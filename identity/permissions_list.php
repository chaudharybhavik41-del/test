<?php
/** PATH: /public_html/identity/permissions_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('core.permission.view');

$pdo = db();
$canManage = has_permission('core.permission.manage');

$q = trim($_GET['q'] ?? '');

$st = $pdo->prepare("
  SELECT id, code, name, COALESCE(module,'other') AS module
  FROM permissions
  WHERE (? = '' OR code LIKE ? OR name LIKE ? OR module LIKE ?)
  ORDER BY module ASC, name ASC
");
$like = '%'.$q.'%';
$st->execute([$q, $like, $like, $like]);
$perms = $st->fetchAll(PDO::FETCH_ASSOC);

$byModule = [];
foreach ($perms as $p) { $byModule[$p['module']][] = $p; }

/* Layout */
$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = 'Permissions';
$ACTIVE_MENU = 'identity.permissions';
require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0">Permissions</h1>
  <div>
    <?php if ($canManage): ?>
      <a href="/identity/permissions_edit.php" class="btn btn-primary btn-sm">New Permission</a>
    <?php endif; ?>
  </div>
</div>

<form class="mb-3" method="get">
  <div class="input-group input-group-sm" style="max-width: 520px;">
    <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search code, name, module">
    <button class="btn btn-outline-secondary">Search</button>
  </div>
</form>

<?php if (!$perms): ?>
  <div class="text-muted">No permissions.</div>
<?php else: ?>
  <?php foreach ($byModule as $module => $items): ?>
    <div class="border rounded mb-3">
      <div class="bg-light px-2 py-1 fw-bold text-uppercase small"><?= htmlspecialchars($module) ?></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr><th style="width:72px">#</th><th>Code</th><th>Name</th><th style="width:120px">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($items as $p): ?>
              <tr>
                <td><?= (int)$p['id'] ?></td>
                <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td>
                  <?php if ($canManage): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="/identity/permissions_edit.php?id=<?= (int)$p['id'] ?>">Edit</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
