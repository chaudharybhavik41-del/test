<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_permission('stocks.adjust.manage');
$pdo = db();

/* Fetch filters */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$wh   = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
$mode = isset($_GET['mode']) && $_GET['mode'] !== '' ? strtoupper($_GET['mode']) : null;
$q    = trim($_GET['q'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$pp   = 25;
$off  = ($page - 1) * $pp;

/* Warehouses for filter */
$warehouses = $pdo->query("SELECT id, COALESCE(name, CONCAT('WH-',id)) AS name FROM warehouses ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);

/* Build WHERE */
$where = ["adj_date BETWEEN :from AND :to"];
$args  = [':from'=>$from, ':to'=>$to];

if ($wh)   { $where[] = "warehouse_id = :wh"; $args[':wh'] = $wh; }
if ($mode) { $where[] = "mode = :mode";       $args[':mode'] = $mode; }
if ($q !== ''){
  $where[] = "(adj_no LIKE :q OR remarks LIKE :q)";
  $args[':q'] = "%{$q}%";
}
$sqlWhere = implode(' AND ', $where);

/* Count */
$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments WHERE $sqlWhere");
$stmtCnt->execute($args);
$total = (int)$stmtCnt->fetchColumn();

/* Data */
$sql = "
 SELECT sa.id, sa.adj_no, sa.adj_date, sa.mode, sa.warehouse_id, sa.remarks, sa.status,
        COALESCE(w.name, CONCAT('WH-',sa.warehouse_id)) AS warehouse_name,
        (SELECT SUM(l.qty) FROM stock_adjustment_items l WHERE l.adj_id = sa.id) AS line_qty
 FROM stock_adjustments sa
 LEFT JOIN warehouses w ON w.id = sa.warehouse_id
 WHERE $sqlWhere
 ORDER BY sa.adj_date DESC, sa.id DESC
 LIMIT $pp OFFSET $off
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Stock Adjustments</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px}
    .card{border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px}
    .row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
    .field{display:flex;flex-direction:column;min-width:180px}
    table{width:100%;border-collapse:collapse;font-size:14px}
    th,td{border-bottom:1px solid #eee;padding:8px;text-align:left}
    th{background:#fafafa}
    .btn{display:inline-block;padding:6px 10px;border:1px solid #ccc;border-radius:6px;background:#fff;text-decoration:none;color:#111}
    .pager{display:flex;gap:8px;align-items:center}
    .muted{opacity:.75}
    .tag{display:inline-block;padding:2px 6px;border:1px solid #aaa;border-radius:4px;font-size:12px}
  </style>
</head>
<body>

<div class="card">
  <h2>Stock Adjustments</h2>
  <form method="get" class="row">
    <div class="field">
      <label>From</label>
      <input type="date" name="from" value="<?=htmlspecialchars($from)?>">
    </div>
    <div class="field">
      <label>To</label>
      <input type="date" name="to" value="<?=htmlspecialchars($to)?>">
    </div>
    <div class="field">
      <label>Warehouse</label>
      <select name="warehouse_id">
        <option value="">-- All --</option>
        <?php foreach($warehouses as $w): ?>
          <option value="<?=$w['id']?>" <?=($wh===$w['id']?'selected':'')?>>
            <?=htmlspecialchars($w['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Mode</label>
      <select name="mode">
        <option value="">-- All --</option>
        <option value="IN"  <?=($mode==='IN'?'selected':'')?>>IN (+)</option>
        <option value="OUT" <?=($mode==='OUT'?'selected':'')?>>OUT (-)</option>
      </select>
    </div>
    <div class="field" style="flex:1;">
      <label>Search</label>
      <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Adj No / Remarks">
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <button class="btn" type="submit">Filter</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between;">
    <div class="muted"><?=number_format($total)?> result(s)</div>
    <div class="pager">
      <?php
      $pages = max(1, (int)ceil($total/$pp));
      $base  = strtok($_SERVER['REQUEST_URI'],'?');
      $qs    = $_GET; // mutable copy
      $qs['page'] = max(1,$page-1);
      $prevUrl = $base . '?' . http_build_query($qs);
      $qs['page'] = min($pages,$page+1);
      $nextUrl = $base . '?' . http_build_query($qs);
      ?>
      <a class="btn" href="<?=$prevUrl?>">&laquo; Prev</a>
      <span class="muted">Page <?=$page?> / <?=$pages?></span>
      <a class="btn" href="<?=$nextUrl?>">Next &raquo;</a>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Adj No</th>
        <th>Warehouse</th>
        <th>Mode</th>
        <th>Total Qty</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['adj_date'])?></td>
          <td><?=htmlspecialchars($r['adj_no'])?></td>
          <td><?=htmlspecialchars($r['warehouse_name'])?></td>
          <td><span class="tag"><?=$r['mode']==='IN'?'IN (+)':'OUT (-)'?></span></td>
          <td><?=number_format((float)$r['line_qty'],3)?></td>
          <td><?=htmlspecialchars(strtoupper($r['status']))?></td>
          <td><a class="btn" href="stock_adjust_view.php?id=<?=$r['id']?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted">No data.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>
