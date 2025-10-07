<?php
/**
 * Stock Ledger Report (List + Filters)
 * Requirements:
 *  - Permission: stores.ledger.view
 *  - Tables: stock_ledger (new), items, warehouses, projects (optional)
 *  - Includes: db.php, rbac.php, helpers.php
 *
 * Features:
 *  - Filters: date_from, date_to, item_id, warehouse_id, project_id, txn_type, search (item code/name)
 *  - Pagination
 *  - Totals (qty, amount)
 *  - CSV export (optional via ?export=csv)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_permission('stores.ledger.view');

$pdo = db();

// --- Inputs & defaults ---
$date_from   = trim($_GET['date_from'] ?? '');
$date_to     = trim($_GET['date_to'] ?? '');
$item_id     = (int)($_GET['item_id'] ?? 0);
$warehouse_id= (int)($_GET['warehouse_id'] ?? 0);
$project_id  = (int)($_GET['project_id'] ?? 0);
$txn_type    = trim($_GET['txn_type'] ?? '');
$search      = trim($_GET['search'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = min(200, max(10, (int)($_GET['per_page'] ?? 50)));
$export_csv  = (isset($_GET['export']) && $_GET['export'] === 'csv');

$params = [];
$where = [];

if ($date_from !== '') { $where[] = "l.txn_date >= :df"; $params[':df'] = $date_from . " 00:00:00"; }
if ($date_to   !== '') { $where[] = "l.txn_date <= :dt"; $params[':dt'] = $date_to . " 23:59:59"; }
if ($item_id   >   0)  { $where[] = "l.item_id = :item"; $params[':item'] = $item_id; }
if ($warehouse_id> 0)  { $where[] = "l.warehouse_id = :wh"; $params[':wh'] = $warehouse_id; }
if ($project_id >  0)  { $where[] = "l.project_id = :prj"; $params[':prj'] = $project_id; }
if ($txn_type  !== '') { $where[] = "l.txn_type = :tt"; $params[':tt'] = $txn_type; }
if ($search    !== '') {
    $where[] = "(i.code LIKE :q OR i.name LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Count ---
$sql_count = "SELECT COUNT(*) AS c FROM stock_ledger l
              JOIN items i ON i.id = l.item_id
              JOIN warehouses w ON w.id = l.warehouse_id
              $where_sql";
$st = $pdo->prepare($sql_count);
$st->execute($params);
$total = (int)$st->fetchColumn();

$offset = ($page - 1) * $per_page;

// --- Query ---
$sql = "SELECT l.*, i.code AS item_code, i.name AS item_name, w.name AS warehouse_name,
               COALESCE(p.name, '') AS project_name
        FROM stock_ledger l
        JOIN items i ON i.id = l.item_id
        JOIN warehouses w ON w.id = l.warehouse_id
        LEFT JOIN projects p ON p.id = l.project_id
        $where_sql
        ORDER BY l.txn_date DESC, l.id DESC
        LIMIT :lim OFFSET :off";

$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $st->bindValue($k, $v); }
$st->bindValue(':lim', $per_page, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Totals
$sql_tot = "SELECT COALESCE(SUM(l.qty),0) qty_total, COALESCE(SUM(l.amount),0) amt_total
            FROM stock_ledger l
            JOIN items i ON i.id = l.item_id
            JOIN warehouses w ON w.id = l.warehouse_id
            $where_sql";
$stt = $pdo->prepare($sql_tot);
$stt->execute($params);
$tot = $stt->fetch(PDO::FETCH_ASSOC);

// CSV export
if ($export_csv) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename=stock_ledger_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Type','Txn No','Item Code','Item Name','Warehouse','Project','Qty','Rate','Amount','UoM']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['txn_date'], $r['txn_type'], $r['txn_no'], $r['item_code'], $r['item_name'],
            $r['warehouse_name'], $r['project_name'], $r['qty'], $r['rate'], $r['amount'], $r['uom_id']
        ]);
    }
    fclose($out);
    exit;
}

// Fetch dropdown data (simple)
$items = $pdo->query("SELECT id, CONCAT(code,' - ',name) label FROM items ORDER BY code LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// --- Render ---
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Stock Ledger</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;margin:16px;}
    .card{border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px;}
    .row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
    .field{display:flex;flex-direction:column;min-width:200px;}
    table{width:100%;border-collapse:collapse;font-size:14px;}
    th,td{border-bottom:1px solid #eee;padding:8px;text-align:left;}
    th{background:#fafafa;}
    .right{text-align:right;}
    .muted{color:#666;}
    .btn{display:inline-block;padding:8px 12px;border:1px solid #ccc;border-radius:6px;background:#fff;cursor:pointer;text-decoration:none;color:#111;}
    .btn.primary{border-color:#2573ef;background:#2f7df4;color:#fff;}
    .badge{display:inline-block;padding:2px 6px;border-radius:4px;border:1px solid #ddd;background:#f8f8f8;font-size:12px;}
  </style>
</head>
<body>

<div class="card">
  <h2>Stock Ledger</h2>
  <form method="get">
    <div class="row">
      <div class="field">
        <label>From</label>
        <input type="date" name="date_from" value="<?=htmlspecialchars($date_from)?>">
      </div>
      <div class="field">
        <label>To</label>
        <input type="date" name="date_to" value="<?=htmlspecialchars($date_to)?>">
      </div>
      <div class="field">
        <label>Item</label>
        <select name="item_id">
          <option value="0">-- Any --</option>
          <?php foreach($items as $it): ?>
          <option value="<?=$it['id']?>" <?=$item_id==$it['id']?'selected':''?>><?=htmlspecialchars($it['label'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Warehouse</label>
        <select name="warehouse_id">
          <option value="0">-- Any --</option>
          <?php foreach($warehouses as $wh): ?>
          <option value="<?=$wh['id']?>" <?=$warehouse_id==$wh['id']?'selected':''?>><?=htmlspecialchars($wh['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Project</label>
        <select name="project_id">
          <option value="0">-- Any --</option>
          <?php foreach($projects as $pr): ?>
          <option value="<?=$pr['id']?>" <?=$project_id==$pr['id']?'selected':''?>><?=htmlspecialchars($pr['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Txn Type</label>
        <select name="txn_type">
          <option value="">-- Any --</option>
          <?php foreach(['ISS','ADJ','GP','GPR','GRN'] as $tt): ?>
            <option value="<?=$tt?>" <?=$txn_type===$tt?'selected':''?>><?=$tt?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Search (Item)</label>
        <input type="text" name="search" value="<?=htmlspecialchars($search)?>" placeholder="Code or Name">
      </div>
      <div class="field">
        <label>&nbsp;</label>
        <button class="btn primary" type="submit">Apply</button>
        <a class="btn" href="?">Reset</a>
        <a class="btn" href="?<?=http_build_query(array_merge($_GET,['export'=>'csv']))?>">Export CSV</a>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between;">
    <div><span class="badge">Rows: <?=$total?></span></div>
    <div>
      <span class="badge">Qty total: <?=number_format((float)$tot['qty_total'], 3)?></span>
      <span class="badge">Amount total: <?=number_format((float)$tot['amt_total'], 2)?></span>
    </div>
  </div>
  <div style="overflow:auto;margin-top:8px;">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Txn No</th>
          <th>Item</th>
          <th>Warehouse</th>
          <th>Project</th>
          <th class="right">Qty</th>
          <th class="right">Rate</th>
          <th class="right">Amount</th>
          <th>UoM</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td class="muted"><?=htmlspecialchars($r['txn_date'])?></td>
          <td><?=htmlspecialchars($r['txn_type'])?></td>
          <td><?=htmlspecialchars($r['txn_no'])?></td>
          <td><?=htmlspecialchars($r['item_code'] . ' — ' . $r['item_name'])?></td>
          <td><?=htmlspecialchars($r['warehouse_name'])?></td>
          <td><?=htmlspecialchars($r['project_name'])?></td>
          <td class="right"><?=number_format((float)$r['qty'], 3)?></td>
          <td class="right"><?=number_format((float)$r['rate'], 4)?></td>
          <td class="right"><?=number_format((float)$r['amount'], 2)?></td>
          <td><?=htmlspecialchars((string)$r['uom_id'])?></td>
        </tr>
        <?php endforeach; if (!$rows): ?>
        <tr><td colspan="10" class="muted">No rows.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total > $per_page): ?>
  <div class="row" style="justify-content:flex-end;margin-top:8px;">
    <?php
      $pages = max(1, ceil($total / $per_page));
      $base = $_GET; unset($base['page']);
      for($p=1;$p<=$pages;$p++){
        $q = http_build_query(array_merge($base,['page'=>$p]));
        $is = $p==$page;
        echo '<a class="btn'.($is?' primary':'').'" href="?'.$q.'">'.$p.'</a> ';
      }
    ?>
  </div>
  <?php endif; ?>
</div>

</body>
</html>
