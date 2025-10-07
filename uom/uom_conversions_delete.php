<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.uom.conversion.manage');

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
  db()->prepare("DELETE FROM uom_conversions WHERE id=?")->execute([$id]);
}
header('Location: uom_conversions_list.php'); exit;
