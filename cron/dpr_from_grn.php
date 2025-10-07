
<?php
// CRON-safe example: push yesterday's GRN (plates/structures) to DPR if not already present
declare(strict_types=1);
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/coupler/DprBridge.php';
$pdo = db();
$bridge = new \Coupler\DprBridge($pdo);

// fetch GRN lines of yesterday (adapt query as per your schema)
$st = $pdo->query("SELECT gl.* FROM grn_lines gl WHERE DATE(gl.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $gl){
  $cat = $gl['item_category'] ?? null; if(!$cat) continue;
  $bridge->fromGrn($gl, (string)$cat, $gl['job_id'] ?? null);
}
echo "DPR sync complete: ".count($rows)." GRN lines checked.";
