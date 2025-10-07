<?php
/** PATH: /public_html/includes/lib_iam_provisioning.php */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rbac.php';

/** Evaluate rules for an employee and return preview data. */
function iam_preview_for_employee(PDO $pdo, int $employeeId): array {
    $empStmt = $pdo->prepare("SELECT e.*, d.name AS dept_name
                              FROM employees e
                              LEFT JOIN departments d ON d.id = e.dept_id
                              WHERE e.id = ?");
    $empStmt->execute([$employeeId]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) { throw new RuntimeException('employee not found'); }

    $rules = $pdo->query("SELECT * FROM iam_rule WHERE enabled = 1 ORDER BY priority ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $matchedRules = [];
    $profiles = [];
    $roles = [];
    $requiresApproval = false;
    $flowCode = null;

    foreach ($rules as $r) {
        if (iam_rule_matches($r, $employee)) {
            $matchedRules[] = $r['code'];
            $gr = json_decode($r['grants_json'] ?? '{}', true);
            foreach (($gr['profiles'] ?? []) as $pcode) { $profiles[] = $pcode; }
            foreach (($gr['roles'] ?? []) as $rcode)    { $roles[] = $rcode; }
            if ((int)$r['requires_approval'] === 1) {
                $requiresApproval = true;
                if (!$flowCode && !empty($r['approval_flow_code'])) $flowCode = $r['approval_flow_code'];
            }
        }
    }

    return [
        'employee'          => $employee,
        'matched_rules'     => array_values(array_unique($matchedRules)),
        'profiles'          => array_values(array_unique($profiles)),
        'roles'             => array_values(array_unique($roles)),  // role codes
        'requires_approval' => $requiresApproval,
        'flow_code'         => $flowCode ?: 'std-manager-appowner',
    ];
}

/** Simple condition evaluator supporting eq,in,gte,lte arrays of clauses */
function iam_rule_matches(array $rule, array $emp): bool {
    $cond = json_decode($rule['condition_json'] ?? '[]', true);
    if (!$cond || !is_array($cond)) return false;
    $ok = true;
    foreach ($cond as $op => $clauses) {
        if (!is_array($clauses)) continue;
        foreach ($clauses as $clause) {
            $field = $clause['field'] ?? null;
            $value = $clause['value'] ?? null;
            $empVal = $emp[$field] ?? null;
            switch ($op) {
                case 'eq':  $ok = $ok && ($empVal == $value); break;
                case 'in':  $ok = $ok && (is_array($value) && in_array($empVal, $value, true)); break;
                case 'gte': $ok = $ok && (is_numeric($empVal) && (float)$empVal >= (float)$value); break;
                case 'lte': $ok = $ok && (is_numeric($empVal) && (float)$empVal <= (float)$value); break;
                default: /* ignore */ break;
            }
            if (!$ok) return false;
        }
    }
    return $ok;
}

/** Ensure user exists for employee and assign roles by role codes; returns user_id */
function iam_apply_roles_for_employee(PDO $pdo, int $employeeId, array $roleCodes): int {
    $u = $pdo->prepare("SELECT id FROM users WHERE employee_id=? LIMIT 1");
    $u->execute([$employeeId]);
    $uid = $u->fetchColumn();

    if (!$uid) {
        $emp = $pdo->prepare("SELECT * FROM employees WHERE id=?");
        $emp->execute([$employeeId]);
        $employee = $emp->fetch(PDO::FETCH_ASSOC);
        if (!$employee) throw new RuntimeException('employee not found');

        $username = strtolower(preg_replace('/[^a-z0-9]+/i','', ($employee['first_name'] ?? 'user').'.'.($employee['last_name'] ?? '')));
        $name     = trim(($employee['first_name'] ?? '').' '.($employee['last_name'] ?? ''));
        $email    = $employee['email'] ?? ($username.'@example.local');
        $pwd      = password_hash(bin2hex(random_bytes(6)), PASSWORD_BCRYPT);

        $ins = $pdo->prepare("INSERT INTO users (employee_id, username, name, email, password, status, created_at, updated_at)
                              VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())");
        $ins->execute([$employeeId, $username, $name, $email, $pwd]);
        $uid = (int)$pdo->lastInsertId();
    }

    if ($roleCodes) {
        $in = str_repeat('?,', count($roleCodes)-1) . '?';
        $st = $pdo->prepare("SELECT id FROM roles WHERE code IN ($in)");
        $st->execute($roleCodes);
        $roleIds = $st->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        foreach ($roleIds as $rid) $ins->execute([(int)$uid, (int)$rid]);
    }

    return (int)$uid;
}

/** Create provision_request + (if needed) approval tasks; auto-apply if no approval required. */
function iam_commit_provision(PDO $pdo, int $employeeId, int $requestedBy): array {
    $preview   = iam_preview_for_employee($pdo, $employeeId);
    $roleCodes = $preview['roles'];
    $profiles  = $preview['profiles'];
    $requires  = $preview['requires_approval'];
    $flowCode  = $preview['flow_code'];

    $st = $pdo->prepare("INSERT INTO provision_request (employee_id, requested_by, status, proposed_profiles, proposed_roles, flow_code, current_step, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    $status = $requires ? 'pending_approval' : 'approved';
    $currentStep = $requires ? 1 : null;
    $st->execute([$employeeId, $requestedBy, $status, json_encode($profiles), json_encode($roleCodes), $flowCode, $currentStep]);
    $prId = (int)$pdo->lastInsertId();

    if ($requires) {
        // Step 1 = manager_of(employee)
        $emp = $pdo->prepare("SELECT manager_employee_id FROM employees WHERE id=?");
        $emp->execute([$employeeId]);
        $mgrEmpId = (int)$emp->fetchColumn();
        if ($mgrEmpId) {
            $u = $pdo->prepare("SELECT id FROM users WHERE employee_id=?");
            $u->execute([$mgrEmpId]);
            $approverUserId = (int)$u->fetchColumn();
            if ($approverUserId) {
                $pdo->prepare("INSERT INTO provision_approval (provision_request_id, step_no, approver_user_id)
                               VALUES (?, 1, ?)")
                    ->execute([$prId, $approverUserId]);
            }
        }
        return ['status'=>'pending_approval', 'provision_request_id'=>$prId];
    }

    iam_apply_roles_for_employee($pdo, $employeeId, $roleCodes);
    return ['status'=>'approved', 'provision_request_id'=>$prId];
}
