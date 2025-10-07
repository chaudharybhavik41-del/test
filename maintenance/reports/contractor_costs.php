<?php
/** PATH: /public_html/maintenance/reports/contractor_costs.php
 * PURPOSE: Summarize maintenance cost per contractor during their machine allocation windows.
 * RULE: Attribute each WO to the contractor who had the machine allocated on the WO's
 *       as-of date: COALESCE(wo.due_date, DATE(wo.created_at)).
 * NOTE: Uses generated column machine_allocations.effective_end_date.
 * PERMS: maintenance.report.view OR admin
 * PHP: 8.4
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';

require_login();
if (!(has_permission('maintenance.report.view') || is_admin())) {
  http_response_code(403); exit('Access denied');
}

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to']   ?? ''));
if ($from === '' || $to === '') {
  $from = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
  $to   = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
}

// Optional filters
$machineId    = (int)($_GET['machine_id'] ?? 0);
$contractorId = (int)($_GET['contractor_id'] ?? 0);
$statusFilter = trim((string)($_GET['wo_status'] ?? 'completed')); // completed | open | all

$asOf = "COALESCE(wo.due_date, DATE(wo.created_at))";

// Build WHERE
$params = [$from, $to];
$where  = ["$asOf BETWEEN ? AND ?"];

// WO status filter
if ($statusFilter === 'completed') {
  $where[] = "wo.status = 'completed'";
} elseif ($statusFilter === 'open') {
  $where[] = "wo.status IN ('open','in_progress')";
} // else 'all' = no status filter

if ($machineId > 0)    { $where[] = "wo.machine_id = ?"; $params[] = $machineId; }
if ($contractorId > 0) { $where[] = "ma.contractor_id = ?"; $params[] = $contractorId; }

// MAIN rollup
$sql = "
SELECT
  p.id   AS contractor_id,
  p.name AS contractor_name,
  COUNT(DISTINCT wo.id)                        AS wo_count,
  COALESCE(SUM(wo.parts_cost),0)               AS parts_cost,
  COALESCE(SUM(wo.labour_cost_internal),0)     AS labour_internal,
  COALESCE(SUM(wo.labour_cost_vendor),0)       AS labour_vendor,
  COALESCE(SUM(wo.misc_cost),0)                AS misc_cost,
  COALESCE(SUM(wo.total_cost),0)               AS total_cost
FROM maintenance_work_orders wo
JOIN machines m ON m.id = wo.machine_id
JOIN machine_allocations ma
  ON ma.machine_id = wo.machine_id
 AND $asOf BETWEEN ma.alloc_date AND COALESCE(ma.effective_end_date, '9999-12-31')
JOIN parties p ON p.id = ma.contractor_id
WHERE " . implode(' AND ', $where) . "
GROUP BY p.id, p.name
ORDER BY total_cost DESC, wo_count DESC
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// DETAIL query (per contractor)
$sqlDetail = "
SELECT
  wo.id, wo.wo_code, wo.title, wo.status, wo.due_date,
  wo.parts_cost, wo.labour_cost_internal, wo.labour_cost_vendor, wo.misc_cost, wo.total_cost,
  m.machine_id AS machine_code, m.name AS machine_name
FROM maintenance_work_orders wo
JOIN machines m ON m.id = wo.machine_id
JOIN machine_allocations ma
  ON ma.machine_id = wo.machine_id
 AND $asOf BETWEEN ma.alloc_date AND COALESCE(ma.effective_end_date, '9999-12-31')
WHERE " . implode(' AND ', $where) . "
  AND ma.contractor_id = ?
ORDER BY wo.due_date, wo.id DESC
";

$UI = $ROOT . '/ui';
$PAGE_TITLE  = 'Maintenance Cost by Contractor';
$ACTIVE_MENU = 'maintenance.reports.contractor_costs';
require_once $UI . '/init.php';
require_once $UI . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Maintenance Cost by Contractor</h1>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto">
    <label class="form-label">From</label>
    <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label">To</label>
    <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to) ?>">
  </div>
  <div class="col-auto">
    <label class="form-label">WO Status</label>
    <select class="form-select" name="wo_status">
      <option value="completed" <?= $statusFilter==='completed'?'selected':'' ?>>Completed</option>
      <option value="open" <?= $statusFilter==='open'?'selected':'' ?>>Open/In Progress</option>
      <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All</option>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label">Machine ID (opt)</label>
    <input type="number" class="form-control" name="machine_id" value="<?= (int)$machineId ?>">
  </div>
  <div class="col-auto">
    <label class="form-label">Contractor ID (opt)</label>
    <input type="number" class="form-control" name="contractor_id" value="<?= (int)$contractorId ?>">
  </div>
  <div class="col-auto align-self-end">
    <button class="btn btn-primary">Apply</button>
    <a class="btn btn-outline-secondary" href="/maintenance/reports/contractor_costs.php">Reset</a>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="alert alert-info">No matching work orders in the selected window.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>Contractor</th>
          <th class="text-end">WO Count</th>
          <th class="text-end">Parts</th>
          <th class="text-end">Labour (Int)</th>
          <th class="text-end">Labour (Vendor)</th>
          <th class="text-end">Misc</th>
          <th class="text-end">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string)$r['contractor_name']) ?> <span class="text-muted">(#<?= (int)$r['contractor_id'] ?>)</span></td>
          <td class="text-end"><?= (int)$r['wo_count'] ?></td>
          <td class="text-end"><?= number_format((float)$r['parts_cost'], 2) ?></td>
          <td class="text-end"><?= number_format((float)$r['labour_internal'], 2) ?></td>
          <td class="text-end"><?= number_format((float)$r['labour_vendor'], 2) ?></td>
          <td class="text-end"><?= number_format((float)$r['misc_cost'], 2) ?></td>
          <td class="text-end"><strong><?= number_format((float)$r['total_cost'], 2) ?></strong></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary" type="button"
              data-bs-toggle="collapse" data-bs-target="#detail<?= (int)$r['contractor_id'] ?>">Details</button>
          </td>
        </tr>
        <tr class="collapse" id="detail<?= (int)$r['contractor_id'] ?>">
          <td colspan="8">
            <?php
              $stD = $pdo->prepare($sqlDetail);
              $paramsD = array_merge($params, [(int)$r['contractor_id']]);
              $stD->execute($paramsD);
              $detail = $stD->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php if (!$detail): ?>
              <div class="text-muted">No WOs.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm">
                  <thead>
                    <tr>
                      <th>WO</th><th>Title</th><th>Machine</th><th>Due</th>
                      <th class="text-end">Parts</th>
                      <th class="text-end">Lab (Int)</th>
                      <th class="text-end">Lab (Vendor)</th>
                      <th class="text-end">Misc</th>
                      <th class="text-end">Total</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($detail as $d): ?>
                    <tr>
                      <td><code><?= htmlspecialchars((string)$d['wo_code']) ?></code></td>
                      <td><?= htmlspecialchars((string)$d['title']) ?></td>
                      <td><strong><?= htmlspecialchars((string)$d['machine_code']) ?></strong> — <?= htmlspecialchars((string)$d['machine_name']) ?></td>
                      <td><?= htmlspecialchars((string)($d['due_date'] ?? '')) ?></td>
                      <td class="text-end"><?= number_format((float)$d['parts_cost'], 2) ?></td>
                      <td class="text-end"><?= number_format((float)$d['labour_cost_internal'], 2) ?></td>
                      <td class="text-end"><?= number_format((float)$d['labour_cost_vendor'], 2) ?></td>
                      <td class="text-end"><?= number_format((float)$d['misc_cost'], 2) ?></td>
                      <td class="text-end"><strong><?= number_format((float)$d['total_cost'], 2) ?></strong></td>
                      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/maintenance/wo_view.php?id=<?= (int)$d['id'] ?>">Open</a></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require_once $UI . '/layout_end.php'; ?>
