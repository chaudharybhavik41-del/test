<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

header('Content-Type: application/json');

function json_out($ok, $payload = []) {
  echo json_encode($ok ? array_merge(['success'=>true], $payload)
                       : array_merge(['success'=>false], $payload));
  exit;
}

function body_json(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

require_login();
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') json_out(false, ['error'=>'Method not allowed']);

$in = body_json();
$action = $in['action'] ?? '';
$po_item_id = (int)($in['po_item_id'] ?? 0);
if ($po_item_id <= 0) json_out(false, ['error'=>'Missing po_item_id']);

switch ($action) {
  case 'list':
    require_permission('purchase.po.view');
    $st = $pdo->prepare("SELECT id, po_id, thickness_mm, density_kg_m3, rate_basis
                         FROM purchase_order_items WHERE id=?");
    $st->execute([$po_item_id]);
    $li = $st->fetch(PDO::FETCH_ASSOC);
    if (!$li) json_out(false, ['error'=>'PO item not found']);
    $po_id = (int)$li['po_id'];

    // cut list
    $rows = $pdo->prepare("SELECT id, width_mm, length_mm, pcs, area_m2, weight_kg
                           FROM purchase_order_item_sizes
                           WHERE po_item_id=?
                           ORDER BY id");
    $rows->execute([$po_item_id]);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

    json_out(true, [
      'rows' => $rows,
      'meta' => [
        'po_id'=>$po_id,
        'rate_basis'=>$li['rate_basis'],
        'thickness_mm'=>$li['thickness_mm'],
        'density_kg_m3'=>$li['density_kg_m3']
      ]
    ]);
    break;

  case 'save':
    require_permission('purchase.po.manage');
    $rows = $in['rows'] ?? [];
    if (!is_array($rows)) $rows = [];

    // get parent line (needs basis, thk, density, pricing)
    $st = $pdo->prepare("SELECT li.*, po.id AS po_id
                         FROM purchase_order_items li
                         JOIN purchase_orders po ON po.id=li.po_id
                         WHERE li.id=?");
    $st->execute([$po_item_id]);
    $li = $st->fetch(PDO::FETCH_ASSOC);
    if (!$li) json_out(false, ['error'=>'PO item not found']);

    $rate_basis   = $li['rate_basis'] ?: 'per_unit';
    $thickness_mm = $li['thickness_mm'];
    $density      = $li['density_kg_m3'];
    $po_id        = (int)$li['po_id'];

    // Replace all sizes
    $pdo->beginTransaction();
    try {
      $pdo->prepare("DELETE FROM purchase_order_item_sizes WHERE po_item_id=?")->execute([$po_item_id]);

      $ins = $pdo->prepare("INSERT INTO purchase_order_item_sizes
        (po_item_id, width_mm, length_mm, pcs, remarks, area_m2, weight_kg)
        VALUES (?,?,?,?,?, ?, ?)");

      $total_area = 0.0;
      $total_weight = 0.0;

      foreach ($rows as $r) {
        $wmm = (float)($r['width_mm'] ?? 0);
        $lmm = (float)($r['length_mm'] ?? 0);
        $pcs = (int)($r['pcs'] ?? 0);
        if ($wmm<=0 || $lmm<=0 || $pcs<=0) continue;

        $area = ($wmm/1000.0)*($lmm/1000.0)*$pcs; // m2
        // Weight needs thickness & density; if missing, keep null and compute 0 this time
        $wt = null;
        if (!is_null($thickness_mm) && !is_null($density) && $thickness_mm>0 && $density>0) {
          $wt = $area * ($thickness_mm/1000.0) * (float)$density; // kg
        }

        $ins->execute([$po_item_id, $wmm, $lmm, $pcs, null, $area, $wt]);

        $total_area += $area;
        if (!is_null($wt)) $total_weight += (float)$wt;
      }

      // Recalculate line & header totals
      _recalc_po_item_and_header($pdo, $po_item_id, $po_id, $rate_basis, $total_area, $total_weight);

      $pdo->commit();
      json_out(true, ['message'=>'Saved']);
    } catch (Throwable $e) {
      $pdo->rollBack();
      json_out(false, ['error'=>$e->getMessage()]);
    }
    break;

  default:
    json_out(false, ['error'=>'Unknown action']);
}

/**
 * Recalculate one PO line and its PO header totals.
 * If $rate_basis/$total_area/$total_weight are not provided, they are derived from DB.
 */
function _recalc_po_item_and_header(PDO $pdo, int $po_item_id, ?int $po_id=null,
  ?string $rate_basis=null, ?float $total_area=null, ?float $total_weight=null): void {

  // Fetch line if needed
  $st = $pdo->prepare("SELECT li.*, po.id AS po_id
                       FROM purchase_order_items li
                       JOIN purchase_orders po ON po.id=li.po_id
                       WHERE li.id=?");
  $st->execute([$po_item_id]);
  $li = $st->fetch(PDO::FETCH_ASSOC);
  if (!$li) return;

  if ($po_id===null) $po_id = (int)$li['po_id'];
  if ($rate_basis===null) $rate_basis = $li['rate_basis'] ?: 'per_unit';

  // Derive area/weight if not provided
  if ($total_area===null || $total_weight===null) {
    $sum = $pdo->prepare("SELECT
        COALESCE(SUM(area_m2),0) AS ta,
        COALESCE(SUM(weight_kg),0) AS tw
      FROM purchase_order_item_sizes WHERE po_item_id=?");
    $sum->execute([$po_item_id]);
    $s = $sum->fetch(PDO::FETCH_ASSOC);
    $total_area   = (float)$s['ta'];
    $total_weight = (float)$s['tw'];
  }

  $qty = (float)$li['qty']; // existing qty (per_unit)
  if ($rate_basis==='per_kg')   $qty = $total_weight;
  if ($rate_basis==='per_m2')   $qty = $total_area;

  // Recompute line totals
  $unit = (float)$li['unit_price'];
  $disc = (float)$li['discount_percent'];
  $taxp = (float)$li['tax_percent'];

  $gross = $qty * $unit;
  $bt = $gross * (1 - $disc/100.0);
  $tax = $bt * ($taxp/100.0);
  $at  = $bt + $tax;

  $up = $pdo->prepare("UPDATE purchase_order_items
                       SET qty=?, line_total_before_tax=?, line_tax=?, line_total_after_tax=?
                       WHERE id=?");
  $up->execute([round($qty,6), round($bt,2), round($tax,2), round($at,2), $po_item_id]);

  // Recompute header totals
  $tot = $pdo->prepare("SELECT
      COALESCE(SUM(line_total_before_tax),0) AS tbt,
      COALESCE(SUM(line_tax),0) AS tt,
      COALESCE(SUM(line_total_after_tax),0) AS tat
    FROM purchase_order_items WHERE po_id=?");
  $tot->execute([$po_id]);
  $t = $tot->fetch(PDO::FETCH_ASSOC);

  $pdo->prepare("UPDATE purchase_orders
                 SET total_before_tax=?, total_tax=?, total_after_tax=?
                 WHERE id=?")
      ->execute([round((float)$t['tbt'],2), round((float)$t['tt'],2), round((float)$t['tat'],2), $po_id]);
}
