
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/../includes/coupler/PwoService.php';
require_login(); require_permission('pwo.manage');
$pdo=db();
$svc=new \Coupler\PwoService($pdo);
$msg=null;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create'){
  try{
    $id=$svc->create((int)$_POST['item_id'], (float)$_POST['qty'], $_POST['bom_version_id']?intval($_POST['bom_version_id']):null, $_POST['routing_id']?intval($_POST['routing_id']):null, $_POST['due_date']?:null);
    $msg="PWO #$id created";
  }catch(Throwable $e){ $msg=$e->getMessage(); }
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='build'){
  try{ $d=$svc->buildFromBom((int)$_POST['pwo_id']); $msg="Built materials ".$d['materials']." — ops ".$d['operations']; }catch(Throwable $e){ $msg=$e->getMessage(); }
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='release'){
  try{ $svc->release((int)$_POST['pwo_id']); $msg="Released"; }catch(Throwable $e){ $msg=$e->getMessage(); }
}
$rows=$pdo->query("SELECT * FROM pwo_headers ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>PWO Core</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}label{display:block;margin:6px 0}</style></head><body>
<h1>PWO Core</h1>
<?php if($msg) echo "<p><b>".htmlspecialchars($msg)."</b></p>"; ?>
<form method="post"><input type="hidden" name="action" value="create">
<label>Item ID <input name="item_id" type="number" required></label>
<label>Qty <input name="qty" type="number" step="0.000001" required></label>
<label>BOM Version ID (opt) <input name="bom_version_id" type="number"></label>
<label>Routing ID (opt) <input name="routing_id" type="number"></label>
<label>Due Date (opt) <input name="due_date" type="date"></label>
<button>Create</button></form>

<form method="post" style="margin-top:10px"><input type="hidden" name="action" value="build">
<label>PWO ID <input name="pwo_id" type="number" required></label>
<button>Build from BOM</button></form>

<form method="post" style="margin-top:10px"><input type="hidden" name="action" value="release">
<label>PWO ID <input name="pwo_id" type="number" required></label>
<button>Release</button></form>

<h3>Recent PWOs</h3>
<table><thead><tr><th>ID</th><th>No</th><th>Item</th><th>Qty</th><th>BOM Ver</th><th>Routing</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= $r['id'] ?></td><td><?= htmlspecialchars($r['pwo_no']) ?></td><td><?= $r['item_id'] ?></td><td><?= (float)$r['qty_ordered'] ?></td><td><?= $r['bom_version_id'] ?></td><td><?= $r['routing_id'] ?></td><td><?= htmlspecialchars($r['status']) ?></td></tr><?php endforeach; ?>
</tbody></table>
</body></html>
