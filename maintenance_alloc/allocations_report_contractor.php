<?php
/** PATH: /public_html/maintenance_alloc/allocations_report_contractor.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('maintenance.wo.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$from = ($_GET['from'] ?? '') ?: date('Y-m-01');
$to   = ($_GET['to'] ?? '') ?: date('Y-m-t');

$sql = "
SELECT p.id AS contractor_id, p.code, p.name,
       COUNT(DISTINCT wo.id) AS wo_count,
       COALESCE(SUM(wo.total_cost),0) AS wo_cost_total,
       COUNT(DISTINCT ma.machine_id) AS machines_used
FROM machine_allocations ma
JOIN parties p ON p.id=ma.contractor_id
JOIN machines m ON m.id=ma.machine_id
JOIN maintenance_work_orders wo
  ON wo.machine_id = ma.machine_id
 AND wo.status='completed'
 AND DATE(wo.closed_at) BETWEEN ? AND ?
 AND (
       /* WO falls within allocation window */
       DATE(wo.closed_at) BETWEEN ma.alloc_date AND COALESCE(ma.return_date, DATE(?))
     )
WHERE ma.status IN ('issued','returned')
GROUP BY p.id, p.code, p.name
ORDER BY wo_cost_total DESC";
$st = $pdo->prepare($sql);
$st->execute([$from,$to,$to]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$PAGE_TITLE = 'Maintenance by Contractor';
$ACTIVE_MENU = 'machines.list';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?=$PAGE_TITLE?></h1>
  <form class="d-flex gap-2">
    <input type="date" name="from" class="form-control form-control-sm" value="<?=htmlspecialchars($from)?>">
    <input type="date" name="to"   class="form-control form-control-sm" value="<?=htmlspecialchars($to)?>">
    <button class="btn btn-sm btn-outline-primary">Apply</button>
  </form>
</div>

<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th>Contractor</th><th class="text-end">Machines Used</th>
        <th class="text-end">Completed WOs</th><th class="text-end">Total Maint. Cost (₹)</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['code'].' — '.$r['name'])?></td>
          <td class="text-end"><?= (int)$r['machines_used'] ?></td>
          <td class="text-end"><?= (int)$r['wo_count'] ?></td>
          <td class="text-end"><?= number_format((float)$r['wo_cost_total'],2) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-light" href="/maintenance_alloc/allocations_trace.php?contractor_id=<?=$r['contractor_id']?>&from=<?=$from?>&to=<?=$to?>">Trace</a>
          </td>
        </tr>
      <?php endforeach; if(!$rows): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No data for range.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/../ui/layout_end.php';
