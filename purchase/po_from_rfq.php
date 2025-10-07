<?php
declare(strict_types=1);
/** PATH: /purchase/po_from_rfq.php */
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/po_seq.php';

require_login();
require_permission('purchase.po.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/** ===================== Helpers ===================== */
function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}
function uom_id_by_code(PDO $pdo, string $code): ?int {
  $st = $pdo->prepare("SELECT id FROM uom WHERE UPPER(code)=UPPER(?) LIMIT 1");
  $st->execute([$code]);
  $id = $st->fetchColumn();
  return $id !== false ? (int)$id : null;
}
function safe_uom_id(PDO $pdo, ?int $fromRfqLine, ?int $fromItem): int {
  if (!empty($fromRfqLine)) return (int)$fromRfqLine;
  if (!empty($fromItem))    return (int)$fromItem;
  $nos = uom_id_by_code($pdo, 'NOS');
  if ($nos) return $nos;
  $any = (int)$pdo->query("SELECT id FROM uom ORDER BY id LIMIT 1")->fetchColumn();
  if ($any > 0) return $any;
  throw new RuntimeException("No UOM available for fallback");
}

/** ===================== Inputs ===================== */
$quote_id    = (int)($_GET['quote_id'] ?? 0);         // Path A (preferred)
$rfq_id_in   = (int)($_GET['rfq_id'] ?? 0);           // Path B (fallback)
$supplier_in = (int)($_GET['supplier_id'] ?? 0);

$has_rfq_quotes = table_exists($pdo,'rfq_quotes') && table_exists($pdo,'rfq_quote_lines');

if ($quote_id<=0 && ($rfq_id_in<=0 || $supplier_in<=0)) {
  http_response_code(400);
  echo "Provide ?quote_id=… OR (fallback) ?rfq_id=…&supplier_id=…";
  exit;
}

/** ===================== Common header fields ===================== */
$po_no        = next_po_no($pdo);
$po_date      = date('Y-m-d');
$currency     = 'INR';
$gst_inclusive= 1;

