<?php
/** PATH: /public_html/org/departments_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('org.department.manage');


// Permission-derived UI flags
$canManage = has_permission('org.department.manage');
$pdo = db();

$cols = $pdo->query("SHOW COLUMNS FROM departments")->fetchAll(PDO::FETCH_COLUMN);
$hasCode     = in_array('code', $cols, true);
$hasStatus   = in_array('status', $cols, true);
$hasParent   = in_array('parent_id', $cols, true);
$hasCreated  = in_array('created_at', $cols, true);
$hasUpdated  = in_array('updated_at', $cols, true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $id > 0;

$dept = [
  'name'      => '',
  'code'      => $hasCode ? '' : null,
  'status'    => $hasStatus ? 'active' : null,
  'parent_id' => $hasParent ? null : null,
];

if ($editing) {
  $sel = ['id','name'];
  if ($hasCode)   $sel[] = 'code';
  if ($hasStatus) $sel[] = 'status';
  if ($hasParent) $sel[] = 'parent_id';

  $st = $pdo->prepare("SELECT ".implode(',', $sel)." FROM departments WHERE id = ?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); exit('Department not found.'); }
  $dept = array_merge($dept, $row);
}

/** Load parents options if supported */
$parents = [];
if ($hasParent) {
  $st = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC");
  $parents = $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Helpers */
function slugify(string $s): string {
  $s = trim($s);
  $s = str_replace(['/', '-', ' '], '_', $s);
  $s = preg_replace('/[^a-zA-Z0-9_]/', '', $s);
  $s = preg_replace('/_+/', '_', $s);
  return strtolower($s ?: 'dept');
}

/** Handle POST */
$errors = [];
$okMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // if (function_exists('csrf_validate_or_die')) csrf_validate_or_die();

  $name      = trim((string)($_POST['name'] ?? ''));
  $code      = $hasCode ? trim((string)($_POST['code'] ?? '')) : null;
  $status    = $hasStatus ? (($_POST['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive') : null;
  $parent_id = $hasParent ? (($_POST['parent_id'] ?? '') === '' ? null : (int)$_POST['parent_id']) : null;

  if ($name === '') $errors[] = 'Name is required.';
  if ($hasParent && $parent_id !== null && $editing && $parent_id === $id) $errors[] = 'A department cannot be its own parent.';

  if ($hasCode) {
    if ($code === '') $code = slugify($name);
    $sqlU = "SELECT id FROM departments WHERE code = ?";
    $parU = [$code];
    if ($editing) { $sqlU .= " AND id <> ?"; $parU[] = $id; }
    $stU = $pdo->prepare($sqlU);
    $stU->execute($parU);
    if ($stU->fetch(PDO::FETCH_ASSOC)) $errors[] = 'Code already exists. Choose another.';
  }

  if (!$errors) {
    if ($editing) {
      $sets = ['name = ?']; $vals = [$name];
      if ($hasCode)   { $sets[] = 'code = ?';      $vals[] = $code; }
      if ($hasStatus) { $sets[] = 'status = ?';    $vals[] = $status; }
      if ($hasParent) { $sets[] = 'parent_id = ?'; $vals[] = $parent_id; }
      if ($hasUpdated){ $sets[] = 'updated_at = NOW()'; }

      $vals[] = $id;
      $sql = "UPDATE departments SET ".implode(', ', $sets)." WHERE id = ?";
      $pdo->prepare($sql)->execute($vals);
      $okMsg = 'Department updated.';
    } else {
      $colsIns = ['name']; $qs=['?']; $vals = [$name];
      if ($hasCode)   { $colsIns[]='code';      $qs[]='?'; $vals[]=$code; }
      if ($hasStatus) { $colsIns[]='status';    $qs[]='?'; $vals[]=$status ?? 'active'; }
      if ($hasParent) { $colsIns[]='parent_id'; $qs[]='?'; $vals[]=$parent_id; }
      if ($hasCreated){ $colsIns[]='created_at';$qs[]='NOW()'; }
      if ($hasUpdated){ $colsIns[]='updated_at';$qs[]='NOW()'; }

      $sql = "INSERT INTO departments (".implode(',', $colsIns).") VALUES (".implode(',', $qs).")";
      $pdo->prepare($sql)->execute($vals);
      $id = (int)$pdo->lastInsertId();
      $editing = true;
      $okMsg = 'Department created.';
    }

    // reload
    $sel = ['id','name'];
    if ($hasCode)   $sel[] = 'code';
    if ($hasStatus) $sel[] = 'status';
    if ($hasParent) $sel[] = 'parent_id';
    $st = $pdo->prepare("SELECT ".implode(',', $sel)." FROM departments WHERE id = ?");
    $st->execute([$id]);
    $dept = $st->fetch(PDO::FETCH_ASSOC) ?: $dept;
  }
}

$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = $editing ? 'Edit Department' : 'Add Department';
$ACTIVE_MENU = 'org.departments';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><?= $editing ? 'Edit Department' : 'Add Department' ?></h1>
  <a class="btn btn-outline-secondary" href="/org/departments_list.php">Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php elseif ($okMsg): ?>
  <div class="alert alert-success"><?= htmlspecialchars($okMsg) ?></div>
<?php endif; ?>

<form method="post" class="row g-3">
  <div class="col-md-6">
    <label class="form-label">Name</label>
    <input name="name" class="form-control" required value="<?= htmlspecialchars((string)$dept['name']) ?>">
  </div>

  <?php if ($hasCode): ?>
  <div class="col-md-6">
    <label class="form-label">Code (unique)</label>
    <input name="code" class="form-control" value="<?= htmlspecialchars((string)($dept['code'] ?? '')) ?>">
    <div class="form-text">Letters/numbers/underscores only; auto-generated if blank.</div>
  </div>
  <?php endif; ?>

  <?php if ($hasStatus): ?>
  <div class="col-md-4">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <option value="active"   <?= ($dept['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= ($dept['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <?php endif; ?>

  <?php if ($hasParent): ?>
  <div class="col-md-8">
    <label class="form-label">Parent Department</label>
    <select name="parent_id" class="form-select">
      <option value="">— None —</option>
      <?php foreach ($parents as $p): if ($editing && (int)$p['id'] === (int)$id) continue; ?>
        <option value="<?= (int)$p['id'] ?>" <?= ((int)($dept['parent_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$p['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="col-12">
    <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Department' ?></button>
  </div>
</form>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
