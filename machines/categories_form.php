<?php
declare(strict_types=1);
/** PATH: /public_html/machines/categories_form.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();
require_permission('machines.manage');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

$row = ['prefix'=>'','name'=>''];
if ($editing) {
  $stmt = $pdo->prepare("SELECT id, prefix, name FROM machine_categories WHERE id=?");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require_token();
  $prefix = strtoupper(trim($_POST['prefix'] ?? ''));
  $name   = trim($_POST['name'] ?? '');
  if ($prefix === '' || $name === '') {
    $err = "Prefix and Name are required.";
  } else {
    try {
      // Uniqueness check (collation-safe) — disallow duplicates except self when editing
      if ($editing) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM machine_categories WHERE prefix COLLATE utf8mb4_general_ci = CAST(? AS CHAR) COLLATE utf8mb4_general_ci AND id <> ?");
        $chk->execute([$prefix, $id]);
      } else {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM machine_categories WHERE prefix COLLATE utf8mb4_general_ci = CAST(? AS CHAR) COLLATE utf8mb4_general_ci");
        $chk->execute([$prefix]);
      }
      if ((int)$chk->fetchColumn() > 0) {
        $err = "Prefix \"$prefix\" already exists. Please choose a different prefix.";
      } else {
        if ($editing) {
          $u = $pdo->prepare("UPDATE machine_categories SET prefix=?, name=? WHERE id=?");
          $u->execute([$prefix,$name,$id]);
        } else {
          $i = $pdo->prepare("INSERT INTO machine_categories(prefix,name) VALUES(?,?)");
          $i->execute([$prefix,$name]);
          $id = (int)$pdo->lastInsertId();
        }
        header("Location: categories_list.php");
        exit;
      }
    } catch (Throwable $e) {
      // Catch any unexpected DB error (including unique constraint just in case)
      $err = "Save failed: " . $e->getMessage();
    }
  }
}

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0"><?=$editing?'Edit':'Add'?> Category</h1>
  <a href="categories_list.php" class="btn btn-light btn-sm">Back</a>
</div>

<?php if (!empty($err)): ?>
  <div class="alert alert-danger"><?=htmlspecialchars($err)?></div>
<?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_hidden_input() ?>
  <div class="col-md-3">
    <label class="form-label">Prefix (2–4 letters)</label>
    <input name="prefix" class="form-control" maxlength="6" required value="<?=htmlspecialchars((string)$row['prefix'])?>">
    <div class="form-text">Example: CUT, CRN, COMP</div>
  </div>
  <div class="col-md-6">
    <label class="form-label">Name</label>
    <input name="name" class="form-control" required value="<?=htmlspecialchars((string)$row['name'])?>">
  </div>
  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary">Save</button>
    <a href="categories_list.php" class="btn btn-light">Cancel</a>
  </div>
</form>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>