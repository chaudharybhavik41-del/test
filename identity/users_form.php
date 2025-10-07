<?php
/** PATH: /public_html/identity/users_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('core.user.manage');

// Permission-derived UI flags
$canCreate = has_permission('core.user.manage');
$canEdit   = has_permission('core.user.manage');
$canRoles  = has_permission('userrole.update');

$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $id > 0;

$user = [
  'employee_id' => null,
  'username'    => '',
  'name'        => '',
  'email'       => '',
  'status'      => 'active',
];

// --- Prefill from Employee when coming from HR list ---
$fromEmployeeId = isset($_GET['from_employee_id']) ? (int)$_GET['from_employee_id'] : 0;

if (!$id && $fromEmployeeId > 0) {
  // If a user already exists for this employee, redirect to edit that user
  $st = $pdo->prepare("SELECT id FROM users WHERE employee_id=? LIMIT 1");
  $st->execute([$fromEmployeeId]);
  $existingUid = (int)$st->fetchColumn();
  if ($existingUid) {
    header('Location: /identity/users_form.php?id='.$existingUid.'&from=employee'); exit;
  }

  // Fetch employee details and prefill
  $st = $pdo->prepare("SELECT first_name, last_name, email FROM employees WHERE id=?");
  $st->execute([$fromEmployeeId]);
  if ($emp = $st->fetch(PDO::FETCH_ASSOC)) {
    $first = trim($emp['first_name'] ?? '');
    $last  = trim($emp['last_name'] ?? '');
    $name  = trim($first.' '.$last);
    $email = trim($emp['email'] ?? '');

    // Suggest a username: first.last (lowercase, alnum+dot), ensure unique
    $base = strtolower(preg_replace('/[^a-z0-9]+/i','.', trim($first).'.'.trim($last)));
    $base = trim($base, '.');
    if ($base === '') $base = 'user'.mt_rand(100,999);

    $username = $base;
    $try = 1;
    $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username=?");
    while (true) {
      $chk->execute([$username]);
      if ((int)$chk->fetchColumn() === 0) break;
      $try++;
      $username = $base.$try;
      if ($try > 50) break; // safety
    }

    // Set into form model
    $user['employee_id'] = $fromEmployeeId;
    $user['username']    = $username;
    $user['name']        = $name ?: $username;
    $user['email']       = $email;
    $user['status']      = 'active';
  }
}

if ($editing) {
    $stmt = $pdo->prepare("SELECT id, employee_id, username, name, email, status FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit('User not found.'); }
    $user = array_merge($user, $row);
}

/* ---- Load roles & current assignments ---- */
$roles = $pdo->query("SELECT id, code, name FROM roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$assignedRoleIds = [];
if ($editing) {
    $st = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
    $st->execute([$id]);
    $assignedRoleIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'role_id'));
}

