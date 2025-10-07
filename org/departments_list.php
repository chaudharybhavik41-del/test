<?php
/** PATH: /public_html/org/departments_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('org.department.view');


// Permission-derived UI flags
$canManage = has_permission('org.department.manage');
$canView   = has_permission('org.department.view');
$pdo = db();

/** Detect columns available in departments */
$cols = $pdo->query("SHOW COLUMNS FROM departments")->fetchAll(PDO::FETCH_COLUMN);
$hasCode     = in_array('code', $cols, true);
$hasStatus   = in_array('status', $cols, true);
$hasParent   = in_array('parent_id', $cols, true);
$hasCreated  = in_array('created_at', $cols, true);
$hasUpdated  = in_array('updated_at', $cols, true);

/** Search (collation-safe) */
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  if ($hasCode) {
    $where = "WHERE d.name COLLATE utf8mb4_unicode_ci LIKE ? OR d.code COLLATE utf8mb4_unicode_ci LIKE ?";
    $params = [$like, $like];
  } else {
    $where = "WHERE d.name COLLATE utf8mb4_unicode_ci LIKE ?";
    $params = [$like];
  }
}

/** Build SELECT dynamically */
$select = ["d.id", "d.name"];
if ($hasCode)    $select[] = "d.code";
if ($hasStatus)  $select[] = "d.status";
if ($hasParent)  $select[] = "d.parent_id";
if ($hasCreated) $select[] = "d.created_at";
if ($hasUpdated) $select[] = "d.updated_at";

$sql = "SELECT " . implode(", ", $select) . "
        FROM departments d
        $where
        ORDER BY d.id DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** Parent map (if parent_id exists) */
$parentMap = [];
if ($hasParent && $rows) {
  $ids = array_unique(array_filter(array_map(fn($r)=> (int)($r['parent_id'] ?? 0), $rows)));
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name FROM departments WHERE id IN ($in)");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pr) {
      $parentMap[(int)$pr['id']] = (string)$pr['name'];
    }
  }
}

$canManage = has_permission('org.department.manage');

$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = 'Departments';
$ACTIVE_MENU = 'org.departments';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Departments</h1>
  <?php if ($canManage): ?>
    <a class="btn btn-primary" href="/org/departments_form.php">+ Add Department</a>
  <?php endif; ?>
</div>

<form method="get" class="row g-2 mb-3">
  <div class="col-auto">
    <input name="q" class="form-control" placeholder="<?= $hasCode ? 'Search code/name' : 'Search name' ?>" value="<?= htmlspecialchars($q) ?>">
  </div>
  <div class="col-auto">
    <button class="btn btn-outline-secondary" type="submit">Search</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th>#</th>
        <?php if ($hasCode): ?><th>Code</th><?php endif; ?>
        <th>Name</th>
        <?php if ($hasParent): ?><th>Parent</th><?php endif; ?>
        <?php if ($hasStatus): ?><th>Status</th><?php endif; ?>
        <?php if ($hasCreated): ?><th>Created</th><?php endif; ?>
        <?php if ($hasUpdated): ?><th>Updated</th><?php endif; ?>
        <?php if ($canManage): ?><th></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <?php if ($hasCode): ?><td><code><?= htmlspecialchars((string)($r['code'] ?? '')) ?></code></td><?php endif; ?>
          <td><?= htmlspecialchars((string)$r['name']) ?></td>
          <?php if ($hasParent): ?>
            <td><?= htmlspecialchars($parentMap[(int)($r['parent_id'] ?? 0)] ?? '—') ?></td>
          <?php endif; ?>
          <?php if ($hasStatus): ?>
            <td><span class="badge bg-<?= ($r['status'] ?? 'inactive') === 'active' ? 'success' : 'secondary' ?>">
              <?= ($r['status'] ?? 'inactive') === 'active' ? 'Active' : 'Inactive' ?>
            </span></td>
          <?php endif; ?>
          <?php if ($hasCreated): ?><td><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></td><?php endif; ?>
          <?php if ($hasUpdated): ?><td><?= htmlspecialchars((string)($r['updated_at'] ?? '')) ?></td><?php endif; ?>
          <?php if ($canManage): ?>
            <td><a class="btn btn-sm btn-outline-primary" href="/org/departments_form.php?id=<?= (int)$r['id'] ?>">Edit</a></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="<?= 1 + ($hasCode?1:0) + 1 + ($hasParent?1:0) + ($hasStatus?1:0) + ($hasCreated?1:0) + ($hasUpdated?1:0) + ($canManage?1:0) ?>"
              class="text-center text-muted py-4">No departments found.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php require_once $UI_PATH . '/layout_end.php'; ?>
