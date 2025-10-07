
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/../includes/coupler/WorkCenterService.php';
require_once __DIR__.'/../includes/coupler/RoutingService.php';
require_login(); require_permission('routing.manage');
$pdo=db();
$wcsvc=new \Coupler\WorkCenterService($pdo);
$rsvc=new \Coupler\RoutingService($pdo);
$msg=null;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='wc'){
  $wcsvc->upsert($_POST['id']?intval($_POST['id']):null,(string)$_POST['code'],(string)$_POST['name'], $_POST['rate']!==''?(float)$_POST['rate']:null, $_POST['calendar']?:null, isset($_POST['active']));
  $msg="Work Center saved";
}
$wcs=$wcsvc->list();
?><!doctype html><html><head><meta charset="utf-8"><title>Workcenters & Routing</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}label{display:block;margin:6px 0}</style></head><body>
<h1>Workcenters</h1><?php if($msg) echo "<p><b>".htmlspecialchars($msg)."</b></p>"; ?>
<form method="post">
  <input type="hidden" name="action" value="wc">
  <label>Code <input name="code" required></label>
  <label>Name <input name="name" required></label>
  <label>Rate/hr <input name="rate" type="number" step="0.0001"></label>
  <label>Calendar JSON <input name="calendar"></label>
  <label><input type="checkbox" name="active" checked> Active</label>
  <button>Save</button>
</form>
<table><thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Rate/hr</th><th>Active</th></tr></thead><tbody>
<?php foreach($wcs as $r): ?><tr><td><?= $r['id'] ?></td><td><?= htmlspecialchars($r['wc_code']) ?></td><td><?= htmlspecialchars($r['wc_name']) ?></td><td><?= (float)$r['cost_rate_per_hour'] ?></td><td><?= $r['is_active']?'Y':'N' ?></td></tr><?php endforeach; ?>
</tbody></table>
<hr>
<h2>Routing Quick API (use Postman)</h2>
<p>POST /routing/_ajax/create.php → {parent_item_id, routing_code, bom_version_id?, is_primary?}</p>
<p>POST /routing/_ajax/add_op.php → {routing_id, op_seq, op_code, wc_id, std_setup_min?, std_run_min_per_unit?, overlap_pct?}</p>
<p>GET  /routing/_ajax/get.php?routing_id=...</p>
</body></html>
