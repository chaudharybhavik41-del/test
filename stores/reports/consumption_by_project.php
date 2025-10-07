<?php
/**
 * Consumption by Project
 * Uses stock_ledger to sum ISS + GP (non-returnable) as consumption
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_permission('stores.ledger.view');

$pdo = db();

$date_from   = trim($_GET['date_from'] ?? '');
$date_to     = trim($_GET['date_to'] ?? '');
$project_id  = (int)($_GET['project_id'] ?? 0);
$item_id     = (int)($_GET['item_id'] ?? 0);
$warehouse_id= (int)($_GET['warehouse_id'] ?? 0);

$params = [];
$where = [];

if ($date_from !== '') { $where[] = "l.txn_date >= :df"; $params[':df'] = $date_from . " 00:00:00"; }
if ($date_to   !== '') { $where[] = "l.txn_date <= :dt"; $params[':dt'] = $date_to . " 23:59:59"; }
if ($project_id> 0)    { $where[] = "l.project_id = :prj"; $params[':prj'] = $project_id; }
if ($item_id   > 0)    { $where[] = "l.item_id = :it"; $params[':it'] = $item_id; }
if ($warehouse_id>0)   { $where[] = "l.warehouse_id = :wh"; $params[':wh'] = $warehouse_id; }

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT p.id AS project_id, COALESCE(p.name,'(No Project)') AS project_name,
               i.id AS item_id, i.code AS item_code, i.name AS item_name,
               w.id AS warehouse_id, w.name AS warehouse_name,
               SUM(CASE WHEN l.txn_type IN ('ISS','GP') THEN -l.qty ELSE 0 END) AS qty_out,
               SUM(CASE WHEN l.txn_type IN ('ISS','GP') THEN -l.amount ELSE 0 END) AS amount_out
        FROM stock_ledger l
        JOIN items i ON i.id = l.item_id
        JOIN warehouses w ON w.id = l.warehouse_id
        LEFT JOIN projects p ON p.id = l.project_id
        $where_sql
        GROUP BY p.id, i.id, w.id
        HAVING ABS(qty_out) > 0.0001
        ORDER BY p.name, i.code, w.name";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// dropdowns
$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query("SELECT id, CONCAT(code,' - ',name) label FROM items ORDER BY code LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Consumption by Project</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px}
    .card{border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px}
    .row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
    .field{display:flex;flex-direction:column;min-width:180px}
    table{width:100%;border-collapse:collapse;font-size:14px}
    th,td{border-bottom:1px solid #eee;padding:8px;text-align:left}
    th{background:#fafafa}
    .right{text-align:right}
    .muted{color:#666}
    .btn{display:inline-block;padding:8px 12px;border:1px solid #ccc;border-radius:6px;background:#fff;cursor:pointer;text-decoration:none;color:#111}
    .btn.primary{border-color:#2573ef;background:#2f7df4;color:#fff}
  </style>
</head>
<body>

<div class="card">
  <h2>Consumption by Project</h2>
  <form method="get">
    <div class="row">
      <div class="field"><label>From</label><input type="date" name="date_from" value="<?=htmlspecialchars($date_from)?>"></div>
      <div class="field"><label>To</label><input type="date" name="date_to" value="<?=htmlspecialchars($date_to)?>"></div>
      <div class="field"><label>Project</label>
        <select name="project_id"><option value="0">-- Any --</option>
          <?php foreach($projects as $pr): ?><option value="<?=$pr['id']?>" <?=$project_id==$pr['id']?'selected':''?>><?=htmlspecialchars($pr['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Item</label>
        <select name="item_id"><option value="0">-- Any --</option>
          <?php foreach($items as $it): ?><option value="<?=$it['id']?>" <?=$item_id==$it['id']?'selected':''?>><?=htmlspecialchars($it['label'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Warehouse</label>
        <select name="warehouse_id"><option value="0">-- Any --</option>
          <?php foreach($warehouses as $wh): ?><option value="<?=$wh['id']?>" <?=$warehouse_id==$wh['id']?'selected':''?>><?=htmlspecialchars($wh['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>&nbsp;</label>
        <button class="btn primary" type="submit">Apply</button> <a class="btn" href="?">Reset</a>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between;">
    <div><span class="muted">Rows: <?=count($rows)?></span></div>
  </div>

  <div style="overflow:auto;margin-top:8px;">
    <table>
      <thead>
        <tr>
          <th>Project</th><th>Item</th><th>Warehouse</th><th class="right">Qty Consumed</th><th class="right">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=htmlspecialchars($r['project_name'])?></td>
            <td><?=htmlspecialchars($r['item_code'] . ' — ' . $r['item_name'])?></td>
            <td><?=htmlspecialchars($r['warehouse_name'])?></td>
            <td class="right"><?=number_format((float)$r['qty_out'],3)?></td>
            <td class="right"><?=number_format((float)$r['amount_out'],2)?></td>
          </tr>
        <?php endforeach; if (!count($rows)): ?>
          <tr><td colspan="5" class="muted">No data for selected filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</body>
</html>
