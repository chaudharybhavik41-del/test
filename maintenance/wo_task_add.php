<?php
/** PATH: /public_html/maintenance/wo_task_add.php
 * PURPOSE: Add a new task line to a WO with status 'todo'
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

$woId = (int)($_POST['wo_id'] ?? 0);
$task = trim((string)($_POST['task'] ?? ''));

if ($woId <= 0 || $task === '') { http_response_code(400); exit('Invalid params'); }

$st = $pdo->prepare("INSERT INTO maintenance_wo_tasks (wo_id, task, status) VALUES (?, ?, 'todo')");
$st->execute([$woId, $task]);

header('Location: /maintenance/wo_view.php?id=' . $woId);