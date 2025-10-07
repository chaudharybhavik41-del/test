<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('core.user.view');

$PAGE_TITLE = 'Access Review';
$LAYOUT_DIR = null;
foreach ([__DIR__ . '/../ui', dirname(__DIR__) . '/ui', $_SERVER['DOCUMENT_ROOT'] . '/ui'] as $dir)
  if (is_dir($dir)) { $LAYOUT_DIR = rtrim($dir, '/'); break; }
if ($LAYOUT_DIR) include $LAYOUT_DIR . '/layout_start.php'; else echo '<!doctype html><html><head><meta charset="utf-8"></head><body><div class="container-fluid">';

$pdo = db();
$q = trim($_GET['q'] ?? '');
$sql = "SELECT u.id, u.username, u.name, u.email, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ', ') AS roles
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        LEFT JOIN roles r ON r.id = ur.role_id
        WHERE (? = '' OR u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)
        GROUP BY u.id
        ORDER BY u.name";
$like = '%'.$q.'%';
$st = $pdo->prepare($sql);
$st->execute([$q, $like, $like, $like]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && $_GET['export']=='csv') {
  header('Content-Type: text/csv');
  header('Content-Disposition: attachment; filename="access_review.csv"');
  $f = fopen('php://output', 'w');
  fputcsv($f, ['User ID','Username','Name','Email','Roles']);
  foreach ($rows as $r) fputcsv($f, [$r['id'],$r['username'],$r['name'],$r['email'],$r['roles']]);
  exit;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0">Access Review</h1>
  <div>
    <a href="?export=csv&q=<?= urlencode($q) ?>" class="btn btn-outline-secondary btn-sm">Export CSV</a>
  </div>
</div>
<form class="mb-3" method="get">
  <div class="input-group input-group-sm" style="max-width:420px">
    <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search user">
    <button class="btn btn-outline-secondary">Search</button>
  </div>
</form>
<div class="table-responsive">
  <table class="table table-sm">
    <thead class="table-light"><tr><th>#</th><th>Username</th><th>Name</th><th>Email</th><th>Roles</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars($r['username']) ?></td>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td><?= htmlspecialchars($r['email']) ?></td>
          <td><?= htmlspecialchars($r['roles']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
if (!empty($LAYOUT_DIR) && is_file($LAYOUT_DIR . '/layout_end.php')) include $LAYOUT_DIR . '/layout_end.php';
else echo '</div></body></html>';
