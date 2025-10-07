<?php
declare(strict_types=1);
/** PATH: /public_html/machines/types_by_category.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
require_permission('machines.manage');

$pdo = db();
$cid = (int)($_GET['category_id'] ?? 0);
if (!$cid) { header('Content-Type: application/json'); echo '[]'; exit; }

$col = $pdo->query("SHOW COLUMNS FROM machine_types LIKE 'machine_code'")->fetch() ? 'machine_code' : 'code';
$stmt = $pdo->prepare("SELECT id, name, $col AS machine_code FROM machine_types WHERE category_id=? ORDER BY $col");
$stmt->execute([$cid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($rows, JSON_UNESCAPED_UNICODE);