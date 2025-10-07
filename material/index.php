<?php
/** PATH: /public_html/material/index.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
// View permission for material taxonomy landing
require_permission('master.material.category.view');

$pdo = db();

// Load active categories
$cats = $pdo->query("
  SELECT id, code, name
  FROM material_categories
  WHERE status='active'
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Determine selected category
$selectedCatId = null;
if (!empty($cats)) {
    $requested = isset($_GET['cat_id']) ? (int)$_GET['cat_id'] : 0;
    $catIds = array_column($cats, 'id');
    $selectedCatId = $requested && in_array($requested, $catIds, true) ? $requested : (int)$cats[0]['id'];
}

// Load subcategories for the selected category
$subcats = [];
if ($selectedCatId) {
    $st = $pdo->prepare("
        SELECT id, code, name
        FROM material_subcategories
        WHERE status='active' AND category_id=?
        ORDER BY name
    ");
    $st->execute([$selectedCatId]);
    $subcats = $st->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = "Material Taxonomy";
include __DIR__ . '/../ui/layout_start.php';
?>

<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><?= h($pageTitle) ?></h1>
    <div class="btn-group">
      <?php if (has_permission('master.material.category.manage')): ?>
        <a class="btn btn-sm btn-primary" href="categories_list.php">
          <i class="bi bi-collection"></i> Manage Categories
        </a>
      <?php endif; ?>
      <?php if (has_permission('master.material.subcategory.manage')): ?>
        <a class="btn btn-sm btn-outline-primary" href="subcategories_list.php">
          <i class="bi bi-diagram-3"></i> Manage Subcategories
        </a>
      <?php endif; ?>
      <?php if (has_permission('materials.item.view')): ?>
        <a class="btn btn-sm btn-outline-secondary" href="../items/items_list.php">
          <i class="bi bi-box-seam"></i> Items
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($cats)): ?>
    <div class="alert alert-warning">
      No material categories found. <?php if (has_permission('master.material.category.manage')): ?>
        <a href="categories_form.php" class="alert-link">Create the first category</a>.
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card shadow-sm">
          <div class="card-header">
            <strong>Categories</strong>
          </div>
          <div class="list-group list-group-flush">
            <?php foreach ($cats as $c): ?>
              <a class="list-group-item list-group-item-action <?= $c['id']===$selectedCatId ? 'active' : '' ?>"
                 href="?cat_id=<?= (int)$c['id'] ?>">
                <div class="d-flex justify-content-between align-items-center">
                  <span><?= h($c['name']) ?></span>
                  <small class="<?= $c['id']===$selectedCatId ? 'text-white-50' : 'text-muted' ?>"><?= h($c['code']) ?></small>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Subcategories</strong>
            <?php if (has_permission('master.material.subcategory.manage') && $selectedCatId): ?>
              <a class="btn btn-sm btn-primary" href="subcategories_form.php?category_id=<?= (int)$selectedCatId ?>">
                <i class="bi bi-plus-circle"></i> New Subcategory
              </a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (empty($subcats)): ?>
              <p class="text-muted mb-0">No active subcategories found for this category.</p>
            <?php else: ?>
              <div class="row row-cols-1 row-cols-md-2 g-3">
                <?php foreach ($subcats as $s): ?>
                  <div class="col">
                    <div class="border rounded p-3 h-100">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <div class="fw-semibold"><?= h($s['name']) ?></div>
                          <div class="small text-muted"><?= h($s['code']) ?></div>
                        </div>
                        <?php if (has_permission('materials.item.view')): ?>
                          <a class="btn btn-sm btn-outline-secondary"
                             href="../items/items_list.php?cat_id=<?= (int)$selectedCatId ?>&subcat_id=<?= (int)$s['id'] ?>">
                            View Items
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>
