
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('config.masterdata');
$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(isset($_POST['add_cat'])){
    $st=$pdo->prepare("INSERT INTO item_categories (code,name,density_kg_per_m3,notes) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), density_kg_per_m3=VALUES(density_kg_per_m3), notes=VALUES(notes)");
    $st->execute([$_POST['code'],$_POST['name'],$_POST['density']?:null,$_POST['notes']?:null]);
  }
  if(isset($_POST['add_sec'])){
    $st=$pdo->prepare("INSERT INTO section_density (section_code,kg_per_m) VALUES (?,?) ON DUPLICATE KEY UPDATE kg_per_m=VALUES(kg_per_m)");
    $st->execute([$_POST['section_code'],$_POST['kg_per_m']]);
  }
  header('Location: ./masterdata_console.php'); exit;
}
$cats=$pdo->query("SELECT * FROM item_categories ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$secs=$pdo->query("SELECT * FROM section_density ORDER BY section_code")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>Master Data Tidy</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}</style></head><body>
<h1>Master Data Tidy</h1>
<h3>Item Categories</h3>
<form method="post">
  <input type="hidden" name="add_cat" value="1">
  <label>Code <input name="code" required></label>
  <label>Name <input name="name" required></label>
  <label>Density (kg/m³) <input name="density" type="number" step="0.000001"></label>
  <label>Notes <input name="notes"></label>
  <button>Save</button>
</form>
<table><thead><tr><th>Code</th><th>Name</th><th>Density</th><th>Notes</th></tr></thead><tbody>
<?php foreach($cats as $c): ?><tr><td><?= htmlspecialchars($c['code']) ?></td><td><?= htmlspecialchars($c['name']) ?></td><td><?= htmlspecialchars((string)$c['density_kg_per_m3']) ?></td><td><?= htmlspecialchars((string)$c['notes']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<hr>
<h3>Section Density (kg/m)</h3>
<form method="post">
  <input type="hidden" name="add_sec" value="1">
  <label>Section Code <input name="section_code" required placeholder="ISMB100"></label>
  <label>kg/m <input name="kg_per_m" type="number" step="0.000001" required></label>
  <button>Save</button>
</form>
<table><thead><tr><th>Section</th><th>kg/m</th></tr></thead><tbody>
<?php foreach($secs as $s): ?><tr><td><?= htmlspecialchars($s['section_code']) ?></td><td><?= htmlspecialchars((string)$s['kg_per_m']) ?></td></tr><?php endforeach; ?>
</tbody></table>
</body></html>
