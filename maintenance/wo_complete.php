<?php
/** PATH: /public_html/maintenance/wo_complete.php
 * PURPOSE:
 *  - Complete/close a Work Order.
 *  - If linked to an interval, roll the interval (last_completed_on, next_due_date).
 * SECURITY: maintenance.wo.manage
 * NOTE: Uses status='completed' to match schema/enum.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';

require_login();
require_permission('maintenance.wo.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$woId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($woId <= 0) { http_response_code(400); exit('Invalid WO id'); }

function next_due_from(string $freq, int $count, int $customDays, DateTimeImmutable $base): string {
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
  // Load WO
  $st = $pdo->prepare("
    SELECT id, wo_code, machine_id, interval_id, status, due_date, down_from, up_at
      FROM maintenance_work_orders
     WHERE id = ?");
  $st->execute([$woId]);
  $wo = $st->fetch(PDO::FETCH_ASSOC);
  if (!$wo) { http_response_code(404); exit('Work Order not found'); }

  $today = new DateTimeImmutable('today');

  $pdo->beginTransaction();

  // If tied to an interval → roll it forward
  if (!empty($wo['interval_id'])) {
    $stIv = $pdo->prepare("
      SELECT id, program_id, title, frequency, interval_count, custom_days
        FROM maintenance_intervals
       WHERE id = ?");
    $stIv->execute([(int)$wo['interval_id']]);
    if ($iv = $stIv->fetch(PDO::FETCH_ASSOC)) {
      $next = next_due_from(
        (string)$iv['frequency'],
        (int)($iv['interval_count'] ?? 1),
        (int)($iv['custom_days'] ?? 0),
        $today
      );
      $pdo->prepare("
        UPDATE maintenance_intervals
           SET last_completed_on = ?, next_due_date = ?, updated_at = NOW()
         WHERE id = ?
      ")->execute([$today->format('Y-m-d'), $next, (int)$iv['id']]);
    }
  }

  // Mark WO completed (enum-safe)
  $pdo->prepare("
    UPDATE maintenance_work_orders
       SET status='completed',
           up_at = COALESCE(up_at, NOW())
     WHERE id = ?
  ")->execute([$woId]);

  // Best-effort maintenance log (ignore failure)
  try {
    $pdo->prepare("
      INSERT INTO maintenance_logs (machine_id, wo_id, performed_on, meter_value, remarks, created_by, created_at) VALUES (?, ?, NOW(), NULL, ?, ?, NOW()))
    ")->execute([
      (int)$wo['machine_id'],
      $today->format('Y-m-d'),
      'WO completed: ' . (string)($wo['wo_code'] ?? $woId),
      (int)current_user_id()
    ]);
  } catch (Throwable $e) { /* non-blocking */ }

  $pdo->commit();
  header('Location: /maintenance/wo_view.php?id=' . $woId . '&ok=1');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo "Complete failed: " . $e->getMessage();
  exit;
}
