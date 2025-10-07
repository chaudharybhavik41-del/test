<?php
declare(strict_types=1);
/** PATH: /public_html/processes/processes_form.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('processes.manage');

$pdo = db();
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$kinds = ['production','inspection','testing','transfer','setup'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $code = trim($_POST['code'] ?? '');
  $name = trim($_POST['name'] ?? '');
  $kind = in_array($_POST['kind'] ?? 'production', $kinds, true) ? $_POST['kind'] : 'production';
  $default_setup_min = ($_POST['default_setup_min'] ?? '') !== '' ? (float)$_POST['default_setup_min'] : null;
  $default_run_min   = ($_POST['default_run_min']   ?? '') !== '' ? (float)$_POST['default_run_min']   : null;
  $requires_machine  = isset($_POST['requires_machine']) ? 1 : 0;
  $skill_level       = ($_POST['skill_level'] ?? '') !== '' ? (int)$_POST['skill_level'] : null;
  $active            = isset($_POST['active']) ? 1 : 0;

  if ($code === '') $errors[] = 'Code is required.';
  if ($name === '') $errors[] = 'Name is required.';

  if (!$errors) {
    if ($id > 0) {
      $sql = "UPDATE processes SET code=?, name=?, kind=?, default_setup_min=?, default_run_min=?, requires_machine=?, skill_level=?, active=? WHERE id=?";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$code,$name,$kind,$default_setup_min,$default_run_min,$requires_machine,$skill_level,$active,$id]);
    } else {
      $sql = "INSERT INTO processes (code,name,kind,default_setup_min,default_run_min,requires_machine,skill_level,active) VALUES (?,?,?,?,?,?,?,?)";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$code,$name,$kind,$default_setup_min,$default_run_min,$requires_machine,$skill_level,$active]);
      $id = (int)$pdo->lastInsertId();
    }
    header('Location: processes_list.php');
    exit;
  }
}

$row = null;
if ($id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM processes WHERE id=?");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><?= $id ? 'Edit Process' : 'New Process' ?></h1>
    <div>
      <a href="processes_list.php" class="btn btn-outline-secondary">Back</a>
    </div>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <input type="hidden" name="id" value="<?=$id?>">
    <div class="col-md-3">
      <label class="form-label">Code</label>
      <input type="text" name="code" class="form-control" required value="<?=htmlspecialchars($row['code'] ?? '')?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Name</label>
      <input type="text" name="name" class="form-control" required value="<?=htmlspecialchars($row['name'] ?? '')?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Kind</label>
      <select name="kind" class="form-select">
        <?php foreach ($kinds as $k): ?>
          <option value="<?=$k?>" <?= (($row['kind'] ?? 'production')===$k)?'selected':''?>><?=strtoupper($k)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Default Setup (min)</label>
      <input type="number" step="0.01" name="default_setup_min" class="form-control" value="<?=htmlspecialchars($row['default_setup_min'] ?? '')?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Default Run (min)</label>
      <input type="number" step="0.01" name="default_run_min" class="form-control" value="<?=htmlspecialchars($row['default_run_min'] ?? '')?>">
    </div>
    <div class="col-md-2">
      <label class="form-label d-block">Requires Machine</label>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="requires_machine" value="1" <?= !empty($row) ? ((int)$row['requires_machine']?'checked':'') : 'checked' ?>>
        <span class="ms-1">Yes</span>
      </div>
    </div>
    <div class="col-md-2">
      <label class="form-label">Skill Level</label>
      <input type="number" min="1" max="10" name="skill_level" class="form-control" value="<?=htmlspecialchars($row['skill_level'] ?? '')?>">
    </div>
    <div class="col-md-2">
      <label class="form-label d-block">Active</label>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="active" value="1" <?= !empty($row) ? ((int)$row['active']?'checked':'') : 'checked' ?>>
        <span class="ms-1">Active</span>
      </div>
    </div>

    <div class="col-12">
      <button class="btn btn-primary">Save</button>
    </div>
  </form>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
