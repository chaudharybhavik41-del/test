<?php
declare(strict_types=1);
/** PATH: /public_html/workorders/pwo_list.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('workorders.view');

$pdo = db();
$pdo->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->query("SET collation_connection = 'utf8mb4_general_ci'");

$q    = trim($_GET['q'] ?? '');
$wc   = (int)($_GET['work_center_id'] ?? 0);
$st   = trim($_GET['status'] ?? '');
$asn  = trim($_GET['assign_type'] ?? '');
$cont = (int)($_GET['contractor_id'] ?? 0);
$bomid= (int)($_GET['bom_id'] ?? 0);
$ok   = $_GET['ok'] ?? '';

$where = "1=1";
$params = [];
if ($q !== '') {
  $where .= " AND (pwo.id = ? OR ro.id = ? OR bc.id = ? OR p.code LIKE CONCAT('%',?,'%') OR p.name LIKE CONCAT('%',?,'%'))";
  $intQ = (int)$q;
  $params[] = $intQ; $params[] = $intQ; $params[] = $intQ; $params[] = $q; $params[] = $q;
}
if ($wc>0) { $where .= " AND pwo.work_center_id = ?"; $params[] = $wc; }
if ($st!=='') { $where .= " AND pwo.status = ?"; $params[] = $st; }
if ($asn!=='') { $where .= " AND pwo.assign_type = ?"; $params[] = $asn; }
if ($cont>0) { $where .= " AND COALESCE(pwo.contractor_id,0) = ?"; $params[] = $cont; }
if ($bomid>0){ $where .= " AND b.id = ?"; $params[] = $bomid; }

$sql = "SELECT
          pwo.id, pwo.routing_op_id, pwo.bom_component_id, pwo.process_id, pwo.work_center_id,
          COALESCE(pwo.planned_prod_qty, pwo.planned_qty) AS planned_qty,
          pwo.planned_comm_qty,
          pwo.prod_uom_id, pwo.comm_uom_id,
          pwo.plan_start_date, pwo.plan_end_date, pwo.assign_type, pwo.contractor_id, pwo.status, pwo.remarks,
          p.code AS pcode, p.name AS pname, wc.code AS wccode,
          pr.code AS prod_uom_code, cr.code AS comm_uom_code,
          b.id AS bom_id, b.bom_no,
          COALESCE(SUM(d.qty_done),0) AS qty_done,
          COALESCE(SUM(d.comm_qty),0) AS comm_done,
          par.name AS contractor_name, par.code AS contractor_code
        FROM process_work_orders pwo
        JOIN processes p ON p.id=pwo.process_id
        LEFT JOIN work_centers wc ON wc.id=pwo.work_center_id
        LEFT JOIN dpr_process_logs d ON d.pwo_id=pwo.id
        LEFT JOIN routing_ops ro ON ro.id = pwo.routing_op_id
        LEFT JOIN bom_components bc ON bc.id = pwo.bom_component_id
        LEFT JOIN bom b ON b.id = (SELECT bom_id FROM bom_components WHERE id = pwo.bom_component_id)
        LEFT JOIN uom pr ON pr.id = pwo.prod_uom_id
        LEFT JOIN uom cr ON cr.id = pwo.comm_uom_id
        LEFT JOIN parties par ON par.id = pwo.contractor_id AND par.type='contractor'
        WHERE $where
        GROUP BY pwo.id
        ORDER BY pwo.id DESC
        LIMIT 800";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$wcs =$pdo->query("SELECT id, CONCAT(code,' — ',name) label FROM work_centers WHERE active=1 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$boms=$pdo->query("SELECT id, bom_no FROM bom ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">PWO List</h1>
    <div class="btn-group">
      <a href="pwo_form.php" class="btn btn-primary">Create / Generate</a>
    </div>
  </div>

  <?php if ($ok): ?><div class="alert alert-success"><?=htmlspecialchars($ok)?></div><?php endif; ?>

  <form class="row g-2 mb-3">
    <div class="col-md-2"><input name="q" value="<?=htmlspecialchars($q)?>" class="form-control" placeholder="PWO/RO/Comp/Process"></div>
    <div class="col-md-3">
      <select name="work_center_id" class="form-select">
        <option value="0">All Work Centers</option>
        <?php foreach($wcs as $c): ?>
          <option value="<?=$c['id']?>" <?= $wc===$c['id']?'selected':''?>><?=htmlspecialchars($c['label'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="bom_id" class="form-select">
        <option value="0">All BOMs</option>
        <?php foreach($boms as $b): ?>
          <option value="<?=$b['id']?>" <?= $bomid===$b['id']?'selected':''?>><?=htmlspecialchars($b['bom_no'].' (#'.$b['id'].')')?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="assign_type" class="form-select">
        <option value="">All Assignments</option>
        <option value="company" <?= $asn==='company'?'selected':''?>>Company</option>
        <option value="contractor" <?= $asn==='contractor'?'selected':''?>>Contractor</option>
      </select>
    </div>
    <div class="col-md-2"><input name="contractor_id" type="number" value="<?= $cont ?: '' ?>" class="form-control" placeholder="Contractor ID"></div>
    <div class="col-md-2">
      <select name="status" class="form-select">
        <option value="">All Status</option>
        <?php foreach(['planned','in_progress','hold','completed','closed'] as $s): ?>
          <option <?= $st===$s?'selected':''?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead class="table-light">
        <tr>
          <th>PWO</th><th>BOM</th><th>Process</th><th>WC</th>
          <th class="text-end">Plan (Prod)</th><th class="text-end">Done (Prod)</th>
          <th class="text-end">Plan (Comm)</th><th class="text-end">Done (Comm)</th>
          <th>Status</th><th>Assign</th><th>Plan Window</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r):
        $planProd = (float)$r['planned_qty'];
        $doneProd = (float)$r['qty_done'];
        $planComm = (float)($r['planned_comm_qty'] ?? 0);
        $doneComm = (float)($r['comm_done'] ?? 0);
        $pct = $planProd>0 ? min(100, round($doneProd/$planProd*100,1)) : ($doneProd>0?100:0);
        $assign = $r['assign_type']==='contractor'
                  ? (($r['contractor_name'] ?: $r['contractor_code'] ?: ('Contractor #'.(int)$r['contractor_id'])))
                  : 'Company';
        $prodU = $r['prod_uom_code'] ? ' '.$r['prod_uom_code'] : '';
        $commU = $r['comm_uom_code'] ? ' '.$r['comm_uom_code'] : '';
      ?>
        <tr>
          <td><?=$r['id']?></td>
          <td><?=htmlspecialchars($r['bom_no'] ?? '—')?></td>
          <td><?=htmlspecialchars($r['pcode'].' — '.$r['pname'])?></td>
          <td><?=htmlspecialchars($r['wccode'] ?? '—')?></td>
          <td class="text-end"><?=number_format($planProd,3).$prodU?></td>
          <td class="text-end"><?=number_format($doneProd,3).$prodU?> <small class="text-muted">(<?=$pct?>%)</small></td>
          <td class="text-end"><?= $planComm>0 ? number_format($planComm,3).$commU : '—' ?></td>
          <td class="text-end"><?= $doneComm>0 ? number_format($doneComm,3).$commU : '—' ?></td>
          <td><span class="badge bg-secondary"><?=htmlspecialchars($r['status'])?></span></td>
          <td><?=htmlspecialchars($assign)?></td>
          <td class="text-nowrap"><?=htmlspecialchars(($r['plan_start_date']??'').' → '.($r['plan_end_date']??''))?></td>
          <td class="text-end">
            <a href="pwo_edit.php?id=<?=$r['id']?>" class="btn btn-sm btn-outline-primary">Edit</a>
            <form action="pwo_delete.php" method="post" class="d-inline" onsubmit="return confirm('Delete this PWO? This does not delete DPR logs.');">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; if(!$rows): ?>
        <tr><td colspan="12" class="text-center text-muted py-4">No PWOs found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>