$errors = [];
$okMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If you have CSRF helper, uncomment:
    // require_once __DIR__ . '/../includes/csrf.php';
    // csrf_validate_or_die();

    $employee_id = (isset($_POST['employee_id']) && $_POST['employee_id'] !== '') ? (int)$_POST['employee_id'] : null;
    $username = trim((string)($_POST['username'] ?? ''));
    $name     = trim((string)($_POST['name'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $status   = ($_POST['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive';
    $password = (string)($_POST['password'] ?? '');
    $roleIds  = array_map('intval', (array)($_POST['roles'] ?? []));

    if ($username === '') $errors[] = 'Username is required.';
    if ($name === '')     $errors[] = 'Name is required.';
    if ($email === '')    $errors[] = 'Email is required.';

    // Unique username/email
    if (!$errors) {
        $sql = "SELECT id FROM users WHERE (username = ? OR email = ?)";
        $params = [$username, $email];
        if ($editing) { $sql .= " AND id <> ?"; $params[] = $id; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if ($st->fetch(PDO::FETCH_ASSOC)) $errors[] = 'Username or Email already exists.';
    }

    // Unique employee_id (at most one user per employee)
    if (!$errors && $employee_id) {
        $sql = "SELECT id FROM users WHERE employee_id = ?";
        $params = [$employee_id];
        if ($editing) { $sql .= " AND id <> ?"; $params[] = $id; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if ($st->fetch(PDO::FETCH_ASSOC)) $errors[] = 'This Employee is already linked to another user.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($editing) {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $st = $pdo->prepare("UPDATE users SET employee_id=?, username=?, name=?, email=?, status=?, password=?, updated_at=NOW() WHERE id=?");
                    $st->execute([$employee_id, $username, $name, $email, $status, $hash, $id]);
                } else {
                    $st = $pdo->prepare("UPDATE users SET employee_id=?, username=?, name=?, email=?, status=?, updated_at=NOW() WHERE id=?");
                    $st->execute([$employee_id, $username, $name, $email, $status, $id]);
                }

                // Replace roles
                $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
                if ($roleIds) {
                    $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($roleIds as $rid) $ins->execute([$id, $rid]);
                }

                $okMsg = 'User updated.';
            } else {
                if ($password === '') throw new RuntimeException('Password is required for new users.');
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $st = $pdo->prepare("INSERT INTO users (employee_id, username, name, email, password, status, created_at, updated_at)
                                     VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $st->execute([$employee_id, $username, $name, $email, $hash, $status]);
                $id = (int)$pdo->lastInsertId();
                $editing = true;

                if ($roleIds) {
                    $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($roleIds as $rid) $ins->execute([$id, $rid]);
                }

                $okMsg = 'User created.';
            }

            $pdo->commit();

            // Reload
            $stmt = $pdo->prepare("SELECT id, employee_id, username, name, email, status FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;

            $st = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
            $st->execute([$id]);
            $assignedRoleIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'role_id'));
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

/* ---- Use your UI files ---- */
$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = $editing ? 'Edit User' : 'Add User';
$ACTIVE_MENU = 'identity.users';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><?= $editing ? 'Edit User' : 'Add User' ?></h1>
  <div class="d-flex gap-2">
    <?php if (!empty($user['employee_id'])): ?>
      <a class="btn btn-outline-dark btn-sm"
         href="/api/iam_provisioning.php?action=preview&employee_id=<?= (int)$user['employee_id'] ?>"
         target="_blank">Preview Access</a>
      <button type="button" class="btn btn-outline-primary btn-sm" onclick="commitProvision(<?= (int)$user['employee_id'] ?>)">
        Provision / Update Access
      </button>
    <?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="/identity/users_list.php">Back</a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php elseif ($okMsg): ?>
  <div class="alert alert-success"><?= htmlspecialchars($okMsg) ?></div>
<?php endif; ?>

<?php if (!empty($user['employee_id'])): ?>
  <div class="alert alert-info py-2">
    Linked Employee ID: <strong>#<?= (int)$user['employee_id'] ?></strong>
    <a class="ms-2" href="/hr/employees_form.php?id=<?= (int)$user['employee_id'] ?>" target="_blank">Open Employee</a>
  </div>
<?php endif; ?>

<form method="post" class="row g-3">
  <!-- <?php // if (function_exists('csrf_field')) csrf_field(); ?> -->

  <?php if (!empty($user['employee_id'])): ?>
    <input type="hidden" name="employee_id" value="<?= (int)$user['employee_id'] ?>">
  <?php else: ?>
    <div class="col-md-3">
      <label class="form-label">Link Employee (ID)</label>
      <input type="number" name="employee_id" class="form-control" value="<?= isset($user['employee_id']) ? (int)$user['employee_id'] : '' ?>">
      <div class="form-text">Optional — link to HR employee record.</div>
    </div>
  <?php endif; ?>

  <div class="col-md-6">
    <label class="form-label">Username</label>
    <input name="username" class="form-control" required value="<?= htmlspecialchars((string)$user['username']) ?>">
  </div>

  <div class="col-md-6">
    <label class="form-label">Name</label>
    <input name="name" class="form-control" required value="<?= htmlspecialchars((string)$user['name']) ?>">
  </div>

  <div class="col-md-6">
    <label class="form-label">Email</label>
    <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars((string)$user['email']) ?>">
  </div>

  <div class="col-md-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <option value="active"   <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>

  <div class="col-md-3">
    <label class="form-label"><?= $editing ? 'New Password (optional)' : 'Password' ?></label>
    <input type="password" name="password" class="form-control" <?= $editing ? '' : 'required' ?>>
  </div>

  <div class="col-12">
    <label class="form-label">Roles</label>
    <div class="border rounded p-3" style="max-height: 360px; overflow:auto;">
      <?php foreach ($roles as $r):
        $rid = (int)$r['id'];
        $checked = in_array($rid, $assignedRoleIds, true);
      ?>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="roles[]" value="<?= $rid ?>" id="role<?= $rid ?>" <?= $checked ? 'checked' : '' ?>>
          <label class="form-check-label" for="role<?= $rid ?>">
            <code><?= htmlspecialchars((string)($r['code'] ?? 'role_'.$rid)) ?></code> — <?= htmlspecialchars((string)$r['name']) ?>
          </label>
        </div>
      <?php endforeach; ?>
      <?php if (!$roles): ?>
        <div class="text-muted">No roles defined yet. Create roles first.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12">
    <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create User' ?></button>
  </div>
</form>

<script>
async function commitProvision(empId) {
  if (!empId) return alert('No linked employee to provision.');
  if (!confirm('Generate/Update access for the linked employee via rules?')) return;
  try {
    const r = await fetch('/api/iam_provisioning.php?action=commit&employee_id=' + empId, { credentials: 'same-origin' });
    const data = await r.json();
    if (data.error) return alert(data.error);
    alert('Done: ' + (data.status || 'ok'));
  } catch (e) {
    alert('Failed: ' + e);
  }
}
</script>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
