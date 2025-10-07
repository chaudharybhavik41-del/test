<?php
/** PATH: /public_html/stores/purchase_advice_list.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('purchase.advice.view');

$pdo = db();

$status = $_GET['status'] ?? 'all';
$wh     = (int)($_GET['warehouse_id'] ?? 0);
$q      = trim((string)($_GET['q'] ?? ''));
$df     = trim((string)($_GET['date_from'] ?? ''));
$dt     = trim((string)($_GET['date_to'] ?? ''));

$where=[]; $p=[];
if ($status !== 'all') { $where[] = "pa.status = ?"; $p[] = $status; }
if ($wh>0)            { $where[] = "pa.warehouse_id = ?"; $p[] = $wh; }
if ($q!=='')          { $where[] = "(pa.advice_no LIKE ? OR pa.remarks LIKE ?)"; $p[]="%$q%"; $p[]="%$q%"; }
if ($df!=='')         { $where[] = "pa.advice_date >= ?"; $p[]=$df; }
if ($dt!=='')         { $where[] = "pa.advice_date <= ?"; $p[]=$dt; }
$W = $where ? ('WHERE '.implode(' AND ',$where)) : '';

$rows = $pdo->prepare("
  SELECT pa.*, w.code wh_code, w.name wh_name,
         (SELECT COUNT(*) FROM purchase_advice_items i WHERE i.advice_id=pa.id) AS line_count
  FROM purchase_advice pa
  JOIN warehouses w ON w.id = pa.warehouse_id
  $W
  ORDER BY pa.advice_date DESC, pa.id DESC
  LIMIT 500
");
$rows->execute($p);
$list = $rows->fetchAll(PDO::FETCH_ASSOC);

$whs = $pdo->query("SELECT id, code, name FROM warehouses WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Purchase Advice";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <a href="minmax_report.php" class="btn btn-sm btn-outline-primary">Min/Max Report</a>
  </div>

  <form class="row g-2 mb-3">
    <div class="col-auto">
      <select class="form-select form-select-sm" name="status">
        <?php foreach (['all'=>'All','draft'=>'Draft','approved'=>'Approved','cancelled'=>'Cancelled'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $k===$status?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select class="form-select form-select-sm" name="warehouse_id">
        <option value="0">All Warehouses</option>
        <?php foreach ($whs as $x): ?>
          <option value="<?= (int)$x['id'] ?>" <?= ((int)$x['id']===$wh)?'selected':'' ?>>
            <?= htmlspecialchars(($x['code']??'').' — '.$x['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <input type="date" class="form-control form-control-sm" name="date_from" value="<?= htmlspecialchars($df) ?>">
    </div>
    <div class="col-auto">
      <input type="date" class="form-control form-control-sm" name="date_to" value="<?= htmlspecialchars($dt) ?>">
    </div>
    <div class="col-auto">
      <input class="form-control form-control-sm" name="q" placeholder="Search advice no / remark" value="<?= htmlspecialchars($q) ?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-secondary">Filter</button>
      <a class="btn btn-sm btn-outline-primary" href="?">Reset</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>Advice No</th>
          <th>Date</th>
          <th>Warehouse</th>
          <th class="text-end">Lines</th>
          <th>Status</th>
          <th>Remarks</th>
          <th style="width:120px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$list): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No purchase advice found.</td></tr>
        <?php else: foreach ($list as $r): ?>
          <tr>
            <td class="fw-semibold"><a href="purchase_advice_view.php?id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['advice_no']) ?></a></td>
            <td><?= htmlspecialchars($r['advice_date']) ?></td>
            <td><span class="badge bg-info-subtle text-info border"><?= htmlspecialchars($r['wh_code']) ?></span> <?= htmlspecialchars($r['wh_name']) ?></td>
            <td class="text-end"><?= (int)$r['line_count'] ?></td>
            <td>
              <?php
                $cls = ['draft'=>'secondary','approved'=>'success','cancelled'=>'danger'][$r['status']] ?? 'secondary';
              ?>
              <span class="badge bg-<?= $cls ?>-subtle text-<?= $cls ?> border"><?= htmlspecialchars($r['status']) ?></span>
            </td>
            <td><?= htmlspecialchars((string)$r['remarks']) ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="purchase_advice_view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
