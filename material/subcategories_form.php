<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.material.subcategory.manage');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$category_id = (int)($_GET['category_id'] ?? 0);
$row = null;

$cats = $pdo->query("SELECT id, name FROM material_categories WHERE status='active' ORDER BY name")->fetchAll();

if ($id) {
  $st=$pdo->prepare("SELECT * FROM material_subcategories WHERE id=?");
  $st->execute([$id]);
  $row=$st->fetch();
  if (!$row) { http_response_code(404); exit('Not found'); }
  $category_id = (int)$row['category_id'];
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf_or_die();
  $category_id = (int)($_POST['category_id'] ?? 0);
  $code   = strtoupper(trim($_POST['code'] ?? ''));
  $name   = trim($_POST['name'] ?? '');
  $prefix = strtoupper(trim($_POST['prefix'] ?? ''));
  $status = $_POST['status'] ?? 'active';

  if (!$category_id || $code===''||$name===''||$prefix==='') $err='All fields required';
  else {
    try {
      if ($id) {
        $up=$pdo->prepare("UPDATE material_subcategories SET category_id=?, code=?, name=?, prefix=?, status=? WHERE id=?");
        $up->execute([$category_id,$code,$name,$prefix,$status,$id]);
      } else {
        $in=$pdo->prepare("INSERT INTO material_subcategories (category_id,code,name,prefix,status) VALUES (?,?,?,?,?)");
        $in->execute([$category_id,$code,$name,$prefix,$status]);
      }
      header('Location: subcategories_list.php?category_id='.$category_id); exit;
    } catch (Throwable $e) { $err='Duplicate within category?'; }
  }
}
$val = fn($k,$d='') => htmlspecialchars($row[$k] ?? $d, ENT_QUOTES);

$page_title = ($id?'Edit':'New').' Subcategory';
include __DIR__ . '/../ui/layout_start.php';
?>
<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
      <li class="breadcrumb-item"><a href="categories_list.php">Material Categories</a></li>
      <li class="breadcrumb-item"><a href="subcategories_list.php<?= $category_id?'?category_id='.$category_id:'' ?>">Subcategories</a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= $id?'Edit':'New' ?></li>
    </ol>
  </nav>
  <a class="btn btn-light btn-sm" href="subcategories_list.php<?= $category_id?'?category_id='.$category_id:'' ?>">
    <i class="bi bi-arrow-left"></i> Back
  </a>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if (!empty($err)): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Category</label>
          <select class="form-select" name="category_id" required>
            <option value="">Choose...</option>
            <?php foreach($cats as $c): ?>
              <option value="<?=$c['id']?>" <?= $category_id===$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Code</label>
          <input class="form-control" name="code" value="<?= $val('code') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Prefix</label>
          <input class="form-control" name="prefix" value="<?= $val('prefix') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="active"   <?= $val('status','active')==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $val('status','active')==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
        <div class="col-md-9">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" value="<?= $val('name') ?>" required>
        </div>
      </div>

      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary"><?= $id?'Update':'Create' ?></button>
        <a class="btn btn-outline-secondary" href="subcategories_list.php<?= $category_id?'?category_id='.$category_id:'' ?>">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('input', e=>{
  if (e.target.name==='code'||e.target.name==='prefix') e.target.value=e.target.value.toUpperCase();
});
</script>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>