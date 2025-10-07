<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/po_seq.php';

require_login();
require_permission('purchase.po.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/** Helpers */
function uom_id_by_code(PDO $pdo, string $code): ?int {
  $st = $pdo->prepare("SELECT id FROM uom WHERE code=? AND status='active' LIMIT 1");
  $st->execute([strtoupper($code)]);
  $id = $st->fetchColumn();
  return $id !== false ? (int)$id : null;
}

function safe_uom_id(PDO $pdo, ?int $fromInquiry, ?int $fromItem): int {
  // 1) inquiry_items.uom_id
  if (!empty($fromInquiry)) return (int)$fromInquiry;

  // 2) items.uom_id
  if (!empty($fromItem)) return (int)$fromItem;

  // 3) fallback to NOS (present in your seed; id=1)
  $nos = uom_id_by_code($pdo, 'NOS');
  if ($nos) return $nos;

  // last resort: first active UOM
  $any = (int)$pdo->query("SELECT id FROM uom WHERE status='active' ORDER BY id LIMIT 1")->fetchColumn();
  if ($any > 0) return $any;

  // Should never happen in a valid setup
  throw new RuntimeException("No active UOM found for fallback.");
}

/** Input */
$inquiry_id  = (int)($_GET['inquiry_id'] ?? 0);
$supplier_id = (int)($_GET['supplier_id'] ?? 0);
if ($inquiry_id <= 0 || $supplier_id <= 0) { http_response_code(400); echo "Missing inquiry_id / supplier_id"; exit; }

/** Load inquiry basics */
$st = $pdo->prepare("SELECT id, project_id, location_id FROM inquiries WHERE id=?");
$st->execute([$inquiry_id]);
$inq = $st->fetch(PDO::FETCH_ASSOC);
if (!$inq) { http_response_code(404); echo "Inquiry not found"; exit; }

/** Pull locked selections for this supplier */
$sel = $pdo->prepare("
  SELECT s.inquiry_item_id, s.quote_id, s.unit_price, s.discount_percent, s.tax_percent, s.delivery_days
  FROM inquiry_quote_selections s
  JOIN inquiry_quote_items ql ON ql.quote_id = s.quote_id AND ql.inquiry_item_id = s.inquiry_item_id
  JOIN inquiry_items ii ON ii.id = s.inquiry_item_id
  WHERE s.inquiry_id = ? AND s.supplier_id = ?
  GROUP BY s.inquiry_item_id, s.quote_id, s.unit_price, s.discount_percent, s.tax_percent, s.delivery_days
");
$sel->execute([$inquiry_id, $supplier_id]);
$rows = $sel->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { http_response_code(400); echo "No locked lines found for this supplier."; exit; }

/** Resolve item & uom & qty per inquiry line (also join item uom to support fallback) */
$ii = $pdo->prepare("
  SELECT ii.id, ii.item_id, ii.uom_id AS inquiry_uom_id, ii.qty,
         it.uom_id AS item_uom_id
  FROM inquiry_items ii
  JOIN items it ON it.id = ii.item_id
  WHERE ii.id = ?
");

/** Build PO */
$po_no = next_po_no($pdo);
$po_date = date('Y-m-d');
$currency = 'INR';
$gst_inclusive = 1;

$pdo->beginTransaction();
try {
  // Header
  $pdo->prepare("
    INSERT INTO purchase_orders
      (po_no, inquiry_id, supplier_id, project_id, location_id, po_date, currency, gst_inclusive,
       total_before_tax, total_tax, total_after_tax, status, created_by)
    VALUES (?,?,?,?,?,?,?,?,0,0,0,'draft',?)
  ")->execute([$po_no, $inquiry_id, $supplier_id, $inq['project_id'], $inq['location_id'], $po_date, $currency, $gst_inclusive, current_user_id()]);
  $po_id = (int)$pdo->lastInsertId();

  // Lines
  $ins = $pdo->prepare("
    INSERT INTO purchase_order_items
      (po_id, inquiry_item_id, quote_id, item_id, uom_id, qty, unit_price, discount_percent, tax_percent,
       line_total_before_tax, line_tax, line_total_after_tax, delivery_days, remarks)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  $tot_bt = 0.0; $tot_tax = 0.0; $tot_at = 0.0;

  foreach ($rows as $r) {
    $ii->execute([(int)$r['inquiry_item_id']]);
    $base = $ii->fetch(PDO::FETCH_ASSOC);
    if (!$base) continue;

    $qty   = (float)$base['qty'];
    $unit  = (float)$r['unit_price'];
    $disc  = (float)$r['discount_percent'];
    $taxp  = (float)$r['tax_percent'];

    // Compute totals
    $gross = $qty * $unit;
    $bt    = $gross * (1 - $disc/100.0);
    $tax   = $bt * ($taxp/100.0);
    $at    = $bt + $tax;

    $tot_bt += $bt; $tot_tax += $tax; $tot_at += $at;

    // UOM fallback chain
    $uom_id = safe_uom_id($pdo,
      isset($base['inquiry_uom_id']) ? (int)$base['inquiry_uom_id'] : null,
      isset($base['item_uom_id'])    ? (int)$base['item_uom_id']    : null
    );

    $ins->execute([
      $po_id,
      (int)$r['inquiry_item_id'],
      (int)$r['quote_id'],
      (int)$base['item_id'],
      $uom_id,
      $qty,
      $unit,
      $disc,
      $taxp,
      round($bt, 2),
      round($tax, 2),
      round($at, 2),
      !empty($r['delivery_days']) ? (int)$r['delivery_days'] : null,
      null
    ]);
  }

  // Header totals
  $pdo->prepare("
    UPDATE purchase_orders
       SET total_before_tax=?, total_tax=?, total_after_tax=?
     WHERE id=?
  ")->execute([round($tot_bt,2), round($tot_tax,2), round($tot_at,2), $po_id]);

  $pdo->commit();
  header('Location: /purchase/po_form.php?id='.$po_id);
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "PO create failed: ".$e->getMessage();
}
