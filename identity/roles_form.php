<?php
/** PATH: /public_html/identity/roles_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('core.role.manage');

$pdo = db();

/* ---------- Detect columns so this works with older/newer schemas ---------- */
$roleCols = $pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_COLUMN);
$hasCode     = in_array('code', $roleCols, true);
$hasStatus   = in_array('status', $roleCols, true);
$hasDesc     = in_array('description', $roleCols, true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $id > 0;

$role = [
  'name'        => '',
  'code'        => $hasCode ? '' : null,
  'status'      => $hasStatus ? 'active' : null,
  'description' => $hasDesc ? '' : null,
];

if ($editing) {
    $sel = ['id', 'name'];
    if ($hasCode)   $sel[] = 'code';
    if ($hasStatus) $sel[] = 'status';
    if ($hasDesc)   $sel[] = 'description';

    $sql = "SELECT ".implode(',', $sel)." FROM roles WHERE id = ?";
    $st  = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit('Role not found.'); }
    $role = array_merge($role, $row);
}

/* ---- Load ALL permissions, grouped + sorted by module ---- */
$allPerms = $pdo->query("
  SELECT id, code, name, COALESCE(module,'other') AS module
  FROM permissions
  ORDER BY module ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$permsByModule = [];
foreach ($allPerms as $p) {
  $permsByModule[$p['module']][] = $p;
}

/* ---- Load currently assigned permissions for this role ---- */
$assignedPermIds = [];
if ($editing) {
  $st = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
  $st->execute([$id]);
  $assignedPermIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$errors = [];
$okMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim((string)($_POST['name'] ?? ''));
    $code   = $hasCode   ? trim((string)($_POST['code'] ?? '')) : null;
    $status = $hasStatus ? (($_POST['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive') : null;
    $desc   = $hasDesc   ? trim((string)($_POST['description'] ?? '')) : null;

    $permIds = array_map('intval', (array)($_POST['permissions'] ?? []));

    if ($name === '') $errors[] = 'Role name is required.';
    if ($hasCode && $code === '') $errors[] = 'Role code is required.';

    // Uniqueness checks
    if (!$errors) {
      if ($hasCode) {
        $sql = "SELECT id FROM roles WHERE code = ?" . ($editing ? " AND id <> ?" : "");
        $st  = $pdo->prepare($sql);
        $st->execute($editing ? [$code, $id] : [$code]);
        if ($st->fetch()) $errors[] = 'Role code already exists.';
      } else {
        // Fallback uniqueness by name if code does not exist
        $sql = "SELECT id FROM roles WHERE name = ?" . ($editing ? " AND id <> ?" : "");
        $st  = $pdo->prepare($sql);
        $st->execute($editing ? [$name, $id] : [$name]);
        if ($st->fetch()) $errors[] = 'Role name already exists.';
      }
    }

    if (!$errors) {
      $pdo->beginTransaction();
      try {
        if ($editing) {
          $fields = ['name = ?'];
          $params = [$name];

          if ($hasCode)   { $fields[] = 'code = ?';        $params[] = $code; }
          if ($hasStatus) { $fields[] = 'status = ?';      $params[] = $status; }
          if ($hasDesc)   { $fields[] = 'description = ?'; $params[] = $desc; }

          $fields[] = 'updated_at = NOW()';
          $params[] = $id;

          $sql = "UPDATE roles SET ".implode(', ', $fields)." WHERE id = ?";
          $pdo->prepare($sql)->execute($params);

          // Replace role-permissions mapping
          $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$id]);
          if ($permIds) {
            $ins = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            foreach ($permIds as $pid) { $ins->execute([$id, $pid]); }
          }

          $okMsg = 'Role updated.';
        } else {
          $cols = ['name', 'created_at', 'updated_at'];
          $vals = ['?', 'NOW()', 'NOW()'];
          $pars = [$name];

          if ($hasCode)   { array_unshift($cols, 'code');        array_unshift($vals, '?'); $pars = array_merge([$code], $pars); }
          if ($hasStatus) { $cols[] = 'status';                  $vals[] = '?';              $pars[] = $status; }
          if ($hasDesc)   { $cols[] = 'description';             $vals[] = '?';              $pars[] = $desc; }

          $sql = "INSERT INTO roles (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
          $pdo->prepare($sql)->execute($pars);
          $id = (int)$pdo->lastInsertId();

          if ($permIds) {
            $ins = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            foreach ($permIds as $pid) { $ins->execute([$id, $pid]); }
          }

          $okMsg = 'Role created.';
          $editing = true;
        }

        $pdo->commit();

        // Refresh assigned set for proper re-render
        $st = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
        $st->execute([$id]);
        $assignedPermIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        // Optional redirect to prevent POST resubmit:
        header('Location: roles_form.php?id=' . $id . '&saved=1'); exit;
      } catch (Throwable $e) {
        $pdo->rollBack();
        $errors[] = 'Save failed: ' . $e->getMessage();
      }
    }
}

/* ---- Layout ---- */
$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = $editing ? 'Edit Role' : 'Add Role';
$ACTIVE_MENU = 'identity.roles';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><?= $editing ? 'Edit Role' : 'Add Role' ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="/identity/roles_list.php">Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php elseif (isset($_GET['saved'])): ?>
  <div class="alert alert-success">Saved.</div>
<?php endif; ?>

<form method="post" class="row g-3">

  <div class="col-md-4">
    <label class="form-label">Name *</label>
    <input name="name" class="form-control" required value="<?= htmlspecialchars((string)$role['name']) ?>">
  </div>

  <?php if ($hasCode): ?>
    <div class="col-md-4">
      <label class="form-label">Code *</label>
      <input name="code" class="form-control" required value="<?= htmlspecialchars((string)$role['code']) ?>">
    </div>
  <?php endif; ?>

  <?php if ($hasStatus): ?>
    <div class="col-md-4">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="active"   <?= $role['status'] === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $role['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
  <?php endif; ?>

  <?php if ($hasDesc): ?>
    <div class="col-12">
      <label class="form-label">Description</label>
      <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars((string)$role['description']) ?></textarea>
    </div>
  <?php endif; ?>

  <div class="col-12">
    <div class="d-flex justify-content-between align-items-center">
      <label class="form-label mb-0">Permissions</label>
      <div class="input-group input-group-sm" style="width: 320px;">
        <span class="input-group-text">Filter</span>
        <input class="form-control" id="permFilter" placeholder="type to filter...">
      </div>
    </div>

    <?php if (!$allPerms): ?>
      <div class="text-muted mt-2">No permissions defined yet.</div>
    <?php else: ?>
      <div class="mt-2">
        <?php foreach ($permsByModule as $module => $items): ?>
          <div class="border rounded mb-3 p-2 perm-module" data-module="<?= htmlspecialchars($module) ?>">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div class="fw-bold small text-uppercase text-muted"><?= htmlspecialchars($module) ?></div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chkall-<?= htmlspecialchars($module) ?>"
                       onclick="toggleModule(this, '<?= htmlspecialchars($module) ?>')">
                <label class="form-check-label small" for="chkall-<?= htmlspecialchars($module) ?>">Select all in this module</label>
              </div>
            </div>
            <?php foreach ($items as $p): 
              $pid = (int)$p['id'];
              $checked = in_array($pid, $assignedPermIds, true) ? 'checked' : '';
            ?>
              <div class="form-check perm-item" data-text="<?= htmlspecialchars(strtolower($p['code'].' '.$p['name'].' '.$module)) ?>">
                <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $pid ?>" id="perm<?= $pid ?>" <?= $checked ?>>
                <label class="form-check-label" for="perm<?= $pid ?>">
                  <code><?= htmlspecialchars($p['code']) ?></code> — <?= htmlspecialchars($p['name']) ?>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-12">
    <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Role' ?></button>
  </div>
</form>

<script>
const filterInput = document.getElementById('permFilter');
if (filterInput) {
  filterInput.addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.perm-item').forEach(el => {
      const t = el.getAttribute('data-text') || '';
      el.style.display = t.includes(q) ? '' : 'none';
    });
  });
}
function toggleModule(box, module){
  document.querySelectorAll('.perm-module[data-module="'+module+'"] input[type=checkbox][name="permissions[]"]').forEach(cb => {
    cb.checked = box.checked;
  });
}
</script>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
