<?php
/** PATH: /public_html/crm/activities_delete.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login(); csrf_require_token(); require_permission('crm.activity.delete');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { flash('Invalid id','danger'); redirect('/crm/activities_list.php'); }

$pdo = db();
$pdo->prepare("UPDATE crm_activities SET deleted_at=NOW() WHERE id=:id AND deleted_at IS NULL")->execute([':id'=>$id]);
flash('Activity deleted','success');
redirect('/crm/activities_list.php');
