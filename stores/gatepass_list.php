<?php
/** PATH: /public_html/stores/gatepass_list.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.gatepass.view');

$pdo = db();
$only_pending = isset($_GET['pending']) && $_GET['pending']=='1';
$qtype = $_GET['type'] ?? 'all'; // all/site/jobwork/maintenance/scrap/correction
$wh = (int)($_GET['warehouse_id'] ?? 0);
$q  = trim((string)($_GET['q'] ?? ''));

$where=[]; $p=[];
if ($only_pending) $where[]="gp.status='open' AND gp.returnable=1";
if ($qtype!=='all') { $where[]="gp.gp_type=?"; $p[]=$qtype; }
if ($wh>0) { $where[]="(gp.source_warehouse_id=? OR gp.dest_warehouse_id=?)"; $p[]=$wh; $p[]=$wh; }
if ($q!=='') { $where[]="(gp.gp_no LIKE ? OR gp.remarks LIKE ?)"; $p[]="%$q%"; $p[]="%$q%"; }
$W = $where ? 'WHERE '.implode(' AND ',$where) : '';

$sql = "
SELECT gp.*,
       wsrc.code src_code, wsrc.name src_name,
       wdst.code dst_code, wdst.name dst_name,
       prt.name AS party_name,
       -- materials-only counts/sums
       (
         SELECT COUNT(*) FROM gatepass_items gi
         WHERE gi.gp_id = gp.id AND gi.is_asset = 0
       ) AS items_count,
       (
         SELECT COALESCE(SUM(gi.qty),0) FROM gatepass_items gi
         WHERE gi.gp_id = gp.id AND gi.is_asset = 0
       ) AS qty_total,
       (
         SELECT COALESCE(SUM(gi.qty_returned),0) FROM gatepass_items gi
         WHERE gi.gp_id = gp.id AND gi.is_asset = 0
       ) AS qty_ret
FROM gatepasses gp
JOIN warehouses wsrc ON wsrc.id = gp.source_warehouse_id
LEFT JOIN warehouses wdst ON wdst.id = gp.dest_warehouse_id
LEFT JOIN parties    prt  ON prt.id  = gp.party_id
$W
ORDER BY gp.gp_date DESC, gp.id DESC
LIMIT 500
";
$st=$pdo->prepare($sql); $st->execute($p);
$rows=$st->fetchAll(PDO::FETCH_ASSOC);

$whs = $pdo->query("SELECT id, code, name FROM warehouses WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$can_edit = function_exists('has_permission') ? has_permission('stores.gatepass.edit') : false;

$page_title = "Gate Passes";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <div class="d-flex gap-2">
      <a href="?pending=1" class="btn btn-sm btn-outline-warning<?= $only_pending?' active':'' ?>">Pending Returns</a>
      <a class="btn btn-outline-secondary btn-sm" href="gatepass_form.php">New Gatepass</a>
    </div>
  </div>

  <form class="row g-2 mb-3">
    <div class="col-auto">
      <select name="type" class="form-select form-select-sm">
        <?php foreach (['all'=>'All','site'=>'Site','jobwork'=>'Jobwork','maintenance'=>'Maintenance','scrap'=>'Scrap','correction'=>'Correction'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $k===$qtype?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <select name="warehouse_id" class="form-select form-select-sm">
        <option value="0">All Warehouses</option>
        <?php foreach ($whs as $x): ?>
          <option value="<?= (int)$x['id'] ?>" <?= ((int)$x['id']===$wh)?'selected':'' ?>>
            <?= htmlspecialchars(($x['code']??'').' — '.$x['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <input name="q" class="form-control form-control-sm" placeholder="Search GP No / remark" value="<?= htmlspecialchars($q) ?>">
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
          <th>GP No</th>
          <th>Date</th>
          <th>Type</th>
          <th>Returnable</th>
          <th>From</th>
          <th>To / Party</th>
          <th class="text-end">Lines</th>
          <th class="text-end">Qty</th>
          <th class="text-end">Returned</th>
          <th>Status</th>
          <th style="width:190px"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="11" class="text-center text-muted py-4">No gatepasses.</td></tr>
        <?php else: foreach ($rows as $r):
          $cls = ['open'=>'warning','closed'=>'success','cancelled'=>'danger'][$r['status']] ?? 'secondary';

          // Destination label: show party name for non-transfer; else destination warehouse for transfer
          $toLabel = ($r['gp_type']==='site' && !$r['returnable'])
              ? trim(($r['dst_code']??'').' — '.($r['dst_name']??''))
              : ( ($r['party_name']??'') !== '' ? $r['party_name'] : '—' );
        ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($r['gp_no']) ?></td>
            <td><?= htmlspecialchars($r['gp_date']) ?></td>
            <td><?= htmlspecialchars(ucfirst($r['gp_type'])) ?></td>
            <td><?= $r['returnable'] ? '<span class="badge bg-info-subtle text-info border">Yes</span>' : 'No' ?></td>
            <td><span class="badge bg-secondary-subtle text-secondary border"><?= htmlspecialchars($r['src_code']) ?></span> <?= htmlspecialchars($r['src_name']) ?></td>
            <td><?= htmlspecialchars($toLabel) ?></td>
            <td class="text-end"><?= (int)$r['items_count'] ?></td>
            <td class="text-end"><?= number_format((float)$r['qty_total'],3) ?></td>
            <td class="text-end"><?= number_format((float)$r['qty_ret'],3) ?></td>
            <td><span class="badge bg-<?= $cls ?>-subtle text-<?= $cls ?> border"><?= htmlspecialchars($r['status']) ?></span></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm" role="group">
                <!-- View -->
                <a class="btn btn-outline-secondary" href="gatepass_print.php?id=<?= (int)$r['id'] ?>">View</a>
                <!-- Print -->
                <a class="btn btn-outline-primary" href="gatepass_print.php?id=<?= (int)$r['id'] ?>" target="_blank">Print</a>
                <!-- Edit (permission) -->
                <?php if ($can_edit): ?>
                  <a class="btn btn-outline-warning" href="gatepass_form.php?id=<?= (int)$r['id'] ?>">Edit</a>
                <?php else: ?>
                  <button class="btn btn-outline-warning" disabled title="No permission">Edit</button>
                <?php endif; ?>
              </div>
              <?php if ($r['returnable'] && $r['status']==='open'): ?>
                <a class="btn btn-outline-success btn-sm ms-1" href="gatepass_return_form.php?gp_id=<?= (int)$r['id'] ?>">Return</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
