
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('grn.ownership.edit');
$pdo=db(); $msg=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $id=intval($_POST['grn_item_id']??0);
  $owner=$_POST['owner']??'company';
  $client_id=$_POST['client_id']!==''?intval($_POST['client_id']):null;
  $st=$pdo->prepare("UPDATE grn_items SET owner=?, client_id=? WHERE id=?");
  $st->execute([$owner,$client_id,$id]);
  $msg="Updated GRN item #$id";
}
$rows=$pdo->query("
  SELECT gi.id, g.grn_no, g.grn_date, gi.item_id,
         COALESCE(gi.qty_accepted, gi.qty_received) AS qty,
         gi.unit_price AS rate,
         COALESCE(gi.owner,'company') AS owner, gi.client_id
  FROM grn_items gi
  JOIN grn g ON g.id=gi.grn_id
  ORDER BY g.grn_date DESC, gi.id DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>GRN Ownership Console</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}</style></head><body>
<h1>GRN Ownership Console</h1>
<?php if($msg) echo "<p><b>".htmlspecialchars($msg)."</b></p>"; ?>
<table><thead><tr><th>ID</th><th>GRN</th><th>Date</th><th>Item</th><th>Qty</th><th>Rate</th><th>Owner</th><th>Client</th><th>Update</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr>
<td><?= $r['id'] ?></td><td><?= htmlspecialchars($r['grn_no']) ?></td><td><?= htmlspecialchars($r['grn_date']) ?></td>
<td><?= $r['item_id'] ?></td><td><?= (float)$r['qty'] ?></td><td><?= (float)$r['rate'] ?></td>
<td>
  <form method="post" style="display:flex;gap:6px;align-items:center">
    <input type="hidden" name="grn_item_id" value="<?= $r['id'] ?>">
    <select name="owner">
      <option value="company" <?= ($r['owner']=='company'?'selected':'') ?>>Company</option>
      <option value="client"  <?= ($r['owner']=='client'?'selected':'')  ?>>Client</option>
    </select>
</td>
<td><input name="client_id" type="number" value="<?= htmlspecialchars((string)$r['client_id']) ?>"></td>
<td><button>Save</button></td></form>
</tr>
<?php endforeach; ?>
</tbody></table>
</body></html>
