
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login(); require_permission('dpr.view');
$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
  $st=$pdo->prepare("INSERT INTO dpr_activity_map (item_category,activity,unit) VALUES (?,?,?) ON DUPLICATE KEY UPDATE activity=VALUES(activity), unit=VALUES(unit)");
  $st->execute([$_POST['item_category'], $_POST['activity'], $_POST['unit']]);
  header('Location: ./dpr_console.php'); exit;
}
$maps=$pdo->query("SELECT * FROM dpr_activity_map ORDER BY item_category")->fetchAll(PDO::FETCH_ASSOC);
$latest=$pdo->query("SELECT * FROM dpr_entries ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>DPR Bridge</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}label{display:block;margin:6px 0}</style></head><body>
<h1>DPR Bridge</h1>
<h3>Map Item Category → DPR Activity</h3>
<form method="post">
<label>Item Category <input name="item_category" required placeholder="plate"></label>
<label>Activity <input name="activity" required placeholder="DPR Plate Received"></label>
<label>Unit <input name="unit" value="kg"></label>
<button>Save Mapping</button>
</form>
<hr>
<h3>Latest DPR Entries</h3>
<table><thead><tr><?php if($latest){ foreach(array_keys($latest[0]) as $c) echo "<th>".htmlspecialchars($c)."</th>"; } ?></tr></thead><tbody>
<?php foreach($latest as $r){ echo "<tr>"; foreach($r as $v) echo "<td>".htmlspecialchars((string)$v)."</td>"; echo "</tr>"; } ?>
</tbody></table>
<hr>
<h3>Current Mappings</h3>
<table><thead><tr><th>Item Category</th><th>Activity</th><th>Unit</th></tr></thead><tbody>
<?php foreach($maps as $m): ?><tr><td><?= htmlspecialchars($m['item_category']) ?></td><td><?= htmlspecialchars($m['activity']) ?></td><td><?= htmlspecialchars($m['unit']) ?></td></tr><?php endforeach; ?>
</tbody></table>
</body></html>
