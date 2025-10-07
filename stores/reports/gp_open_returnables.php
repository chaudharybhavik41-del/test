<?php
/**
 * Open Returnables Report
 * Requires: stores.gatepass.manage (or similar view permission)
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_permission('stores.gatepass.manage');

$pdo = db();

$search = trim($_GET['search'] ?? '');
$only_overdue = (int)($_GET['only_overdue'] ?? 0);
$date_from   = trim($_GET['date_from'] ?? '');
$date_to     = trim($_GET['date_to'] ?? '');
$warehouse_id= (int)($_GET['warehouse_id'] ?? 0);
$party_id    = (int)($_GET['party_id'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = min(200, max(10, (int)($_GET['per_page'] ?? 50)));

$where = ["g.returnable = 1"];
$params = [];

if ($date_from !== '') { $where[] = "g.created_at >= :df"; $params[':df'] = $date_from . " 00:00:00"; }
if ($date_to   !== '') { $where[] = "g.created_at <= :dt"; $params[':dt'] = $date_to . " 23:59:59"; }
if ($warehouse_id>0)   { $where[] = "g.warehouse_id = :wh"; $params[':wh'] = $warehouse_id; }
if ($party_id>0)       { $where[] = "g.party_id = :py"; $params[':py'] = $party_id; }
if ($search !== '') {
  $where[] = "(i.code LIKE :q OR i.name LIKE :q OR g.gp_no LIKE :q)";
  $params[':q'] = '%' . $search . '%';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Count
$sql_count = "SELECT COUNT(*)
              FROM gatepasses g
              JOIN gatepass_items gi ON gi.gp_id = g.id
              JOIN items i ON i.id = gi.item_id
              LEFT JOIN parties p ON p.id = g.party_id
              $where_sql";
$st = $pdo->prepare($sql_count);
$st->execute($params);
$total = (int)$st->fetchColumn();

$offset = ($page - 1) * $per_page;

$sql = "SELECT g.gp_no, g.created_at as gp_date, g.expected_return_date, g.warehouse_id, g.party_id,
               p.name AS party_name,
               gi.id as gp_line_id, gi.line_no, gi.item_id, i.code AS item_code, i.name AS item_name,
               gi.qty, gi.returned_qty,
               (gi.qty - gi.returned_qty) AS balance_qty,
               DATEDIFF(CURDATE(), g.expected_return_date) AS overdue_days
        FROM gatepasses g
        JOIN gatepass_items gi ON gi.gp_id = g.id
        JOIN items i ON i.id = gi.item_id
        LEFT JOIN parties p ON p.id = g.party_id
        $where_sql
        HAVING balance_qty > 0 " . ($only_overdue ? " AND overdue_days > 0 " : "") . "
        ORDER BY g.created_at DESC, g.gp_no DESC, gi.line_no ASC
        LIMIT :lim OFFSET :off";

$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':lim', $per_page, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Dropdowns
$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$parties = $pdo->query("SELECT id, name FROM parties ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Open Returnables</title>
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
    .badge{display:inline-block;padding:2px 6px;border-radius:4px;border:1px solid #ddd;background:#f8f8f8;font-size:12px}
    .btn{display:inline-block;padding:8px 12px;border:1px solid #ccc;border-radius:6px;background:#fff;cursor:pointer;text-decoration:none;color:#111}
    .btn.primary{border-color:#2573ef;background:#2f7df4;color:#fff}
  </style>
</head>
<body>

<div class="card">
  <h2>Open Returnables</h2>
  <form method="get">
    <div class="row">
      <div class="field"><label>From</label><input type="date" name="date_from" value="<?=htmlspecialchars($date_from)?>"></div>
      <div class="field"><label>To</label><input type="date" name="date_to" value="<?=htmlspecialchars($date_to)?>"></div>
      <div class="field"><label>Warehouse</label>
        <select name="warehouse_id"><option value="0">-- Any --</option>
          <?php foreach($warehouses as $wh): ?><option value="<?=$wh['id']?>" <?=$warehouse_id==$wh['id']?'selected':''?>><?=htmlspecialchars($wh['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Party</label>
        <select name="party_id"><option value="0">-- Any --</option>
          <?php foreach($parties as $p): ?><option value="<?=$p['id']?>" <?=$party_id==$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label>Search</label><input type="text" name="search" value="<?=htmlspecialchars($search)?>" placeholder="GP No or Item"></div>
      <div class="field"><label>&nbsp;</label><label><input type="checkbox" name="only_overdue" value="1" <?=$only_overdue?'checked':''?>> Only overdue</label></div>
      <div class="field"><label>&nbsp;</label>
        <button class="btn primary" type="submit">Apply</button> <a class="btn" href="?">Reset</a>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between;">
    <div><span class="badge">Rows: <?=count($rows)?></span></div>
  </div>

  <div style="overflow:auto;margin-top:8px;">
    <table>
      <thead>
        <tr>
          <th>GP No</th><th>Date</th><th>Party</th><th>Item</th><th class="right">Issued</th><th class="right">Returned</th><th class="right">Balance</th><th>Expected Return</th><th>Overdue</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?=htmlspecialchars($r['gp_no'])?></td>
            <td class="muted"><?=htmlspecialchars($r['gp_date'])?></td>
            <td><?=htmlspecialchars($r['party_name'] ?: '-')?></td>
            <td><?=htmlspecialchars($r['item_code'] . ' — ' . $r['item_name'])?></td>
            <td class="right"><?=number_format((float)$r['qty'],3)?></td>
            <td class="right"><?=number_format((float)$r['returned_qty'],3)?></td>
            <td class="right"><?=number_format((float)$r['balance_qty'],3)?></td>
            <td><?=htmlspecialchars((string)$r['expected_return_date'])?></td>
            <td><?=($r['overdue_days']>0) ? ($r['overdue_days'].' days') : '-'?></td>
          </tr>
        <?php endforeach; if (!count($rows)): ?>
          <tr><td colspan="9" class="muted">No open returnables.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</body>
</html>
