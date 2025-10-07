<?php
declare(strict_types=1);
/** PATH: /public_html/machines/types_list.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('machines.manage');

$pdo = db();

// Detect column name for the short type code
$codeCol = $pdo->query("SHOW COLUMNS FROM machine_types LIKE 'machine_code'")->fetch() ? 'machine_code' : 'code';

$cat = (int)($_GET['category_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$cats = $pdo->query("SELECT id, CONCAT(prefix,' - ',name) AS label FROM machine_categories ORDER BY prefix")->fetchAll(PDO::FETCH_KEY_PAIR);

$w = [];
$p = [];
if ($cat) { $w[] = "t.category_id = ?"; $p[] = $cat; }
if ($q !== '') {
  // Keep comparisons within same table + parameters to avoid collation conflicts
  $w[] = "(t.$codeCol LIKE CONCAT('%', ?, '%') OR t.name LIKE CONCAT('%', ?, '%'))";
  array_push($p, $q, $q);
}
$where = $w ? "WHERE ".implode(" AND ", $w) : "";

$sql = "SELECT t.id, t.$codeCol AS type_code, t.name, c.prefix, c.name AS cat_name
        FROM machine_types t
        JOIN machine_categories c ON c.id=t.category_id
        $where
        ORDER BY c.prefix, t.$codeCol";
$stmt = $pdo->prepare($sql);
$stmt->execute($p);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0">Machine Types</h1>
  <div class="d-flex gap-2">
    <a href="types_form.php" class="btn btn-primary btn-sm">Add Type</a>
    <a href="machines_list.php" class="btn btn-primary btn-sm">Manage Machines</a>
    <a href="categories_list.php" class="btn btn-outline-secondary btn-sm">Manage Categories</a>
  </div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-4">
    <select class="form-select" name="category_id" onchange="this.form.submit()">
      <option value="0">All Categories</option>
      <?php foreach ($cats as $cid=>$lab): ?>
        <option value="<?=$cid?>" <?=$cid===$cat?'selected':''?>><?=htmlspecialchars($lab)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <input class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search code/name">
  </div>
  <div class="col-md-2 d-grid">
    <button class="btn btn-outline-secondary">Filter</button>
  </div>
</form>

<div class="table-responsive">
<table class="table table-hover align-middle">
  <thead class="table-light">
    <tr>
      <th style="width:120px;">Code</th>
      <th>Type Name</th>
      <th>Category</th>
      <th class="text-end" style="width:120px;">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="fw-semibold"><?=htmlspecialchars($r['type_code'])?></td>
      <td><?=htmlspecialchars($r['name'])?></td>
      <td><?=htmlspecialchars($r['prefix'])?> — <small class="text-muted"><?=htmlspecialchars($r['cat_name'])?></small></td>
      <td class="text-end">
        <a class="btn btn-sm btn-outline-secondary" href="types_form.php?id=<?=$r['id']?>">Edit</a>
      </td>
    </tr>
    <?php endforeach; if(!$rows): ?>
      <tr><td colspan="4" class="text-muted">No types yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