/** ===================== Path A: from a specific RFQ quote ===================== */
if ($quote_id > 0 && $has_rfq_quotes) {
  try {
    $pdo->beginTransaction();

    // Header info from quote -> rfq -> recipient (supplier)
    $hdr = $pdo->prepare("
      SELECT q.id AS quote_id, r.id AS rfq_id, r.project_id, r.inquiry_id, rr.supplier_id
      FROM rfq_quotes q
      JOIN rfqs r            ON r.id = q.rfq_id
      LEFT JOIN rfq_recipients rr ON rr.id = q.recipient_id
      WHERE q.id = ?
      LIMIT 1
    ");
    $hdr->execute([$quote_id]);
    $H = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$H) throw new RuntimeException("Quote not found");
    $supplier_id = (int)($H['supplier_id'] ?? 0);
    if ($supplier_id<=0) throw new RuntimeException("Quote has no linked supplier");

    // Optional project/location from inquiry
    $project_id  = (int)($H['project_id'] ?? 0) ?: null;
    $location_id = null;
    if (!empty($H['inquiry_id'])) {
      $inq = $pdo->prepare("SELECT project_id, location_id FROM inquiries WHERE id=?");
      $inq->execute([(int)$H['inquiry_id']]);
      if ($tmp=$inq->fetch(PDO::FETCH_ASSOC)) {
        $project_id  = $project_id ?: (int)($tmp['project_id'] ?? 0) ?: null;
        $location_id = (int)($tmp['location_id'] ?? 0) ?: null;
      }
    }

    // Create PO header (totals patched after line loop)
    $pdo->prepare("INSERT INTO purchase_orders
        (po_no, inquiry_id, supplier_id, project_id, location_id, po_date, currency, gst_inclusive,
         total_before_tax, total_tax, total_after_tax, status, created_by)
      VALUES (?,?,?,?,?,?,?,?,0,0,0,'draft',?)")
      ->execute([$po_no, (int)($H['inquiry_id'] ?? 0) ?: null, $supplier_id, $project_id, $location_id,
                 $po_date, $currency, $gst_inclusive, current_user_id()]);
    $po_id = (int)$pdo->lastInsertId();

    // Quote lines + RFQ lines + items (to resolve UOM and item)
    $ql = $pdo->prepare("
      SELECT 
        ql.id AS ql_id, ql.rfq_line_id, ql.qty, ql.weight_kg, ql.rate, ql.rate_basis, ql.tax_pct, ql.delivery_days,
        rl.item_id, rl.qty_uom_id,
        it.uom_id AS item_uom_id
      FROM rfq_quote_lines ql
      JOIN rfq_lines rl ON rl.id = ql.rfq_line_id
      LEFT JOIN items it ON it.id = rl.item_id
      WHERE ql.quote_id = ?
      ORDER BY ql.id
    ");
    $ql->execute([$quote_id]);
    $lines = $ql->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) throw new RuntimeException("No quote lines to import");

    $ins = $pdo->prepare("INSERT INTO purchase_order_items
      (po_id, inquiry_item_id, quote_id, item_id, uom_id, qty, unit_price, discount_percent, tax_percent,
       line_total_before_tax, line_tax, line_total_after_tax, delivery_days, remarks)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $tot_bt=0.0; $tot_tax=0.0; $tot_at=0.0;

    foreach ($lines as $r) {
      // qty (fallback to rfq qty; if null, treat as 0)
      $qty = $r['qty'] !== null ? (float)$r['qty'] : 0.0;

      // unit price: if PER_KG, smear to per-unit for storage in unit_price
      $rate  = (float)$r['rate'];
      $basis = (string)($r['rate_basis'] ?? 'PER_QTY');
      $kg    = $r['weight_kg'] !== null ? (float)$r['weight_kg'] : 0.0;
      $unit_price = ($basis === 'PER_KG')
        ? (($qty>0.0) ? ($rate * $kg / max($qty, 1e-9)) : $rate)
        : $rate;

      $taxp = $r['tax_pct']!==null ? (float)$r['tax_pct'] : 0.0;

      // --- Harden UOM ---
      $uom_id = safe_uom_id(
        $pdo,
        isset($r['qty_uom_id']) ? (int)$r['qty_uom_id'] : null,               // from rfq_lines
        isset($r['item_uom_id'])? (int)$r['item_uom_id'] : null               // from items
      );

      // totals
      $gross = $qty * $unit_price;
      $bt    = $gross;            // no line-level discount in RFQ -> set 0
      $tax   = $bt * ($taxp/100);
      $at    = $bt + $tax;

      $tot_bt += $bt; $tot_tax += $tax; $tot_at += $at;

      $ins->execute([
        $po_id,
        null,                                   // inquiry_item_id not used in pure RFQ path
        $quote_id,
        (int)($r['item_id'] ?? 0) ?: null,
        $uom_id,                                // << guaranteed NOT NULL now
        $qty,
        $unit_price,
        0.0,                                    // discount_percent
        $taxp,
        round($bt,2),
        round($tax,2),
        round($at,2),
        !empty($r['delivery_days']) ? (int)$r['delivery_days'] : null,
        null
      ]);
    }

    $pdo->prepare("UPDATE purchase_orders
                     SET total_before_tax=?, total_tax=?, total_after_tax=?
                   WHERE id=?")
        ->execute([round($tot_bt,2), round($tot_tax,2), round($tot_at,2), $po_id]);

    $pdo->commit();
    header('Location: /purchase/po_form.php?id='.$po_id);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "PO create failed: ".$e->getMessage();
    exit;
  }
}

/** ===================== Path B: fallback via Inquiry locked selections ===================== */
/** This mirrors po_from_selection.php with the same UOM hardening */
try {
  // RFQ -> Inquiry
  $R = $pdo->prepare("SELECT id, inquiry_id, project_id FROM rfqs WHERE id=? LIMIT 1");
  $R->execute([$rfq_id_in]);
  $rfq = $R->fetch(PDO::FETCH_ASSOC);
  if (!$rfq) { http_response_code(404); echo "RFQ not found"; exit; }
  if ((int)$rfq['inquiry_id']<=0) { http_response_code(400); echo "RFQ not linked to an inquiry"; exit; }

  $inq = $pdo->prepare("SELECT id, project_id, location_id FROM inquiries WHERE id=?");
  $inq->execute([(int)$rfq['inquiry_id']]);
  $I = $inq->fetch(PDO::FETCH_ASSOC);
  if (!$I) { http_response_code(404); echo "Inquiry not found"; exit; }

  // Locked selections for this supplier
  $sel=$pdo->prepare("
    SELECT s.inquiry_item_id, s.quote_id, s.unit_price, s.discount_percent, s.tax_percent, s.delivery_days
    FROM inquiry_quote_selections s
    JOIN inquiry_quote_items ql ON ql.quote_id=s.quote_id AND ql.inquiry_item_id=s.inquiry_item_id
    JOIN inquiry_items ii       ON ii.id=s.inquiry_item_id
    WHERE s.inquiry_id=? AND s.supplier_id=?
    GROUP BY s.inquiry_item_id, s.quote_id, s.unit_price, s.discount_percent, s.tax_percent, s.delivery_days
  ");
  $sel->execute([(int)$rfq['inquiry_id'], $supplier_in]);
  $rows=$sel->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows){ http_response_code(400); echo "No locked lines for this supplier on the linked inquiry."; exit; }

  $ii=$pdo->prepare("
    SELECT ii.id, ii.item_id, ii.uom_id AS inquiry_uom_id, ii.qty,
           it.uom_id AS item_uom_id
    FROM inquiry_items ii
    JOIN items it ON it.id = ii.item_id
    WHERE ii.id=?
  ");

  $pdo->beginTransaction();

  $pdo->prepare("INSERT INTO purchase_orders
      (po_no, inquiry_id, supplier_id, project_id, location_id, po_date, currency, gst_inclusive,
       total_before_tax, total_tax, total_after_tax, status, created_by)
    VALUES (?,?,?,?,?,?,?,?,0,0,0,'draft',?)")
    ->execute([$po_no, (int)$rfq['inquiry_id'], $supplier_in, (int)$I['project_id'], (int)($I['location_id']??0)?:null,
               $po_date, $currency, $gst_inclusive, current_user_id()]);
  $po_id = (int)$pdo->lastInsertId();

  $ins = $pdo->prepare("INSERT INTO purchase_order_items
    (po_id, inquiry_item_id, quote_id, item_id, uom_id, qty, unit_price, discount_percent, tax_percent,
     line_total_before_tax, line_tax, line_total_after_tax, delivery_days, remarks)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

  $tot_bt=0.0; $tot_tax=0.0; $tot_at=0.0;

  foreach ($rows as $r) {
    $ii->execute([(int)$r['inquiry_item_id']]);
    $base = $ii->fetch(PDO::FETCH_ASSOC);
    if (!$base) continue;

    $qty   = (float)$base['qty'];
    $unit  = (float)$r['unit_price'];
    $disc  = (float)$r['discount_percent'];
    $taxp  = (float)$r['tax_percent'];

    $gross = $qty * $unit;
    $bt    = $gross * (1 - $disc/100.0);
    $tax   = $bt * ($taxp/100.0);
    $at    = $bt + $tax;

    $tot_bt += $bt; $tot_tax += $tax; $tot_at += $at;

    // Harden UOM like Path A
    $uom_id = safe_uom_id(
      $pdo,
      isset($base['inquiry_uom_id']) ? (int)$base['inquiry_uom_id'] : null,
      isset($base['item_uom_id'])    ? (int)$base['item_uom_id']    : null
    );

    $ins->execute([
      $po_id,
      (int)$r['inquiry_item_id'],
      (int)$r['quote_id'],
      (int)$base['item_id'],
      $uom_id,                       // << guaranteed NOT NULL now
      $qty,
      $unit,
      $disc,
      $taxp,
      round($bt,2),
      round($tax,2),
      round($at,2),
      !empty($r['delivery_days']) ? (int)$r['delivery_days'] : null,
      null
    ]);
  }

  $pdo->prepare("UPDATE purchase_orders
                  SET total_before_tax=?, total_tax=?, total_after_tax=?
                WHERE id=?")
      ->execute([round($tot_bt,2), round($tot_tax,2), round($tot_at,2), $po_id]);

  $pdo->commit();
  header('Location: /purchase/po_form.php?id='.$po_id);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo "PO create failed: ".$e->getMessage();
  exit;
}
