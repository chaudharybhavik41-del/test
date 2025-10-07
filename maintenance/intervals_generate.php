<?php
/** PATH: /public_html/maintenance/intervals_generate.php
 * PURPOSE:
 *   - Generate WO(s) from maintenance intervals.
 *   - Works for a single interval (?id=123) or all due in a program (?program_id=45).
 *
 * PHP: 8.4
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('maintenance.wo.manage');

$pdo = db();
// Keep session consistent with rest of app.
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id         = (int)($_GET['id'] ?? 0);          // maintenance_intervals.id
$program_id = (int)($_GET['program_id'] ?? 0);  // maintenance_programs.id

if ($id <= 0 && $program_id <= 0) {
  http_response_code(400);
  echo "Usage: intervals_generate.php?id={interval_id} OR ?program_id={program_id}";
  exit;
}

/** Safe, concurrent WO number allocator (same approach as wo_form.php) */
function allocate_wo_code(PDO $pdo): string {
  $year = date('Y');
  $lock = "wo_code_$year";
  $pdo->query("SELECT GET_LOCK('$lock', 10)");
  try {
    $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(wo_code, 10) AS UNSIGNED)) FROM maintenance_work_orders WHERE wo_code LIKE ?");
    $st->execute(["WO-$year-%"]);
    $next = ((int)$st->fetchColumn()) + 1;
    return sprintf("WO-%s-%04d", $year, $next);
  } finally {
    $pdo->query("SELECT RELEASE_LOCK('$lock')");
  }
}

/** Build one WO for a given interval row (joined with program for machine_id). Returns new WO id or null if skipped. */
function create_wo_for_interval(PDO $pdo, array $iv): ?int {
  // Skip if there is already an open/in-progress WO for this interval
  $chk = $pdo->prepare("SELECT id FROM maintenance_work_orders WHERE interval_id=? AND status NOT IN ('closed','cancelled') ORDER BY id DESC LIMIT 1");
  $chk->execute([(int)$iv['id']]);
  if ($chk->fetchColumn()) return null;

  $wo_code = allocate_wo_code($pdo);

  $title   = (string)($iv['title'] ?? 'Scheduled Maintenance');
  $desc    = (string)($iv['description'] ?? null);
  $due     = $iv['next_due_date'] ?? null; // may be null
  $dueStr  = $due ? (string)$due : date('Y-m-d');

  $ins = $pdo->prepare("
    INSERT INTO maintenance_work_orders
      (wo_code, machine_id, interval_id, title, description, maintenance_type_id, priority, status, due_date,
       reported_by, reported_at, down_from, up_at, parts_cost, labour_cost_internal, labour_cost_vendor, misc_cost, total_cost,
       created_by, created_at)
    VALUES
      (?,?,?,?,?,?,?,?,?,
       ?, NOW(), ?, ?, 0, 0, 0, 0, 0,
       ?, NOW())
  ");

  $ins->execute([
    $wo_code,
    (int)$iv['machine_id'],
    (int)$iv['id'],
    $title,
    $desc ?: null,
    (int)($iv['maintenance_type_id'] ?? 0) ?: null,
    'medium',              // default
    'open',                // new WO starts open
    $dueStr,
    current_user_id(),
    null,                  // down_from
    null,                  // up_at
    current_user_id()
  ]);

  $wo_id = (int)$pdo->lastInsertId();

  // Seed tasks: prefer checklist_json if present, else ensure at least one task line = title
  $added = false;
  if (!empty($iv['checklist_json'])) {
    $chk = json_decode((string)$iv['checklist_json'], true);
    if (is_array($chk) && $chk) {
      $insT = $pdo->prepare("INSERT INTO maintenance_wo_tasks (wo_id, task, status) VALUES (?,?, 'todo')");
      foreach ($chk as $t) {
        $task = (string)($t['task'] ?? '');
        if ($task !== '') { $insT->execute([$wo_id, $task]); $added = true; }
      }
    }
  }
  if (!$added) {
    $pdo->prepare("INSERT INTO maintenance_wo_tasks (wo_id, task, status) VALUES (?,?, 'todo')")
        ->execute([$wo_id, $title]);
  }

  return $wo_id;
}

// Single interval mode:
if ($id > 0) {
  $st = $pdo->prepare("SELECT mi.*, mp.machine_id
                         FROM maintenance_intervals mi
                         JOIN maintenance_programs mp ON mp.id = mi.program_id
                        WHERE mi.id=?");
  $st->execute([$id]);
  $iv = $st->fetch(PDO::FETCH_ASSOC);
  if (!$iv) { http_response_code(404); echo "Interval not found"; exit; }

  $pdo->beginTransaction();
  try {
    $wo_id = create_wo_for_interval($pdo, $iv);
    $pdo->commit();
    if ($wo_id) {
      header("Location: /maintenance/wo_view.php?id=" . $wo_id);
    } else {
      header("Location: /maintenance/intervals_list.php?program_id=" . (int)$iv['program_id'] . "&info=exists");
    }
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Generate failed: " . $e->getMessage();
    exit;
  }
}

// Program batch mode: create WOs for all intervals due today or earlier
$st = $pdo->prepare("SELECT mi.*, mp.machine_id
                       FROM maintenance_intervals mi
                       JOIN maintenance_programs mp ON mp.id = mi.program_id
                      WHERE mi.program_id=? AND mi.next_due_date IS NOT NULL AND mi.next_due_date <= CURDATE()
                      ORDER BY mi.next_due_date, mi.id");
$st->execute([$program_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$made = 0;
$pdo->beginTransaction();
try {
  foreach ($rows as $iv) {
    $wo_id = create_wo_for_interval($pdo, $iv);
    if ($wo_id) $made++;
  }
  $pdo->commit();
  header("Location: /maintenance/intervals_list.php?program_id=".$program_id."&generated=".$made);
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $msg = urlencode('Generate failed: '.$e->getMessage());
  header("Location: /maintenance/intervals_list.php?program_id=".$program_id."&error=".$msg);
  exit;
}
