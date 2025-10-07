<?php
/** PATH: /public_html/items/items_restore.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('materials.item.manage');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

if ($id > 0) {
  $pdo->prepare("UPDATE items SET status='active' WHERE id=?")->execute([$id]);
}

header('Location: /items/items_list.php');
exit;
