<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.material.category.view');

$pdo = db();
$rows = $pdo->query("SELECT id, code, name, prefix, status FROM material_categories ORDER BY name")->fetchAll();
$canManage = has_permission('master.material.category.manage');

$page_title = 'Material Categories';
include __DIR__ . '/../ui/layout_start.php';
?>
<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
      <li class="breadcrumb-item active" aria-current="page">Material Categories</li>
    </ol>
  </nav>
  <div>
    <?php if ($canManage): ?>
      <a class="btn btn-primary btn-sm" href="categories_form.php"><i class="bi bi-plus-lg me-1"></i> New</a>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:120px;">Code</th>
            <th>Name</th>
            <th style="width:120px;">Prefix</th>
            <th style="width:120px;">Status</th>
            <th style="width:220px;" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><code><?= htmlspecialchars($r['code']) ?></code></td>
            <td class="fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
            <td><code><?= htmlspecialchars($r['prefix']) ?></code></td>
            <td>
              <span class="badge <?= $r['status']==='active' ? 'bg-success-subtle text-success-emphasis border' : 'bg-secondary-subtle text-secondary-emphasis border' ?>">
                <?= ucfirst($r['status']) ?>
              </span>
            </td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <a class="btn btn-light" href="subcategories_list.php?category_id=<?= (int)$r['id'] ?>" title="View subcategories"><i class="bi bi-diagram-3"></i></a>
                <?php if ($canManage): ?>
                  <a class="btn btn-light" href="categories_form.php?id=<?= (int)$r['id'] ?>" title="Edit"><i class="bi bi-pencil-square"></i></a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No records</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>