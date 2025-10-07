<?php
/** PATH: /public_html/api/iam_approvals.php */
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();

$pdo = db();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
  require_permission('iam.provision.view');
  $uid = (int)(current_user()['id'] ?? 0);

  $sql = "SELECT pa.id AS approval_id, pr.id AS provision_request_id, pr.employee_id, pr.proposed_roles, pr.proposed_profiles,
                 pa.step_no, pa.decision, pr.status, pr.created_at
          FROM provision_approval pa
          JOIN provision_request pr ON pr.id = pa.provision_request_id
          WHERE pa.approver_user_id = ? AND pa.decision = 'pending'
          ORDER BY pr.created_at ASC";
  $st = $pdo->prepare($sql);
  $st->execute([$uid]);
  echo json_encode(['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
  exit;
}

if ($action === 'decide') {
  require_permission('iam.provision.approve');

  $approvalId = (int)($_POST['approval_id'] ?? $_GET['approval_id'] ?? 0);
  $decision   = $_POST['decision'] ?? $_GET['decision'] ?? '';
  $notes      = trim($_POST['notes'] ?? $_GET['notes'] ?? '');

  if (!$approvalId || !in_array($decision, ['approved','rejected'], true)) {
    http_response_code(400); echo json_encode(['error'=>'approval_id and decision required']); exit;
  }

  $pdo->beginTransaction();
  $st = $pdo->prepare("SELECT pa.*, pr.status, pr.current_step, pr.proposed_roles, pr.employee_id
                       FROM provision_approval pa
                       JOIN provision_request pr ON pr.id = pa.provision_request_id
                       WHERE pa.id = ? FOR UPDATE");
  $st->execute([$approvalId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row || $row['decision'] !== 'pending') { $pdo->rollBack(); http_response_code(404); echo json_encode(['error'=>'not found']); exit; }

  $uid = (int)(current_user()['id'] ?? 0);
  if ((int)$row['approver_user_id'] !== $uid) { $pdo->rollBack(); http_response_code(403); echo json_encode(['error'=>'not your task']); exit; }

  $pdo->prepare("UPDATE provision_approval SET decision=?, notes=?, decided_at=NOW() WHERE id=?")
      ->execute([$decision, $notes ?: null, $approvalId]);

  if ($decision === 'rejected') {
    $pdo->prepare("UPDATE provision_request SET status='rejected', updated_at=NOW() WHERE id=?")->execute([$row['provision_request_id']]);
    $pdo->commit(); echo json_encode(['ok'=>true,'status'=>'rejected']); exit;
  }

  // Is there another pending step?
  $next = $pdo->prepare("SELECT pa.* FROM provision_approval pa WHERE pa.provision_request_id=? AND pa.decision='pending' ORDER BY pa.step_no ASC LIMIT 1");
  $next->execute([$row['provision_request_id']]);
  $n = $next->fetch(PDO::FETCH_ASSOC);
  if ($n) {
    $pdo->prepare("UPDATE provision_request SET current_step=?, updated_at=NOW() WHERE id=?")->execute([$n['step_no'], $row['provision_request_id']]);
    $pdo->commit(); echo json_encode(['ok'=>true,'status'=>'pending_approval','next_step'=>$n['step_no']]); exit;
  }

  // Final approval → apply roles
  $pdo->prepare("UPDATE provision_request SET status='approved', updated_at=NOW() WHERE id=?")->execute([$row['provision_request_id']]);

  // Apply roles by codes → ensure user
  $roleCodes = json_decode($row['proposed_roles'] ?? '[]', true) ?: [];
  $u = $pdo->prepare("SELECT id FROM users WHERE employee_id=? LIMIT 1");
  $u->execute([(int)$row['employee_id']]);
  $uidCreated = $u->fetchColumn();
  if (!$uidCreated) {
    $emp = $pdo->prepare("SELECT * FROM employees WHERE id=?");
    $emp->execute([(int)$row['employee_id']]);
    $employee = $emp->fetch(PDO::FETCH_ASSOC);
    $username = strtolower(preg_replace('/[^a-z0-9]+/i','', ($employee['first_name'] ?? 'user').'.'.($employee['last_name'] ?? '')));
    $name = trim(($employee['first_name'] ?? '').' '.($employee['last_name'] ?? ''));
    $email = $employee['email'] ?? ( $username.'@example.local' );
    $pwd = password_hash(bin2hex(random_bytes(6)), PASSWORD_BCRYPT);
    $ins = $pdo->prepare("INSERT INTO users (employee_id, username, name, email, password, status, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())");
    $ins->execute([(int)$row['employee_id'], $username, $name, $email, $pwd]);
    $uidCreated = (int)$pdo->lastInsertId();
  }
  if ($roleCodes) {
    $in = str_repeat('?,', count($roleCodes)-1) . '?';
    $st = $pdo->prepare("SELECT id FROM roles WHERE code IN ($in)");
    $st->execute($roleCodes);
    $roleIds = $st->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
    foreach ($roleIds as $rid) { $ins->execute([$uidCreated, (int)$rid]); }
  }
  $pdo->commit(); echo json_encode(['ok'=>true,'status'=>'approved']); exit;
}

http_response_code(400);
echo json_encode(['error'=>'unknown action']);
