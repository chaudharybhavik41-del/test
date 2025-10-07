<?php
/** PATH: /public_html/maintenance/interval_quick_complete.php
 * PURPOSE: One-click completion of a maintenance interval (no WO).
 *  - Sets last_completed_on = today
 *  - Advances next_due_date using frequency + interval_count/custom_days
 *  - Optionally writes a maintenance log (best-effort)
 *
 * SECURITY: requires maintenance.plan.manage
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';

require_login();
require_permission('maintenance.plan.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id   = (int)($_POST['id'] ?? 0);
$from = (string)($_POST['from'] ?? '');
if ($id <= 0) { http_response_code(400); exit('Interval id required'); }

$today = new DateTimeImmutable('today');

function compute_next_due(string $freq, int $count, int $customDays, DateTimeImmutable $base): string {
  $count = max(1, $count);
  return match ($freq) {
    'daily'       => $base->modify("+{$count} day")->format('Y-m-d'),
    'weekly'      => $base->modify("+{$count} week")->format('Y-m-d'),
    'monthly'     => $base->modify("+{$count} month")->format('Y-m-d'),
    'quarterly'   => $base->modify("+".(3 * $count)." month")->format('Y-m-d'),
    'semiannual'  => $base->modify("+".(6 * $count)." month")->format('Y-m-d'),
    'annual'      => $base->modify("+{$count} year")->format('Y-m-d'),
    'custom'      => $base->modify("+".max(1, $customDays)." day")->format('Y-m-d'),
    default       => $base->modify("+{$count} month")->format('Y-m-d'),
  };
}

try {
  // Load interval + program/machine
  $st = $pdo->prepare("
    SELECT mi.*, mp.machine_id
      FROM maintenance_intervals mi
      JOIN maintenance_programs mp ON mp.id = mi.program_id
     WHERE mi.id = ?");
  $st->execute([$id]);
  $iv = $st->fetch(PDO::FETCH_ASSOC);
  if (!$iv) { http_response_code(404); exit('Interval not found'); }

  $freq  = (string)$iv['frequency'];
  $cnt   = (int)($iv['interval_count'] ?? 1);
  $cdays = (int)($iv['custom_days'] ?? 0);

  $next = compute_next_due($freq, $cnt, $cdays, $today);

  $pdo->beginTransaction();

  // Update interval dates
  $upd = $pdo->prepare("UPDATE maintenance_intervals
                           SET last_completed_on = ?, next_due_date = ?, updated_at = NOW()
                         WHERE id = ?");
  $upd->execute([$today->format('Y-m-d'), $next, $id]);

  // Best-effort maintenance log (schema variations tolerated)
  try {
    // Try the common schema first
    $insLog = $pdo->prepare("
      INSERT INTO maintenance_logs (machine_id, wo_id, performed_on, meter_value, remarks, created_by, created_at) VALUES (?, NULL, NOW(), NULL, ?, ?, NOW()))
    ");
    $note = 'Quick-completed: ' . ((string)$iv['title'] ?: 'Scheduled Maintenance');
    $insLog->execute([(int)$iv['machine_id'], $today->format('Y-m-d'), $note, (int)current_user_id()]);
  } catch (Throwable $e) {
    // If table or columns differ, ignore silently to avoid blocking completion.
  }

  $pdo->commit();

  // Redirect back
  if ($from === 'schedule') {
    header('Location: /maintenance/schedule.php?ok=1');
  } else {
    header('Location: /maintenance/intervals_list.php?program_id='.(int)$iv['program_id'].'&ok=1');
  }
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo "Quick-complete failed: " . $e->getMessage();
  exit;
}
