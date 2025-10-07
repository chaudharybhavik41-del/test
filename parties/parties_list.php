<?php
/** PATH: /public_html/parties/parties_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('parties.view');

$pdo = db();

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$types = ['client','supplier','contractor','other'];
if ($type !== '' && !in_array($type, $types, true)) $type = '';

// Build WHERE dynamically (avoid collation issues)
$where = [];
$params = [];
if ($q !== '') {
  $where[] = "(name LIKE ? OR code LIKE ? OR phone LIKE ? OR gst_number LIKE ? OR city LIKE ? OR state LIKE ?)";
  $like = '%'.$q.'%';
  array_push($params, $like,$like,$like,$like,$like,$like);
}
if ($type !== '') { $where[] = "type = ?"; $params[] = $type; }

$sql = "SELECT id, code, name, type, phone, gst_number, city, state, status
        FROM parties"
      . (count($where) ? " WHERE ".implode(" AND ", $where) : "")
      . " ORDER BY id DESC LIMIT 200";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Parties';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><?= h($pageTitle) ?></h1>
    <div class="d-flex gap-2">
      <?php if (has_permission('parties.manage')): ?>
        <a href="parties_form.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> New Party</a>
      <?php endif; ?>
    </div>
  </div>

  <form class="row g-2 mb-3" method="get" action="">
    <div class="col-sm-5 col-md-4 col-lg-3">
      <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search name, code, phone, GST, city, state">
    </div>
    <div class="col-sm-4 col-md-3 col-lg-2">
      <select name="type" class="form-select">
        <option value="">All types</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= h($t) ?>" <?= $type===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Filter</button>
    </div>
  </form>

  <?php if (empty($rows)): ?>
    <div class="alert alert-warning">No parties found.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:120px">Code</th>
            <th>Name</th>
            <th style="width:120px">Type</th>
            <th style="width:140px">Phone</th>
            <th style="width:160px">GST</th>
            <th style="width:180px">Location</th>
            <th style="width:90px">Status</th>
            <th style="width:140px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><span class="badge bg-secondary-subtle border text-body"><?= h($r['code']) ?></span></td>
              <td><?= h($r['name']) ?></td>
              <td><?= ucfirst(h($r['type'])) ?></td>
              <td><?= h($r['phone'] ?? '') ?></td>
              <td><?= h($r['gst_number'] ?? '') ?></td>
              <td><?= h(trim(($r['city'] ?? '') . ', ' . ($r['state'] ?? ''), ', ')) ?></td>
              <td>
                <?php if ((int)$r['status'] === 1): ?>
                  <span class="badge bg-success-subtle border text-success-emphasis">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary-subtle border text-body">Inactive</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm">
                  <?php if (has_permission('parties.manage')): ?>
                    <a class="btn btn-outline-secondary" href="parties_form.php?id=<?= (int)$r['id'] ?>" title="Edit">
                      <i class="bi bi-pencil-square"></i> Edit
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
