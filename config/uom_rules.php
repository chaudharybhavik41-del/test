
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login(); require_permission('config.uom_rules');
$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
  $in = $_POST;
  if(isset($in['create_rule'])){
    $st=$pdo->prepare("INSERT INTO uom_rules (name,method,params_json) VALUES (?,?,?)");
    $st->execute([$in['name'], $in['method'], $in['params_json'] ?: '{}']);
  }
  if(isset($in['map_cat'])){
    $st=$pdo->prepare("INSERT INTO item_category_rules (item_category,rule_id) VALUES (?,?) ON DUPLICATE KEY UPDATE rule_id=VALUES(rule_id)");
    $st->execute([$in['item_category'], (int)$in['rule_id']]);
  }
  header('Location: ./uom_rules.php'); exit;
}
$rules=$pdo->query("SELECT * FROM uom_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>UOM Rules</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}</style></head><body>
<h1>UOM Rules</h1>
<form method="post">
  <h3>Create Rule</h3>
  <label>Name <input name="name" required></label>
  <label>Method
    <select name="method">
      <option>by_weight</option><option>by_pcs</option><option>by_area</option><option>by_volume</option><option>custom_multiplier</option>
    </select>
  </label>
  <label>Params JSON <textarea name="params_json" rows="3" cols="80" placeholder='{"factors":["weight_kg"],"scale":1.0}'></textarea></label>
  <button name="create_rule" value="1">Create</button>
</form>
<hr>
<form method="post">
  <h3>Map Item Category → Rule</h3>
  <label>Item Category <input name="item_category" required placeholder="plate, structure, consumable, ..."></label>
  <label>Rule
    <select name="rule_id">
      <?php foreach($rules as $r): ?><option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name']) ?> (#<?= (int)$r['id'] ?>)</option><?php endforeach; ?>
    </select>
  </label>
  <button name="map_cat" value="1">Save Mapping</button>
</form>
<hr>
<h3>Existing Rules</h3>
<table><thead><tr><th>ID</th><th>Name</th><th>Method</th><th>Params</th><th>Status</th></tr></thead><tbody>
<?php foreach($rules as $r): ?><tr>
<td><?= (int)$r['id'] ?></td><td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['method']) ?></td>
<td><pre style="white-space:pre-wrap"><?= htmlspecialchars($r['params_json']) ?></pre></td>
<td><?= htmlspecialchars($r['status']) ?></td>
</tr><?php endforeach; ?></tbody></table>
</body></html>
