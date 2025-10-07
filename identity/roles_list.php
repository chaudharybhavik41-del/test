<?php
/** PATH: /public_html/identity/roles_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('core.role.view');

$pdo = db();
$canManage = has_permission('core.role.manage');

$q = trim($_GET['q'] ?? '');

$roleCols = $pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_COLUMN);
$hasCode   = in_array('code', $roleCols, true);
$hasStatus = in_array('status', $roleCols, true);

$sql = "SELECT r.id, r.name".
       ($hasCode ? ", r.code" : "").
       ($hasStatus ? ", r.status" : "").
       ", COUNT(rp.permission_id) AS perm_count
       FROM roles r
       LEFT JOIN role_permissions rp ON rp.role_id = r.id
       WHERE (? = '' OR r.name LIKE ?".($hasCode ? " OR r.code LIKE ?" : "").")
       GROUP BY r.id
       ORDER BY r.name ASC
       LIMIT 500";

$params = [$q, '%'.$q.'%'];
if ($hasCode) $params[] = '%'.$q.'%';

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* Layout */
$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = 'Roles';
$ACTIVE_MENU = 'identity.roles';
require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0">Roles</h1>
  <div>
    <?php if ($canManage): ?>
      <a href="/identity/roles_form.php" class="btn btn-primary btn-sm">New Role</a>
    <?php endif; ?>
  </div>
</div>

<form class="mb-3" method="get">
  <div class="input-group input-group-sm" style="max-width: 420px;">
    <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name<?= $hasCode ? ', code' : '' ?>">
    <button class="btn btn-outline-secondary">Search</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th style="width:72px">#</th>
        <th>Name</th>
        <?php if ($hasCode): ?><th>Code</th><?php endif; ?>
        <th>Permissions</th>
        <?php if ($hasStatus): ?><th>Status</th><?php endif; ?>
        <th style="width:160px">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <?php if ($hasCode): ?><td><code><?= htmlspecialchars($r['code']) ?></code></td><?php endif; ?>
          <td><span class="badge bg-secondary"><?= (int)$r['perm_count'] ?></span></td>
          <?php if ($hasStatus): ?>
            <td><span class="badge bg-<?= ($r['status']==='active'?'success':'secondary') ?>"><?= htmlspecialchars($r['status']) ?></span></td>
          <?php endif; ?>
          <td>
            <?php if ($canManage): ?>
              <a class="btn btn-outline-secondary btn-sm" href="/identity/roles_form.php?id=<?= (int)$r['id'] ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= 5 + (int)$hasCode + (int)$hasStatus ?>" class="text-muted">No roles.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
