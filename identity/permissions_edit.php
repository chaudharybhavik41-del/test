<?php
/** PATH: /public_html/identity/permissions_edit.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('core.permission.manage');

$pdo = db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $id > 0;

$perm = ['code'=>'','name'=>'','module'=>''];

if ($editing) {
  $st = $pdo->prepare("SELECT id, code, name, module FROM permissions WHERE id=?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); exit('Permission not found'); }
  $perm = array_merge($perm, $row);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code   = trim((string)($_POST['code'] ?? ''));
  $name   = trim((string)($_POST['name'] ?? ''));
  $module = trim((string)($_POST['module'] ?? ''));

  if ($code==='') $errors[] = 'Code is required.';
  if ($name==='') $errors[] = 'Name is required.';

  if (!$errors) {
    // Unique code
    $sql = "SELECT id FROM permissions WHERE code=?" . ($editing ? " AND id<>?" : "");
    $st  = $pdo->prepare($sql);
    $st->execute($editing ? [$code, $id] : [$code]);
    if ($st->fetch()) $errors[] = 'Code already exists.';
  }

  if (!$errors) {
    if ($editing) {
      $st = $pdo->prepare("UPDATE permissions SET code=?, name=?, module=?, updated_at=NOW() WHERE id=?");
      $st->execute([$code, $name, $module ?: null, $id]);
      header('Location: /identity/permissions_list.php?saved=1'); exit;
    } else {
      $st = $pdo->prepare("INSERT INTO permissions (code,name,module,created_at,updated_at) VALUES (?,?,?,?,?)");
      $now = date('Y-m-d H:i:s');
      $st->execute([$code, $name, $module ?: null, $now, $now]);
      header('Location: /identity/permissions_list.php?saved=1'); exit;
    }
  } else {
    $perm['code']=$code; $perm['name']=$name; $perm['module']=$module;
  }
}

/* Layout */
$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = $editing ? 'Edit Permission' : 'Add Permission';
$ACTIVE_MENU = 'identity.permissions';
require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0"><?= $editing ? 'Edit Permission' : 'Add Permission' ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="/identity/permissions_list.php">Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php elseif (isset($_GET['saved'])): ?>
  <div class="alert alert-success">Saved.</div>
<?php endif; ?>

<form method="post" class="row g-3">
  <div class="col-md-4">
    <label class="form-label">Code *</label>
    <input name="code" class="form-control" required value="<?= htmlspecialchars($perm['code']) ?>">
    <div class="form-text">Example: <code>hr.employee.view</code></div>
  </div>
  <div class="col-md-4">
    <label class="form-label">Name *</label>
    <input name="name" class="form-control" required value="<?= htmlspecialchars($perm['name']) ?>">
  </div>
  <div class="col-md-4">
    <label class="form-label">Module</label>
    <input name="module" class="form-control" value="<?= htmlspecialchars((string)$perm['module']) ?>" placeholder="e.g. hr, identity, crm">
  </div>

  <div class="col-12">
    <button class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Permission' ?></button>
  </div>
</form>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
