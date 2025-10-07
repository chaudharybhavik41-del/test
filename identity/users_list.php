<?php
/** PATH: /public_html/identity/users_list.php (finalized replacement) */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('core.user.view');

// Permission-derived UI flags
$canCreate = has_permission('core.user.manage');   // for New User button
$canEdit   = has_permission('core.user.manage');   // for Edit button
$canRoles  = has_permission('userrole.update');    // for managing roles

$pdo = db();

$q = trim($_GET['q'] ?? '');

// Build query (same logic as your original, simplified here)
$sql = "SELECT u.id, u.username, u.name, u.email, u.status, u.created_at
        FROM users u
        WHERE (? = '' OR u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)
        ORDER BY u.created_at DESC
        LIMIT 500";
$like = '%' . $q . '%';
$params = [$q, $like, $like, $like];
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include dirname(__DIR__).'/ui/layout_start.php';
?>
<div class="container-fluid">
  <main class="px-3 py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 m-0">Users</h1>
      <div>
        <?php if ($canCreate): ?>
          <a href="users_form.php" class="btn btn-primary btn-sm">New User</a>
        <?php endif; ?>
      </div>
    </div>

    <form class="mb-3" method="get" action="">
      <div class="input-group input-group-sm" style="max-width: 420px;">
        <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name, username, email">
        <button class="btn btn-outline-secondary" type="submit">Search</button>
      </div>
    </form>

    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th style="width: 80px">#</th>
            <th>Username</th>
            <th>Name</th>
            <th>Email</th>
            <th>Status</th>
            <th>Created</th>
            <th style="width: 220px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td><?= htmlspecialchars($row['username']) ?></td>
              <td><?= htmlspecialchars($row['name']) ?></td>
              <td><?= htmlspecialchars($row['email']) ?></td>
              <td><span class="badge bg-<?= $row['status']==='active'?'success':'secondary' ?>"><?= htmlspecialchars($row['status']) ?></span></td>
              <td><?= htmlspecialchars($row['created_at']) ?></td>
              <td>
                <div class="btn-group btn-group-sm" role="group">
                  <?php if ($canEdit): ?>
                    <a href="users_form.php?id=<?= (int)$row['id'] ?>" class="btn btn-outline-secondary">Edit</a>
                  <?php endif; ?>
                  <?php if ($canRoles): ?>
                    <a href="user_roles.php?user_id=<?= (int)$row['id'] ?>" class="btn btn-outline-primary">Roles</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>
<?php include dirname(__DIR__).'/ui/layout_end.php'; ?>
