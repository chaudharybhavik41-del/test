<?php
/** PATH: /public_html/api/hr_validate.php */
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('hr.employee.manage');

$pdo = db();
$field = $_GET['field'] ?? '';
$value = trim($_GET['value'] ?? '');
$id    = (int)($_GET['id'] ?? 0);

if (!in_array($field, ['aadhaar','pan','email','code'], true) || $value==='') {
  http_response_code(400); echo json_encode(['error'=>'bad request']); exit;
}
$sql = "SELECT id FROM employees WHERE $field = ?". ($id ? " AND id <> ?" : "") ." LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute($id ? [$value, $id] : [$value]);
$exists = (bool)$st->fetchColumn();
echo json_encode(['ok'=>true, 'exists'=>$exists]);
