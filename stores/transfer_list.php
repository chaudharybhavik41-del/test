<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_permission('stores.transfer.manage');
$pdo = db();

/* Filters */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$whf  = isset($_GET['from_wh']) && $_GET['from_wh'] !== '' ? (int)$_GET['from_wh'] : null;
$wht  = isset($_GET['to_wh'])   && $_GET['to_wh']   !== '' ? (int)$_GET['to_wh']   : null;
$q    = trim($_GET['q'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$pp   = 25;
$off  = ($page - 1) * $pp;

/* Warehouses for filters */
$warehouses = $pdo->query("SELECT id, COALESCE(name, CONCAT('WH-',id)) AS name FROM warehouses ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);

/* WHERE on created_at date to avoid schema mismatch on trn_date */
$where = ["DATE(created_at) BETWEEN :from AND :to"];
$args  = [':from'=>$from, ':to'=>$to];
if ($whf) { $where[] = "from_warehouse_id = :whf"; $args[':whf'] = $whf; }
if ($wht) { $where[] = "to_warehouse_id   = :wht"; $args[':wht'] = $wht; }
if ($q !== '') {
  $where[] = "(trn_no LIKE :q OR remarks LIKE :q)";
  $args[':q'] = "%{$q}%";
}
$sqlWhere = implode(' AND ', $where);

/* Count */
$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM stock_transfers WHERE $sqlWhere");
$stmtCnt->execute($args);
$total = (int)$stmtCnt->fetchColumn();

/* Data */
$sql = "
 SELECT t.id, t.trn_no, t.created_at, t.status, t.from_warehouse_id, t.to_warehouse_id, t.remarks,
        COALESCE(wf.name, CONCAT('WH-',t.from_warehouse_id)) AS from_wh_name,
        COALESCE(wt.name, CONCAT('WH-',t.to_warehouse_id))   AS to_wh_name,
        (SELECT COUNT(*) FROM stock_transfer_items li WHERE li.transfer_id = t.id) AS lines,
        (SELECT SUM(li.qty) FROM stock_transfer_items li WHERE li.transfer_id = t.id) AS qty_sum
 FROM stock_transfers t
 LEFT JOIN warehouses wf ON wf.id = t.from_warehouse_id
 LEFT JOIN warehouses wt ON wt.id = t.to_warehouse_id
 WHERE $sqlWhere
 ORDER BY t.created_at DESC, t.id DESC
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
  <title>Stock Transfers</title>
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
  <h2>Stock Transfers</h2>
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
      <label>From Warehouse</label>
      <select name="from_wh">
        <option value="">-- All --</option>
        <?php foreach($warehouses as $w): ?>
          <option value="<?=$w['id']?>" <?=($whf===$w['id']?'selected':'')?>>
            <?=htmlspecialchars($w['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>To Warehouse</label>
      <select name="to_wh">
        <option value="">-- All --</option>
        <?php foreach($warehouses as $w): ?>
          <option value="<?=$w['id']?>" <?=($wht===$w['id']?'selected':'')?>>
            <?=htmlspecialchars($w['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="flex:1;">
      <label>Search</label>
      <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="TRF No / Remarks">
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
      $qs    = $_GET; // mutable
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
        <th>TRF No</th>
        <th>From</th>
        <th>To</th>
        <th>Lines</th>
        <th>Total Qty</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars(substr($r['created_at'],0,10))?></td>
          <td><?=htmlspecialchars($r['trn_no'])?></td>
          <td><?=htmlspecialchars($r['from_wh_name'])?></td>
          <td><?=htmlspecialchars($r['to_wh_name'])?></td>
          <td><?= (int)$r['lines'] ?></td>
          <td><?= number_format((float)$r['qty_sum'],3) ?></td>
          <td><?=htmlspecialchars(strtoupper($r['status']))?></td>
          <td><a class="btn" href="transfer_view.php?id=<?=$r['id']?>">View</a></td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr><td colspan="8" class="muted">No data.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>
