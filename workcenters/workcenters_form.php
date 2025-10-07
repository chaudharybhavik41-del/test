<?php
declare(strict_types=1);
/** PATH: /public_html/workcenters/workcenters_form.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('workcenters.manage');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$types = ['cutting','drilling','welding','blasting','painting','assembly','inspection','other'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id = (int)($_POST['id'] ?? 0);
  $code = trim($_POST['code'] ?? '');
  $name = trim($_POST['name'] ?? '');
  $type = in_array($_POST['type'] ?? 'other', $types, true) ? $_POST['type'] : 'other';
  $capacity_per_shift = ($_POST['capacity_per_shift'] ?? '') !== '' ? (float)$_POST['capacity_per_shift'] : null;
  $active = isset($_POST['active']) ? 1 : 0;

  if ($code==='') $errors[] = 'Code is required.';
  if ($name==='') $errors[] = 'Name is required.';

  if (!$errors) {
    if ($id > 0) {
      $stmt = $pdo->prepare("UPDATE work_centers SET code=?, name=?, type=?, capacity_per_shift=?, active=? WHERE id=?");
      $stmt->execute([$code,$name,$type,$capacity_per_shift,$active,$id]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO work_centers (code,name,type,capacity_per_shift,active) VALUES (?,?,?,?,?)");
      $stmt->execute([$code,$name,$type,$capacity_per_shift,$active]);
      $id = (int)$pdo->lastInsertId();
    }
    header('Location: workcenters_list.php');
    exit;
  }
}

$row = null;
if ($id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM work_centers WHERE id=?");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><?= $id ? 'Edit Work Center' : 'New Work Center' ?></h1>
    <a href="workcenters_list.php" class="btn btn-outline-secondary">Back</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <input type="hidden" name="id" value="<?=$id?>">
    <div class="col-md-3">
      <label class="form-label">Code</label>
      <input type="text" class="form-control" name="code" required value="<?=htmlspecialchars($row['code'] ?? '')?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Name</label>
      <input type="text" class="form-control" name="name" required value="<?=htmlspecialchars($row['name'] ?? '')?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Type</label>
      <select class="form-select" name="type">
        <?php foreach ($types as $t): ?>
          <option value="<?=$t?>" <?= (($row['type'] ?? 'other')===$t)?'selected':''?>><?=strtoupper($t)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Capacity per Shift</label>
      <input type="number" step="0.01" class="form-control" name="capacity_per_shift" value="<?=htmlspecialchars($row['capacity_per_shift'] ?? '')?>">
      <div class="form-text">Unit depends on process (e.g., m/hr, parts/shift).</div>
    </div>
    <div class="col-md-2">
      <label class="form-label d-block">Active</label>
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="active" value="1" <?= !empty($row) ? ((int)$row['active']?'checked':'') : 'checked' ?>>
        <span class="ms-1">Active</span>
      </div>
    </div>
    <div class="col-12"><button class="btn btn-primary">Save</button></div>
  </form>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
