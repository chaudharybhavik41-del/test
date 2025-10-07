<?php
/** PATH: /public_html/api/iam_provisioning.php */
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/lib_iam_provisioning.php';

require_login();
require_permission('core.user.manage');

$pdo = db();
$action = $_GET['action'] ?? 'preview';
$employeeId = isset($_REQUEST['employee_id']) ? (int)$_REQUEST['employee_id'] : 0;
if ($employeeId <= 0) { http_response_code(400); echo json_encode(['error'=>'employee_id is required']); exit; }

try {
  if ($action === 'preview') {
    $data = iam_preview_for_employee($pdo, $employeeId);
    if ($data['roles']) {
      $in = str_repeat('?,', count($data['roles'])-1) . '?';
      $st = $pdo->prepare("SELECT id, code FROM roles WHERE code IN ($in)");
      $st->execute($data['roles']);
      $data['role_id_map'] = $st->fetchAll(PDO::FETCH_KEY_PAIR); // id=>code
    }
    echo json_encode($data); exit;
  }

  if ($action === 'commit') {
    $requestedBy = (int)(current_user()['id'] ?? 0);
    $res = iam_commit_provision($pdo, $employeeId, $requestedBy);
    echo json_encode(['ok'=>true] + $res); exit;
  }

  http_response_code(400);
  echo json_encode(['error'=>'unknown action']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
