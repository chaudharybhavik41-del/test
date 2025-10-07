<?php
/** PATH: /public_html/stores/_jobs/policy_adaptive_refresh.php */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_permission('purchase.advice.manage'); // restrict to admins

$pdo = db();

/**
 * Compute avg daily consumption (ISSUE qtys) from stock_moves:
 * - 90d window: baseline
 * - 14d window: recent
 * Suggested ROP = max(existing ROP, safety_stock, min_qty, ceil(avg14 * 14))
 * (You can tweak factors below.)
 */
$today = new DateTimeImmutable('today');
$from90 = $today->modify('-90 days')->format('Y-m-d');
$from14 = $today->modify('-14 days')->format('Y-m-d');

$sql = "
  SELECT sm.item_id, sm.warehouse_id,
         SUM(CASE WHEN sm.txn_date>=? AND sm.txn_date<=? AND sm.qty<0 THEN -sm.qty ELSE 0 END) AS cons_14,
         SUM(CASE WHEN sm.txn_date>=? AND sm.txn_date<=? AND sm.qty<0 THEN -sm.qty ELSE 0 END) AS cons_90
  FROM stock_moves sm
  GROUP BY sm.item_id, sm.warehouse_id
";
$st = $pdo->prepare($sql);
$st->execute([$from14, $today->format('Y-m-d'), $from90, $today->format('Y-m-d')]);
$cons = $st->fetchAll(PDO::FETCH_ASSOC);

$selPol = $pdo->query("SELECT item_id, warehouse_id, min_qty, max_qty, reorder_point, safety_stock, policy_mode
                       FROM items_stock_policy")->fetchAll(PDO::FETCH_ASSOC);
$polMap = [];
foreach ($selPol as $p) {
  $polMap[$p['item_id'].'-'.$p['warehouse_id']] = $p;
}

$ins = $pdo->prepare("INSERT INTO policy_adaptive_cache
  (item_id, warehouse_id, avg_daily_90d, avg_daily_14d, spike_ratio, suggested_reorder_point, computed_at)
  VALUES (?,?,?,?,?,?,NOW())
  ON DUPLICATE KEY UPDATE
    avg_daily_90d=VALUES(avg_daily_90d),
    avg_daily_14d=VALUES(avg_daily_14d),
    spike_ratio=VALUES(spike_ratio),
    suggested_reorder_point=VALUES(suggested_reorder_point),
    computed_at=VALUES(computed_at)
");

foreach ($cons as $c) {
  $key = $c['item_id'].'-'.$c['warehouse_id'];
  $pol = $polMap[$key] ?? null;
  if (!$pol) continue;

  $avg90 = ((float)$c['cons_90']) / 90.0;
  $avg14 = ((float)$c['cons_14']) / 14.0;
  $ratio = ($avg90>0) ? ($avg14/$avg90) : ( $avg14>0 ? 9.99 : 0.0 );

  // Only matters for adaptive policies
  $min = (float)$pol['min_qty'];
  $rop = (float)$pol['reorder_point'];
  $saf = (float)$pol['safety_stock'];

  // Suggested ROP: protect ~2 weeks of recent consumption, at least existing thresholds
  $suggestROP = max($rop, $saf, $min, ceil($avg14 * 14));

  $ins->execute([(int)$c['item_id'], (int)$c['warehouse_id'], $avg90, $avg14, $ratio, $suggestROP]);
}

echo "OK refreshed at ".date('Y-m-d H:i:s');
