<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.uom.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: uom_list.php'); exit; }

try {
  $stmt = db()->prepare("DELETE FROM uom WHERE id=?");
  $stmt->execute([$id]);
} catch (Throwable $e) {
  // You can switch to soft delete if needed:
  // db()->prepare("UPDATE uom SET status='inactive' WHERE id=?")->execute([$id]);
}
header('Location: uom_list.php'); exit;
