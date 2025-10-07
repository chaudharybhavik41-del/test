<?php
/** PATH: /public_html/hr/employees_list.php (with Create/Edit User button) */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('hr.employee.view');

$canManageEmp  = has_permission('hr.employee.manage');
$canManageUser = has_permission('core.user.manage');

$PAGE_TITLE  = 'Employees';
$ACTIVE_MENU = 'hr.employees';

$LAYOUT_DIR = null;
foreach ([__DIR__ . '/../ui', dirname(__DIR__) . '/ui', $_SERVER['DOCUMENT_ROOT'] . '/ui'] as $dir) {
  if (is_dir($dir)) { $LAYOUT_DIR = rtrim($dir, '/'); break; }
}
if ($LAYOUT_DIR) include $LAYOUT_DIR . '/layout_start.php'; else echo '<!doctype html><html><head><meta charset="utf-8"><title>'.$PAGE_TITLE.'</title></head><body><div class="container-fluid">';

$pdo = db();
$q = trim($_GET['q'] ?? '');
$sql = "SELECT e.id, e.code, e.first_name, e.last_name, e.email, e.status, e.grade_level, e.location, d.name AS dept_name,
               u.id AS user_id, u.username
        FROM employees e
        LEFT JOIN departments d ON d.id = e.dept_id
        LEFT JOIN users u ON u.employee_id = e.id
        WHERE (? = '' OR e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR e.code LIKE ?)
        ORDER BY e.created_at DESC
        LIMIT 500";
$like = '%'.$q.'%';
$stmt = $pdo->prepare($sql);
$stmt->execute([$q, $like, $like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0">Employees</h1>
  <div>
    <?php if ($canManageEmp): ?>
      <a href="employees_form.php" class="btn btn-primary btn-sm">New Employee</a>
    <?php endif; ?>
  </div>
</div>

<form class="mb-3" method="get">
  <div class="input-group input-group-sm" style="max-width: 520px;">
    <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name, email, code">
    <button class="btn btn-outline-secondary">Search</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th style="width:72px">#</th>
        <th>Code</th>
        <th>Name</th>
        <th>Email</th>
        <th>Dept</th>
        <th>Grade</th>
        <th>Location</th>
        <th>Status</th>
        <th>User</th>
        <th style="width: 260px">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><code><?= htmlspecialchars($r['code']) ?></code></td>
          <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></td>
          <td><?= htmlspecialchars($r['email']) ?></td>
          <td><?= htmlspecialchars($r['dept_name'] ?? '-') ?></td>
          <td><?= htmlspecialchars((string)$r['grade_level'] ?? '-') ?></td>
          <td><?= htmlspecialchars($r['location'] ?? '-') ?></td>
          <td><span class="badge bg-<?= $r['status']==='active'?'success':'secondary' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <td>
            <?php if ($r['user_id']): ?>
              <span class="badge bg-info">#<?= (int)$r['user_id'] ?></span>
              <small class="text-muted"><?= htmlspecialchars($r['username'] ?? '') ?></small>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-nowrap">
            <?php if ($canManageEmp): ?>
              <a href="employees_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline-secondary btn-sm">Edit</a>
            <?php endif; ?>

            <?php if ($canManageUser): ?>
              <?php if ($r['user_id']): ?>
                <a href="/identity/users_form.php?id=<?= (int)$r['user_id'] ?>"
                   class="btn btn-outline-primary btn-sm">Edit User</a>
              <?php else: ?>
                <a href="/identity/users_form.php?from_employee_id=<?= (int)$r['id'] ?>"
                   class="btn btn-primary btn-sm">Create User</a>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($canManageUser): ?>
              <a target="_blank"
                 href="/api/iam_provisioning.php?action=preview&employee_id=<?= (int)$r['id'] ?>"
                 class="btn btn-outline-dark btn-sm">Preview Access</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
if (!empty($LAYOUT_DIR) && is_file($LAYOUT_DIR . '/layout_end.php')) include $LAYOUT_DIR . '/layout_end.php';
else echo '</div></body></html>';
