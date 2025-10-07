<?php
/** PATH: /public_html/purchase/trace_plate.php
 * PURPOSE: Full lineage — original lot → remnants → parts → scrap
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Resolve the lot */
$lot_id  = isset($_GET['lot_id']) ? (int)$_GET['lot_id'] : 0;
$plate_no= trim($_GET['plate_no'] ?? '');
$heat_no = trim($_GET['heat_no'] ?? '');

if ($lot_id<=0 && ($plate_no!=='' || $heat_no!=='')) {
  $st = $pdo->prepare("SELECT id FROM stock_lots
                       WHERE (plate_no=? OR ?='') AND (heat_no=? OR ?='')
                       ORDER BY id DESC LIMIT 1");
  $st->execute([$plate_no, $plate_no, $heat_no, $heat_no]);
  $lot_id = (int)$st->fetchColumn();
}

if ($lot_id<=0) {
  http_response_code(400);
  echo "Provide ?lot_id= or ?plate_no=&heat_no=";
  exit;
}

/* Load chosen lot + compute chain root */
$st = $pdo->prepare("SELECT * FROM stock_lots WHERE id=?"); $st->execute([$lot_id]);
$lot = $st->fetch();
if (!$lot) { echo "Lot not found."; exit; }

$root_id = (int)($lot['chain_root_lot_id'] ?: $lot['id']);

/* Get full chain (all lots with this root, including root) */
$chain = $pdo->prepare("SELECT * FROM stock_lots
                        WHERE (chain_root_lot_id = ? OR id = ?)
                        ORDER BY id ASC");
$chain->execute([$root_id, $root_id]);
$lots = $chain->fetchAll();

/* Collect their IDs for trace rows */
$ids = array_map(fn($r)=>(int)$r['id'], $lots);
$trByLot = [];
if ($ids) {
  $place = implode(',', array_fill(0, count($ids), '?'));
  $tr = $pdo->prepare("SELECT t.*, p.plan_no
                       FROM part_traceability t
                       LEFT JOIN plate_plans p ON p.id = t.plan_id
                       WHERE t.lot_id IN ($place)
                       ORDER BY t.recorded_at ASC, t.id ASC");
  $tr->execute($ids);
  foreach ($tr->fetchAll() as $t) {
    $trByLot[(int)$t['lot_id']][] = $t;
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Trace Plate</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:18px}
    .wrap{max-width:1100px;margin:0 auto}
    .card{border:1px solid #eee;border-radius:12px;padding:16px;margin-bottom:14px}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #ddd;font-size:12px;margin-left:6px}
    .muted{color:#666}
    ul{margin:6px 0 0 18px}
  </style>
</head>
<body>
<div class="wrap">
  <h3>Trace Plate — <?= h(($lot['plate_no'] ?: '—').' / '.($lot['heat_no'] ?: '—')) ?></h3>

  <div class="card">
    <div><b>Grade:</b> <?= h($lot['item_name'] ?: '') ?></div>
    <div><b>Root Lot:</b> <?= (int)$root_id ?></div>
    <div class="muted">Use Remnant List to scrap/trace other lots.</div>
  </div>

  <?php foreach ($lots as $L): ?>
    <div class="card">
      <div><b>Lot:</b> <?= h($L['internal_lot_no'] ?: ('LOT-'.$L['id'])) ?>
        <span class="pill"><?= h($L['status']) ?></span>
        <?php if ($L['status']==='scrapped' && !empty($L['scrap_at'])): ?>
          <span class="pill">Scrapped @ <?= h($L['scrap_at']) ?></span>
        <?php endif; ?>
      </div>
      <div><b>Plate / Heat:</b> <?= h(($L['plate_no'] ?: '—').' / '.($L['heat_no'] ?: '—')) ?></div>
      <div><b>Thickness:</b> <?= (float)$L['thickness_mm'] ?>
        &nbsp; <b>Avail area:</b> <?= number_format((int)$L['available_area_mm2']) ?> mm²
        &nbsp; <b>Origin plan:</b> <?= h($L['origin_plan_no'] ?: '') ?>
      </div>
      <?php if (!empty($trByLot[(int)$L['id']])): ?>
        <div style="margin-top:8px"><b>Parts produced from this lot:</b>
          <ul>
            <?php foreach ($trByLot[(int)$L['id']] as $t): ?>
              <li>
                Plan <?= h($t['plan_no'] ?: (string)$t['plan_id']) ?> ·
                PlateID <?= (int)$t['plate_id'] ?> ·
                Part <?= (int)$t['part_id'] ?> ·
                Alloc <?= (int)$t['allocation_id'] ?> ·
                <?= h($t['process_code']) ?> ·
                <?= h($t['recorded_at']) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>