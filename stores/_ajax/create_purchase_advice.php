<?php
/** PATH: /public_html/stores/_ajax/create_purchase_advice.php */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/services/NumberingService.php';

require_permission('purchase.advice.manage');
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $raw = file_get_contents('php://input') ?: '';
  $in = $raw ? json_decode($raw, true) : null;
  if (!$in) throw new RuntimeException('No input');

  $warehouse_id = (int)($in['warehouse_id'] ?? 0);
  $item_ids = $in['item_ids'] ?? [];
  $remarks = trim((string)($in['remarks'] ?? ''));

  if ($warehouse_id <= 0) throw new RuntimeException('warehouse_id required');
  if (!is_array($item_ids) || count($item_ids) === 0) throw new RuntimeException('item_ids required');

  // De-dup + sanitize
  $item_ids = array_values(array_unique(array_map(fn($x)=> (int)$x, $item_ids)));
  $idsPlace = implode(',', array_fill(0, count($item_ids), '?'));

 // Pull policy + onhand + uom + adaptive cache
$sql = "
  SELECT
    i.id AS item_id,
    i.uom_id,
    i.material_code, i.name,
    p.min_qty, p.max_qty, p.reorder_point, p.safety_stock, p.policy_mode,
    COALESCE(soh.qty, 0) AS onhand,
    ac.avg_daily_90d, ac.avg_daily_14d, ac.spike_ratio, ac.suggested_reorder_point
  FROM items_stock_policy p
  JOIN items i ON i.id = p.item_id
  LEFT JOIN (
    SELECT item_id, warehouse_id, SUM(qty) qty
    FROM stock_onhand
    WHERE warehouse_id = ?
    GROUP BY item_id, warehouse_id
  ) soh ON soh.item_id = p.item_id
  LEFT JOIN policy_adaptive_cache ac
    ON ac.item_id = p.item_id AND ac.warehouse_id = p.warehouse_id
  WHERE p.warehouse_id = ?
    AND p.item_id IN ($idsPlace)
  ORDER BY i.name
";
$params = array_merge([$warehouse_id, $warehouse_id], $item_ids);
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$ADAPT_SPIKE_MIN_RATIO = 1.30; // if 14d > 1.3x 90d, treat as spike

$lines = [];
foreach ($rows as $r) {
  $onhand = (float)$r['onhand'];
  $min    = (float)$r['min_qty'];
  $max    = (float)$r['max_qty'];
  $rop    = (float)$r['reorder_point'];
  $sfty   = (float)$r['safety_stock'];

  $mode   = (string)$r['policy_mode'];
  $avg90  = (float)($r['avg_daily_90d'] ?? 0);
  $avg14  = (float)($r['avg_daily_14d'] ?? 0);
  $ratio  = (float)($r['spike_ratio'] ?? 0);
  $sugROP = (float)($r['suggested_reorder_point'] ?? 0);

  // base threshold
  $threshold = max($min, $rop, $sfty);

  // adaptive bump if enabled and spike detected
  if ($mode === 'adaptive') {
    $spike = ($ratio >= $ADAPT_SPIKE_MIN_RATIO) || ($avg14 > $avg90 && $avg14 >= 1 && $avg90==0);
    if ($spike && $sugROP > $threshold) {
      $threshold = $sugROP; // raise threshold
    }
  }

  $suggest = ($onhand < $threshold) ? max(0, $max - $onhand) : 0;

  if ($suggest > 0) {
    $lines[] = [
      'item_id' => (int)$r['item_id'],
      'uom_id'  => (int)($r['uom_id'] ?? 0),
      'onhand'  => $onhand,
      'min_qty' => $min,
      'max_qty' => $max,
      'reorder_point' => $rop,
      'safety_stock'  => $sfty,
      'suggested_qty' => $suggest
    ];
  }
}


  if (!$lines) throw new RuntimeException('Nothing to advise (no item below threshold)');

  $user_id = (int)($_SESSION['user_id'] ?? 0);
  $pdo->beginTransaction();

  $advice_no = NumberingService::next($pdo, 'PA');
  $pdo->prepare("INSERT INTO purchase_advice (advice_no, advice_date, warehouse_id, status, remarks, created_by)
                 VALUES (?, CURRENT_DATE, ?, 'draft', ?, ?)")
      ->execute([$advice_no, $warehouse_id, $remarks, $user_id]);
  $advice_id = (int)$pdo->lastInsertId();

  $ins = $pdo->prepare("INSERT INTO purchase_advice_items
    (advice_id, item_id, uom_id, onhand, min_qty, max_qty, reorder_point, safety_stock, suggested_qty, remarks)
    VALUES (?,?,?,?,?,?,?,?,?,?)");

  foreach ($lines as $ln) {
    $ins->execute([
      $advice_id,
      $ln['item_id'],
      $ln['uom_id'] ?: null,
      $ln['onhand'],
      $ln['min_qty'],
      $ln['max_qty'],
      $ln['reorder_point'],
      $ln['safety_stock'],
      $ln['suggested_qty'],
      null
    ]);
  }

  audit_log($pdo, 'purchase_advice', 'create', $advice_id, json_encode(['warehouse_id'=>$warehouse_id,'items'=>$item_ids]));
  $pdo->commit();

  echo json_encode(['ok'=>true, 'advice_id'=>$advice_id, 'advice_no'=>$advice_no, 'lines'=>count($lines)]);
} catch (Throwable $e) {
  if ($pdo?->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
