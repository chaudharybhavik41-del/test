<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/NumberingService.php';
require_once __DIR__ . '/../includes/Availability.php';
require_permission('stores.cycle.manage');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_token($_POST['csrf_token'] ?? '');
    $warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
    $bin_id       = isset($_POST['bin_id']) ? (int)$_POST['bin_id'] : null;
    $project_id   = isset($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    if ($warehouse_id <= 0) { die('Warehouse is required'); }
    $cc_no = NumberingService::next($pdo, 'CC');
    $pdo->prepare("INSERT INTO cycle_counts (cc_no, warehouse_id, bin_id, project_id, status, created_by, created_at)
                   VALUES (:cc_no, :wh, :bin, :prj, 'DRAFT', :uid, NOW(6))")
        ->execute([':cc_no'=>$cc_no, ':wh'=>$warehouse_id, ':bin'=>$bin_id, ':prj'=>$project_id, ':uid'=>(int)($_SESSION['user_id'] ?? 0)]);
    $cc_id = (int)$pdo->lastInsertId();
    $items = $pdo->prepare("SELECT DISTINCT m.item_id FROM stock_moves m WHERE m.warehouse_id = :w AND m.txn_date >= CURDATE() - INTERVAL 180 DAY");
    $items->execute([':w'=>$warehouse_id]);
    $item_ids = $items->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare("INSERT INTO cycle_count_items (cycle_id, line_no, item_id, uom_id, expected_qty, counted_qty, variance_qty)
                          VALUES (:cid, :ln, :item, :uom, :exp, 0, 0)");
    $line_no = 0;
    foreach ($item_ids as $item_id) {
        $uom_id = $pdo->query("SELECT uom_id FROM items WHERE id = ".(int)$item_id)->fetchColumn();
        if (!$uom_id) $uom_id = null;
        $exp = Availability::onhand($pdo, (int)$item_id, $warehouse_id);
        if (abs($exp) < 0.0005) continue;
        $line_no++;
        $ins->execute([':cid'=>$cc_id, ':ln'=>$line_no, ':item'=>(int)$item_id, ':uom'=>$uom_id, ':exp'=>(float)$exp]);
    }
    header("Location: cycle_count_enter.php?id=".$cc_id); exit;
}
$csrf = csrf_token();
$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$bins  = $pdo->query("SELECT id, name FROM bins ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>Cycle Count - Generate</title><meta name="viewport" content="width=device-width, initial-scale=1">
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px}.card{border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px;max-width:920px}.row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}.field{display:flex;flex-direction:column;min-width:220px}select,input{padding:6px}.btn{display:inline-block;padding:8px 12px;border:1px solid #ccc;border-radius:6px;background:#fff;cursor:pointer;text-decoration:none;color:#111}.btn.primary{border-color:#2573ef;background:#2f7df4;color:#fff}</style></head><body>
<div class="card"><h2>Cycle Count — Generate Sheet</h2><form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><div class="row">
<div class="field"><label>Warehouse</label><select name="warehouse_id" required><option value="">-- Select --</option><?php foreach($warehouses as $w): ?><option value="<?=$w['id']?>"><?=htmlspecialchars($w['name'])?></option><?php endforeach; ?></select></div>
<div class="field"><label>Bin (optional)</label><select name="bin_id"><option value="">-- Any --</option><?php foreach($bins as $b): ?><option value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option><?php endforeach; ?></select></div>
<div class="field"><label>Project (optional)</label><select name="project_id"><option value="">-- None --</option><?php foreach($projects as $p): ?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option><?php endforeach; ?></select></div>
<div class="field" style="flex:1;"><label>&nbsp;</label><button class="btn primary" type="submit">Generate Sheet</button></div>
</div></form></div></body></html>
