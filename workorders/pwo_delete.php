<?php
declare(strict_types=1);
/** PATH: /public_html/workorders/pwo_delete.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('workorders.manage');

$pdo = db();
$pdo->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->query("SET collation_connection = 'utf8mb4_general_ci'");

$id = (int)($_POST['id'] ?? 0);

if ($id > 0) {
  // Prevent deleting PWOs that already have DPR logs — safer default
  $chk = $pdo->prepare("SELECT COUNT(*) FROM dpr_process_logs WHERE pwo_id=?");
  $chk->execute([$id]);
  $has = (int)$chk->fetchColumn();

  if ($has > 0) {
    header('Location: pwo_list.php?ok='.urlencode('Cannot delete: DPR exists. Close it instead.')); exit;
  }

  $del = $pdo->prepare("DELETE FROM process_work_orders WHERE id=?");
  $del->execute([$id]);
  header('Location: pwo_list.php?ok='.urlencode('PWO deleted')); exit;
}
header('Location: pwo_list.php?ok='.urlencode('Nothing to delete'));
