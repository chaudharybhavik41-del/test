<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.material.subcategory.view');

$pdo = db();
$category_id = (int)($_GET['category_id'] ?? 0);
$cat = null;

if ($category_id) {
  $st=$pdo->prepare("SELECT id, name FROM material_categories WHERE id=?");
  $st->execute([$category_id]);
  $cat=$st->fetch();
}

$bind=[]; 
$sql="SELECT sc.id, sc.code, sc.name, sc.prefix, sc.status, c.name AS cat_name
      FROM material_subcategories sc JOIN material_categories c ON c.id=sc.category_id";
if ($category_id) { $sql.=" WHERE sc.category_id=?"; $bind[]=$category_id; }
$sql.=" ORDER BY c.name, sc.name";
$st=$pdo->prepare($sql); $st->execute($bind); $rows=$st->fetchAll();
$canManage = has_permission('master.material.subcategory.manage');

$page_title = 'Material Subcategories';
include __DIR__ . '/../ui/layout_start.php';
?>
<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
      <li class="breadcrumb-item"><a href="categories_list.php">Material Categories</a></li>
      <li class="breadcrumb-item active" aria-current="page">Subcategories<?= $cat ? ' — '.htmlspecialchars($cat['name']) : '' ?></li>
    </ol>
  </nav>
  <div>
    <?php if ($canManage): ?>
      <a class="btn btn-primary btn-sm" href="subcategories_form.php<?= $category_id ? '?category_id='.$category_id : '' ?>">
        <i class="bi bi-plus-lg me-1"></i> New
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Category</th>
            <th style="width:120px;">Code</th>
            <th>Name</th>
            <th style="width:120px;">Prefix</th>
            <th style="width:120px;">Status</th>
            <th style="width:160px;" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['cat_name']) ?></td>
            <td><code><?= htmlspecialchars($r['code']) ?></code></td>
            <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
            <td><code><?= htmlspecialchars($r['prefix']) ?></code></td>
            <td>
              <span class="badge <?= $r['status']==='active' ? 'bg-success-subtle text-success-emphasis border':'bg-secondary-subtle text-secondary-emphasis border' ?>">
                <?= ucfirst($r['status']) ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ($canManage): ?>
                <div class="btn-group btn-group-sm">
                  <a class="btn btn-light" href="subcategories_form.php?id=<?= (int)$r['id'] ?>" title="Edit"><i class="bi bi-pencil-square"></i></a>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No records</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>