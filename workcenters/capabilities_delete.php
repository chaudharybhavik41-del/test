<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('workcenters.manage');

$pdo = db();
$id = (int)($_POST['id'] ?? 0);
$wc = (int)($_POST['work_center_id'] ?? 0);
if ($id>0) {
  $stmt=$pdo->prepare("DELETE FROM work_center_capabilities WHERE id=?");
  $stmt->execute([$id]);
}
header('Location: capabilities_form.php?work_center_id='.$wc);
