<?php
/** PATH: /public_html/maintenance/wo_task_toggle.php
 * PURPOSE: Toggle one task status between 'todo' and 'done'
 * PERMS: maintenance.wo.manage
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';

require_login();
require_permission('maintenance.wo.manage');

$pdo = db();
csrf_require_token();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id   = (int)($_POST['id'] ?? 0);
$woId = (int)($_POST['wo_id'] ?? 0);
$to   = (string)($_POST['to'] ?? '');

if ($id <= 0 || $woId <= 0 || !in_array($to, ['todo','done'], true)) {
  http_response_code(400); exit('Invalid params');
}

$st = $pdo->prepare("UPDATE maintenance_wo_tasks SET status=? WHERE id=? AND wo_id=?");
$st->execute([$to, $id, $woId]);

header('Location: /maintenance/wo_view.php?id=' . $woId);