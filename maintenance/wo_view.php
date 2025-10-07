<?php
/** PATH: /public_html/maintenance/wo_view.php
 * PURPOSE: View a Work Order with tickable tasks + allocation & costing context.
 * PERMS: view => maintenance.wo.view (or admin), manage => maintenance.wo.manage
 * PHP: 8.4
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';

require_login();

$canView   = has_permission('maintenance.wo.view')   || is_admin();
$canManage = has_permission('maintenance.wo.manage') || is_admin();
if (!$canView) { http_response_code(403); exit('Access denied'); }

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$woId = (int)($_GET['id'] ?? 0);
if ($woId <= 0) { http_response_code(400); exit('Invalid WO id'); }

/** Load WO header + machine */
$st = $pdo->prepare("
  SELECT wo.*,
         m.machine_id AS machine_code, m.name AS machine_name
    FROM maintenance_work_orders wo
    JOIN machines m ON m.id = wo.machine_id
   WHERE wo.id = ?
");
$st->execute([$woId]);
$wo = $st->fetch(PDO::FETCH_ASSOC);
if (!$wo) { http_response_code(404); exit('Work Order not found'); }

/** If linked to interval, load minimal interval (title + program link) */
$interval = null;
if (!empty($wo['interval_id'])) {
  $sti = $pdo->prepare("SELECT mi.id, mi.title, mi.program_id FROM maintenance_intervals mi WHERE mi.id=?");
  $sti->execute([(int)$wo['interval_id']]);
  $interval = $sti->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Determine 'as-of' date for allocation attribution */
$asOf = null;
if (!empty($wo['due_date'])) {
  $asOf = (string)$wo['due_date'];
} else {
  // Fallback to created_at if present; else today
  $asOf = !empty($wo['created_at']) ? date('Y-m-d', strtotime((string)$wo['created_at'])) : date('Y-m-d');
}

/**
 * Load allocation record as of $asOf.
 * Window: alloc_date .. COALESCE(return_date, expected_return, '9999-12-31')
 * Prefer active status 'issued'; but allow a returned record if asOf sits within window historically.
 */
$alloc = null;
try {
  $stmt = $pdo->prepare("
  SELECT ma.*, p.name AS contractor_name
    FROM machine_allocations ma
    JOIN parties p ON p.id = ma.contractor_id
   WHERE ma.machine_id = ?
     AND ? BETWEEN ma.alloc_date AND COALESCE(ma.effective_end_date, '9999-12-31')
   ORDER BY ma.status = 'issued' DESC, ma.id DESC
   LIMIT 1
");

  $stmt->execute([(int)$wo['machine_id'], $asOf]);
  $alloc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $alloc = null; // Never block the page on allocation lookup
}

/** Load tasks */
$st2 = $pdo->prepare("
  SELECT id, task, status
    FROM maintenance_wo_tasks
   WHERE wo_id = ?
   ORDER BY id ASC
");
$st2->execute([$woId]);
$tasks = $st2->fetchAll(PDO::FETCH_ASSOC);

/** UI shell */
$UI = $ROOT . '/ui';
$PAGE_TITLE  = 'WO ' . htmlspecialchars((string)$wo['wo_code']);
$ACTIVE_MENU = 'maintenance.wo';
require_once $UI . '/init.php';
require_once $UI . '/layout_start.php';

$ok = (int)($_GET['ok'] ?? 0);
?>
<?php if ($ok): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    Work Order marked <strong>completed</strong>.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Work Order <code><?= htmlspecialchars((string)$wo['wo_code']) ?></code></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/maintenance/wo_list.php">Back to list</a>
  </div>
</div>

<div class="row g-3">
  <!-- HEADER + ALLOCATION + COSTING -->
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title mb-3">Header</h5>
        <div class="row">
          <div class="col-sm-6 mb-2">
            <strong>Machine</strong><br>
            <span class="text-muted"><?= htmlspecialchars((string)$wo['machine_code']) ?></span> — <?= htmlspecialchars((string)$wo['machine_name']) ?>
          </div>
          <div class="col-sm-6 mb-2">
            <strong>Status</strong><br>
            <span class="badge bg-secondary"><?= htmlspecialchars((string)$wo['status']) ?></span>
          </div>
          <div class="col-sm-6 mb-2">
            <strong>Title</strong><br><?= htmlspecialchars((string)$wo['title']) ?>
          </div>
          <div class="col-sm-6 mb-2">
            <strong>Due Date</strong><br><?= htmlspecialchars((string)($wo['due_date'] ?? '')) ?>
          </div>

          <?php if ($interval): ?>
          <div class="col-sm-12 mb-2">
            <strong>Source Interval</strong><br>
            <div class="small">
              <?= htmlspecialchars((string)($interval['title'] ?: 'Scheduled Maintenance')) ?>
              &nbsp;·&nbsp;
              <a href="/maintenance/intervals_list.php?program_id=<?= (int)$interval['program_id'] ?>">View program</a>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($wo['description'])): ?>
          <div class="col-sm-12 mb-2">
            <strong>Description</strong><br><?= nl2br(htmlspecialchars((string)$wo['description'])) ?>
          </div>
          <?php endif; ?>

          <?php if ($alloc): ?>
          <div class="col-sm-12 mb-2">
            <strong>Allocation (as of <?= htmlspecialchars($asOf) ?>)</strong><br>
            <div class="small">
              Contractor: <strong><?= htmlspecialchars((string)$alloc['contractor_name']) ?></strong>
              &nbsp;•&nbsp; Code: <code><?= htmlspecialchars((string)$alloc['alloc_code']) ?></code>
              <br>
              From <strong><?= htmlspecialchars((string)$alloc['alloc_date']) ?></strong>
              to <strong><?= htmlspecialchars((string)($alloc['return_date'] ?? $alloc['expected_return'] ?? 'open')) ?></strong>
              &nbsp;•&nbsp; Status: <span class="badge bg-<?= ($alloc['status']==='issued'?'warning text-dark':'secondary') ?>">
                <?= htmlspecialchars((string)$alloc['status']) ?>
              </span>
              <?php if (!is_null($alloc['meter_issue'])): ?>
                <br><span class="text-muted">Meter @ issue:</span> <?= number_format((float)$alloc['meter_issue'], 2) ?>
              <?php endif; ?>
              <?php if (!is_null($alloc['meter_return'])): ?>
                &nbsp;|&nbsp;<span class="text-muted">Meter @ return:</span> <?= number_format((float)$alloc['meter_return'], 2) ?>
              <?php endif; ?>
              <?php if (!empty($alloc['remarks'])): ?>
                <div class="mt-1 text-muted">Remarks: <?= htmlspecialchars((string)$alloc['remarks']) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- Costing block -->
          <div class="col-sm-12 mt-2">
            <h6 class="mt-3">Costing</h6>
            <div class="row g-2 small">
              <div class="col-6"><span class="text-muted">Parts</span><br><strong><?= number_format((float)($wo['parts_cost'] ?? 0), 2) ?></strong></div>
              <div class="col-6"><span class="text-muted">Labour (Internal)</span><br><strong><?= number_format((float)($wo['labour_cost_internal'] ?? 0), 2) ?></strong></div>
              <div class="col-6"><span class="text-muted">Labour (Vendor)</span><br><strong><?= number_format((float)($wo['labour_cost_vendor'] ?? 0), 2) ?></strong></div>
              <div class="col-6"><span class="text-muted">Misc</span><br><strong><?= number_format((float)($wo['misc_cost'] ?? 0), 2) ?></strong></div>
              <div class="col-12"><hr class="my-2"></div>
              <div class="col-12"><span class="text-muted">Total</span><br><strong><?= number_format((float)($wo['total_cost'] ?? 0), 2) ?></strong></div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- TASKS -->
  <div class="col-lg-6">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <h5 class="card-title mb-0">Tasks</h5>
          <?php if ($canManage): ?>
          <form class="d-flex gap-2" method="post" action="/maintenance/wo_task_add.php">
            <input type="hidden" name="wo_id" value="<?= (int)$woId ?>">
            <input class="form-control form-control-sm" name="task" placeholder="New task..." required>
            <button class="btn btn-sm btn-primary">Add</button>
          </form>
          <?php endif; ?>
        </div>

        <div class="mt-3">
          <?php if (!$tasks): ?>
            <div class="text-muted">No tasks yet.</div>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($tasks as $t): $done = (string)$t['status'] === 'done'; ?>
                <li class="list-group-item">
                  <?php if ($canManage): ?>
                  <form method="post" action="/maintenance/wo_task_toggle.php" class="m-0">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <input type="hidden" name="wo_id" value="<?= (int)$woId ?>">
                    <input type="hidden" name="to" value="<?= $done ? 'todo' : 'done' ?>">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="task<?= (int)$t['id'] ?>"
                             <?= $done ? 'checked' : '' ?>
                             onchange="this.form.submit()">
                      <label class="form-check-label <?= $done ? 'text-decoration-line-through text-muted' : '' ?>"
                             for="task<?= (int)$t['id'] ?>">
                        <?= htmlspecialchars((string)$t['task']) ?>
                      </label>
                    </div>
                  </form>
                  <?php else: ?>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" disabled <?= $done ? 'checked' : '' ?>>
                    <label class="form-check-label <?= $done ? 'text-decoration-line-through text-muted' : '' ?>">
                      <?= htmlspecialchars((string)$t['task']) ?>
                    </label>
                  </div>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
        <div class="mt-3 d-flex gap-2">
          <a class="btn btn-success" href="/maintenance/wo_complete.php?id=<?= (int)$woId ?>"
             onclick="return confirm('Mark this WO as completed? Intervals (if linked) will roll to next due.');">
             Complete WO
          </a>
          <a class="btn btn-outline-secondary" href="/maintenance/wo_edit.php?id=<?= (int)$woId ?>">Edit</a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once $UI . '/layout_end.php'; ?>
