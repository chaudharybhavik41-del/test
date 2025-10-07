<?php
declare(strict_types=1);
/** PATH: /public_html/machines/types_form.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();
require_permission('machines.manage');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

$cats = $pdo->query("SELECT id, CONCAT(prefix,' - ',name) AS label FROM machine_categories ORDER BY prefix")->fetchAll(PDO::FETCH_KEY_PAIR);

$row = ['category_id'=>'','machine_code'=>'','name'=>'','notes'=>''];
if ($editing) {
  $stmt = $pdo->prepare("SELECT * FROM machine_types WHERE id=?");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require_token();
  $category_id = (int)($_POST['category_id'] ?? 0);
  $machine_code = strtoupper(trim($_POST['machine_code'] ?? ''));
  $name = trim($_POST['name'] ?? '');
  $notes = trim($_POST['notes'] ?? '');
  if (!$category_id || $machine_code==='' || $name==='') { $err = "Category, Code and Name are required."; }
  else {
    try {
      if ($editing) {
        $u = $pdo->prepare("UPDATE machine_types SET category_id=?, machine_code=?, name=?, notes=? WHERE id=?");
        $u->execute([$category_id,$machine_code,$name,$notes,$id]);
        } else {
        $i = $pdo->prepare("INSERT INTO machine_types(category_id,machine_code,name,notes) VALUES(?,?,?,?)");
        $i->execute([$category_id,$machine_code,$name,$notes]);
        $id = (int)$pdo->lastInsertId();
        }
      header("Location: types_list.php?category_id=".$category_id);
      exit;
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0"><?=$editing?'Edit':'Add'?> Machine Type</h1>
  <a href="types_list.php" class="btn btn-light btn-sm">Back</a>
</div>

<?php if (!empty($err)): ?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_hidden_input() ?>
  <div class="col-md-5">
    <label class="form-label">Category</label>
    <select name="category_id" class="form-select" required>
      <option value="">-- Select --</option>
      <?php foreach ($cats as $cid=>$lab): ?>
        <option value="<?=$cid?>" <?=$cid==($row['category_id']??0)?'selected':''?>><?=htmlspecialchars($lab)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Type Code (2–3 letters)</label>
    <input name="machine_code" class="form-control" maxlength="5" required value="<?=htmlspecialchars((string)$row['machine_code'])?>">
    <div class="form-text">Example: CNC, PUG, GEN</div>
  </div>
  <div class="col-md-4">
    <label class="form-label">Type Name</label>
    <input name="name" class="form-control" required value="<?=htmlspecialchars((string)$row['name'])?>">
  </div>
  <div class="col-12">
    <label class="form-label">Notes</label>
    <textarea name="notes" rows="2" class="form-control"><?=htmlspecialchars((string)$row['notes'])?></textarea>
  </div>
  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary">Save</button>
    <a href="types_list.php" class="btn btn-light">Cancel</a>
  </div>
</form>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>