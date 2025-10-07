<?php
declare(strict_types=1);
/** PATH: /public_html/machines/categories_list.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('machines.manage');

$pdo = db();

$q = trim($_GET['q'] ?? '');

// Build WHERE dynamically (avoid ?='' which causes collation coercion)
$wheres = [];
$params = [];

if ($q !== '') {
  // Collation-safe comparisons
  $wheres[] = "(
    prefix COLLATE utf8mb4_general_ci LIKE CONCAT('%', CAST(? AS CHAR) COLLATE utf8mb4_general_ci, '%')
    OR name COLLATE utf8mb4_general_ci LIKE CONCAT('%', CAST(? AS CHAR) COLLATE utf8mb4_general_ci, '%')
  )";
  array_push($params, $q, $q);
}

$whereSql = $wheres ? ('WHERE '.implode(' AND ', $wheres)) : '';

$sql = "SELECT id, prefix, name, created_at
        FROM machine_categories
        $whereSql
        ORDER BY prefix";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0">Machine Categories</h1>
  <div class="d-flex gap-2">
    <a href="categories_form.php" class="btn btn-primary btn-sm">Add Category</a>
    <a href="types_list.php" class="btn btn-outline-secondary btn-sm">Manage Types</a>
  </div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-4">
    <input class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search prefix/name">
  </div>
  <div class="col-md-2 d-grid">
    <button class="btn btn-outline-secondary">Search</button>
  </div>
</form>

<div class="table-responsive">
<table class="table table-hover align-middle">
  <thead class="table-light">
    <tr>
      <th style="width:120px;">Prefix</th>
      <th>Name</th>
      <th>Created</th>
      <th class="text-end" style="width:120px;">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="fw-semibold"><?=htmlspecialchars($r['prefix'])?></td>
      <td><?=htmlspecialchars($r['name'])?></td>
      <td><small class="text-muted"><?=htmlspecialchars($r['created_at'])?></small></td>
      <td class="text-end">
        <a class="btn btn-sm btn-outline-secondary" href="categories_form.php?id=<?=$r['id']?>">Edit</a>
      </td>
    </tr>
    <?php endforeach; if(!$rows): ?>
      <tr><td colspan="4" class="text-muted">No categories yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
