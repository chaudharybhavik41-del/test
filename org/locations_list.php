<?php
/** PATH: /public_html/org/locations_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('org.location.view');

$pdo = db();

/** Detect columns present in locations */
$cols = $pdo->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_COLUMN);
$hasCode    = in_array('code', $cols, true);
$hasStatus  = in_array('status', $cols, true);
$hasCity    = in_array('city', $cols, true);
$hasState   = in_array('state', $cols, true);
$hasCountry = in_array('country', $cols, true);
$hasPincode = in_array('pincode', $cols, true);
$hasAddress = in_array('address', $cols, true);
$hasCreated = in_array('created_at', $cols, true);
$hasUpdated = in_array('updated_at', $cols, true);

/** Search (collation-safe) */
$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
if ($q !== '') {
  $like = '%' . $q . '%';
  $parts = ["l.name COLLATE utf8mb4_unicode_ci LIKE ?"];
  $params[] = $like;
  if ($hasCode)    { $parts[] = "l.code COLLATE utf8mb4_unicode_ci LIKE ?";    $params[] = $like; }
  if ($hasCity)    { $parts[] = "l.city COLLATE utf8mb4_unicode_ci LIKE ?";    $params[] = $like; }
  if ($hasState)   { $parts[] = "l.state COLLATE utf8mb4_unicode_ci LIKE ?";   $params[] = $like; }
  if ($hasCountry) { $parts[] = "l.country COLLATE utf8mb4_unicode_ci LIKE ?"; $params[] = $like; }
  if ($hasAddress) { $parts[] = "l.address COLLATE utf8mb4_unicode_ci LIKE ?"; $params[] = $like; }
  $where = "WHERE " . implode(' OR ', $parts);
}

/** Build SELECT */
$select = ["l.id", "l.name"];
if ($hasCode)    $select[] = "l.code";
if ($hasStatus)  $select[] = "l.status";
if ($hasCity)    $select[] = "l.city";
if ($hasState)   $select[] = "l.state";
if ($hasCountry) $select[] = "l.country";
if ($hasPincode) $select[] = "l.pincode";
if ($hasAddress) $select[] = "l.address";
if ($hasCreated) $select[] = "l.created_at";
if ($hasUpdated) $select[] = "l.updated_at";

$sql = "SELECT " . implode(", ", $select) . " FROM locations l
        $where
        ORDER BY l.id DESC
        LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canManage = has_permission('org.location.manage');

$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = 'Locations';
$ACTIVE_MENU = 'org.locations';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Locations</h1>
  <?php if ($canManage): ?>
    <a class="btn btn-primary" href="/org/locations_form.php">+ Add Location</a>
  <?php endif; ?>
</div>

<form method="get" class="row g-2 mb-3">
  <div class="col-auto">
    <input name="q" class="form-control" placeholder="Search name<?= $hasCode?', code':''; ?><?= $hasCity?', city':''; ?><?= $hasState?', state':''; ?><?= $hasCountry?', country':''; ?>" value="<?= htmlspecialchars($q) ?>">
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
        <?php if ($hasCity): ?><th>City</th><?php endif; ?>
        <?php if ($hasState): ?><th>State</th><?php endif; ?>
        <?php if ($hasCountry): ?><th>Country</th><?php endif; ?>
        <?php if ($hasPincode): ?><th>Pincode</th><?php endif; ?>
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
          <?php if ($hasCity): ?><td><?= htmlspecialchars((string)($r['city'] ?? '')) ?></td><?php endif; ?>
          <?php if ($hasState): ?><td><?= htmlspecialchars((string)($r['state'] ?? '')) ?></td><?php endif; ?>
          <?php if ($hasCountry): ?><td><?= htmlspecialchars((string)($r['country'] ?? '')) ?></td><?php endif; ?>
          <?php if ($hasPincode): ?><td><?= htmlspecialchars((string)($r['pincode'] ?? '')) ?></td><?php endif; ?>
          <?php if ($hasStatus): ?>
            <td><span class="badge bg-<?= ($r['status'] ?? 'inactive') === 'active' ? 'success' : 'secondary' ?>">
              <?= ($r['status'] ?? 'inactive') === 'active' ? 'Active' : 'Inactive' ?>
            </span></td>
          <?php endif; ?>
          <?php if ($hasCreated): ?><td><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></td><?php endif; ?>
          <?php if ($hasUpdated): ?><td><?= htmlspecialchars((string)($r['updated_at'] ?? '')) ?></td><?php endif; ?>
          <?php if ($canManage): ?>
            <td><a class="btn btn-sm btn-outline-primary" href="/locations/locations_form.php?id=<?= (int)$r['id'] ?>">Edit</a></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="<?= 1 + ($hasCode?1:0) + 1 + ($hasCity?1:0) + ($hasState?1:0) + ($hasCountry?1:0) + ($hasPincode?1:0) + ($hasStatus?1:0) + ($hasCreated?1:0) + ($hasUpdated?1:0) + ($canManage?1:0) ?>"
              class="text-center text-muted py-4">No locations found.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php require_once $UI_PATH . '/layout_end.php'; ?>
