<?php
/** PATH: /public_html/items/items_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('materials.item.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

/** Optional filters */
$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');

$where = [];
$args  = [];
if ($q !== '') {
  $where[] = "(i.name LIKE CONCAT('%', ?, '%') OR i.material_code LIKE CONCAT('%', ?, '%'))";
  $args[] = $q; $args[] = $q;
}
if ($status !== '') {
  $where[] = "i.status = ?";
  $args[] = $status;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
  SELECT
    i.id, i.material_code, i.name, i.status,
    GROUP_CONCAT(DISTINCT m.name ORDER BY m.name SEPARATOR ', ') AS makes
  FROM items i
  LEFT JOIN item_makes im ON im.item_id = i.id
  LEFT JOIN makes m ON m.id = im.make_id
  $whereSql
  GROUP BY i.id
  ORDER BY i.id DESC
  LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* UI helpers */
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
      <li class="breadcrumb-item active" aria-current="page">Items</li>
    </ol>
  </nav>
  <div class="d-flex gap-2">
    <?php if (has_permission('materials.item.manage')): ?>
      <a class="btn btn-primary btn-sm" href="/items/items_form.php"><i class="bi bi-plus-lg"></i> Add Item</a>
    <?php endif; ?>
  </div>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-12 col-md-6">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search code or name">
        </div>
      </div>
      <div class="col-6 col-md-3">
        <select name="status" class="form-select">
          <option value="" <?= $status===''?'selected':'' ?>>All statuses</option>
          <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-6 col-md-3 text-md-end">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel me-1"></i> Apply</button>
        <a class="btn btn-light" href="/items/items_list.php" title="Reset"><i class="bi bi-x-circle"></i></a>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:160px;">Material Code</th>
            <th>Name</th>
            <th>Makes</th>
            <th style="width:120px;">Status</th>
            <th style="width:180px;" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($r['material_code']) ?></td>
              <td><?= htmlspecialchars($r['name']) ?></td>
              <td><span class="text-muted-sm"><?= htmlspecialchars($r['makes'] ?: '—') ?></span></td>
              <td><?= ui_status((string)$r['status']) ?></td>
              <td class="text-end">
                <?= ui_row_actions([
                  'view'   => '/items/items_form.php?id='.(int)$r['id'],
                  'edit'   => has_permission('materials.item.manage') ? '/items/items_form.php?id='.(int)$r['id'] : null,
                  'delete' => ($r['status']==='active' && has_permission('materials.item.manage'))
                              ? '/items/items_delete.php?id='.(int)$r['id'] : null,
                  'extra'  => [['icon'=>'paperclip','label'=>'Attachments','href'=>'/attachments/panel.php?entity=item&id='.(int)$r['id']]],
                ]) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No items found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../ui/layout_end.php';