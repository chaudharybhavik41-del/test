<?php
/** PATH: /public_html/maintenance/schedule.php
 * PURPOSE: Show maintenance person a "today" view:
 *  - Due plan items (intervals with next_due_date <= today)
 *  - My open Work Orders due today
 * ACTIONS:
 *  - Generate WO (links to intervals_generate.php)
 *  - Quick Complete (POST to interval_quick_complete.php)
 *
 * PHP 8.4, Bootstrap 5 UI
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';

require_login();

// Create DB connection BEFORE any queries
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

// KPI badges (non-blocking)
$counts = [
  'overdue_intervals'   => 0,
  'due_today_intervals' => 0,
  'due_week_intervals'  => 0,
  'wo_due_today'        => 0,
  'wo_due_week'         => 0,
];

try {
  // Overdue / Due Intervals
  $st = $pdo->prepare("
    SELECT
      SUM(CASE WHEN mi.next_due_date IS NOT NULL AND mi.next_due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_intervals,
      SUM(CASE WHEN mi.next_due_date = CURDATE() THEN 1 ELSE 0 END) AS due_today_intervals,
      SUM(CASE WHEN mi.next_due_date > CURDATE() AND mi.next_due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due_week_intervals
    FROM maintenance_intervals mi
    JOIN maintenance_programs mp ON mp.id = mi.program_id
  ");
  $st->execute();
  $counts = array_merge($counts, $st->fetch(PDO::FETCH_ASSOC) ?: []);

  // Work Orders due
  $st2 = $pdo->prepare("
    SELECT
      SUM(CASE WHEN due_date = CURDATE() AND status IN ('open','in_progress','waiting_parts') THEN 1 ELSE 0 END) AS wo_due_today,
      SUM(CASE WHEN due_date > CURDATE() AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               AND status IN ('open','in_progress','waiting_parts') THEN 1 ELSE 0 END) AS wo_due_week
    FROM maintenance_work_orders
  ");
  $st2->execute();
  $counts = array_merge($counts, $st2->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {
  // kpi non-blocking
}

// Permissions: view schedule; manage allows quick-complete
$canView   = has_permission('maintenance.plan.view') || has_permission('maintenance.wo.view') || is_admin();
$canManage = has_permission('maintenance.plan.manage') || has_permission('maintenance.wo.manage') || is_admin();
if (!$canView) { http_response_code(403); exit('Access denied'); }

// Filters
$team  = trim((string)($_GET['team'] ?? ''));        // optional program.default_team
$q     = trim((string)($_GET['q'] ?? ''));           // search by machine code/name or interval title
$mine  = (int)($_GET['mine'] ?? 0);                  // reserved for future responsible_user_id

// Today
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

// --- Query: Due intervals (next_due_date <= today), active only ---
$where  = ["mi.active = 1", "(mi.next_due_date IS NOT NULL AND mi.next_due_date <= ?)"];
$params = [$today];

if ($team !== '') {
  $where[]  = "mp.default_team = ?";
  $params[] = $team;
}
if ($q !== '') {
  $like = '%'.$q.'%';
  $where[] = "(m.machine_id COLLATE utf8mb4_general_ci LIKE ? OR m.name COLLATE utf8mb4_general_ci LIKE ? OR mi.title COLLATE utf8mb4_general_ci LIKE ?)";
  array_push($params, $like, $like, $like);
}

$sql_due = "
SELECT mi.id AS interval_id, mi.title, mi.frequency, mi.interval_count, mi.custom_days,
       mi.next_due_date, mi.requires_shutdown, mi.priority,
       mi.maintenance_type_id, mt.name AS maintenance_type_name,
       mp.id AS program_id, mp.default_team,
       m.id AS machine_id, m.machine_id AS machine_code, m.name AS machine_name
  FROM maintenance_intervals mi
  JOIN maintenance_programs mp ON mp.id = mi.program_id
  JOIN machines m ON m.id = mp.machine_id
  LEFT JOIN maintenance_types mt ON mt.id = mi.maintenance_type_id
 WHERE " . implode(' AND ', $where) . "
 ORDER BY mi.next_due_date ASC, mi.priority DESC, mi.id ASC
 LIMIT 200";
$st = $pdo->prepare($sql_due);
$st->execute($params);
$due_rows = $st->fetchAll(PDO::FETCH_ASSOC);

// --- Query: Open WOs due today (or overdue) ---
$sql_wo = "
SELECT wo.id, wo.wo_code, wo.title, wo.status, wo.due_date,
       m.machine_id AS machine_code, m.name AS machine_name
  FROM maintenance_work_orders wo
  JOIN machines m ON m.id = wo.machine_id
 WHERE wo.status IN ('open','in_progress') AND wo.due_date IS NOT NULL AND wo.due_date <= ?
 ORDER BY wo.due_date ASC, wo.id DESC
 LIMIT 200";
$st2 = $pdo->prepare($sql_wo);
$st2->execute([$today]);
$wo_rows = $st2->fetchAll(PDO::FETCH_ASSOC);

// UI shell
$UI          = $ROOT . '/ui';
$PAGE_TITLE  = 'Maintenance — Daily Schedule';
$ACTIVE_MENU = 'maintenance.schedule';
require_once $UI . '/init.php';
require_once $UI . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Daily Maintenance Schedule</h1>
  <div data-kpi-badges class="d-flex gap-2">
    <span class="badge bg-danger">Overdue: <?= (int)($counts['overdue_intervals'] ?? 0) ?></span>
    <span class="badge bg-warning text-dark">Due Today: <?= (int)($counts['due_today_intervals'] ?? 0) ?></span>
    <span class="badge bg-info text-dark">Due Week: <?= (int)($counts['due_week_intervals'] ?? 0) ?></span>
    <span class="badge bg-primary">WO Today: <?= (int)($counts['wo_due_today'] ?? 0) ?></span>
    <span class="badge bg-secondary">WO Week: <?= (int)($counts['wo_due_week'] ?? 0) ?></span>
  </div>
</div>

<form method="get" class="row g-2 mb-3">
  <div class="col-md-3">
    <input name="q" class="form-control" placeholder="Search machine/interval" value="<?= htmlspecialchars($q) ?>">
  </div>
  <div class="col-md-3">
    <input name="team" class="form-control" placeholder="Team (e.g., Mechanical)" value="<?= htmlspecialchars($team) ?>">
  </div>
  <div class="col-md-auto">
    <button class="btn btn-outline-secondary">Filter</button>
    <a class="btn btn-outline-secondary" href="/maintenance/schedule.php">Reset</a>
  </div>
</form>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-due" type="button" role="tab">Plan Due (<?= count($due_rows) ?>)</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-wo" type="button" role="tab">My Open WOs (<?= count($wo_rows) ?>)</button>
  </li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tab-due" role="tabpanel">
    <?php if (!$due_rows): ?>
      <div class="alert alert-success">No intervals due today—great job!</div>
    <?php else: ?>
      <div class="row row-cols-1 row-cols-lg-2 g-3">
        <?php foreach ($due_rows as $r): ?>
          <div class="col">
            <div class="card shadow-sm">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="small text-muted"><?= htmlspecialchars((string)($r['maintenance_type_name'] ?? '')) ?></div>
                    <h5 class="card-title mb-1"><?= htmlspecialchars((string)($r['title'] ?: 'Scheduled Maintenance')) ?></h5>
                    <div class="small">
                      <strong><?= htmlspecialchars((string)$r['machine_code']) ?></strong> — <?= htmlspecialchars((string)$r['machine_name']) ?>
                    </div>
                  </div>
                  <div class="text-end">
                    <?php
                      $pri = (string)($r['priority'] ?? 'medium');
                      $priClass = ($pri === 'high' ? 'danger' : ($pri === 'medium' ? 'warning text-dark' : 'secondary'));
                    ?>
                    <span class="badge bg-<?= $priClass ?>"><?= htmlspecialchars($pri) ?></span>
                    <div class="small text-muted mt-1">
                      Due: <strong><?= htmlspecialchars((string)$r['next_due_date']) ?></strong>
                    </div>
                  </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                  <a class="btn btn-sm btn-primary" href="/maintenance/intervals_generate.php?id=<?= (int)$r['interval_id'] ?>">
                    <i class="bi bi-gear-wide-connected me-1"></i> Generate WO
                  </a>

                  <?php if ($canManage): ?>
                    <form method="post" action="/maintenance/interval_quick_complete.php" class="d-inline">
                      <?= csrf_hidden_input() ?>
                      <input type="hidden" name="id" value="<?= (int)$r['interval_id'] ?>">
                      <input type="hidden" name="from" value="schedule">
                      <button class="btn btn-sm btn-success" onclick="return confirm('Mark this interval as completed for today and roll next due date?');">
                        <i class="bi bi-check2-circle me-1"></i> Quick Complete
                      </button>
                    </form>
                  <?php endif; ?>

                  <a class="btn btn-sm btn-outline-secondary" href="/maintenance/intervals_list.php?program_id=<?= (int)$r['program_id'] ?>">Program</a>
                </div>

                <div class="mt-2 small text-muted">
                  Freq:
                  <?php
                  $f  = (string)$r['frequency'];
                  $c  = (int)$r['interval_count'];
                  $cd = (int)($r['custom_days'] ?? 0);
                  echo htmlspecialchars($f . ($c ? "×$c" : '') . ($f === 'custom' && $cd ? " ($cd days)" : ''));
                  ?>
                  <?php if ((int)$r['requires_shutdown'] === 1): ?>
                    &nbsp;•&nbsp;<span class="text-danger">Requires shutdown</span>
                  <?php endif; ?>
                  <?php if (!empty($r['default_team'])): ?>
                    &nbsp;•&nbsp;Team: <?= htmlspecialchars((string)$r['default_team']) ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="tab-pane fade" id="tab-wo" role="tabpanel">
    <?php if (!$wo_rows): ?>
      <div class="alert alert-info">No open work orders due today.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>WO</th>
              <th>Title</th>
              <th>Machine</th>
              <th>Due</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($wo_rows as $w): ?>
            <tr>
              <td><code><?= htmlspecialchars((string)$w['wo_code']) ?></code></td>
              <td><?= htmlspecialchars((string)$w['title']) ?></td>
              <td><strong><?= htmlspecialchars((string)$w['machine_code']) ?></strong> — <?= htmlspecialchars((string)$w['machine_name']) ?></td>
              <td><?= htmlspecialchars((string)$w['due_date']) ?></td>
              <td><span class="badge bg-secondary"><?= htmlspecialchars((string)$w['status']) ?></span></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="/maintenance/wo_view.php?id=<?= (int)$w['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
require_once $UI . '/layout_end.php';
