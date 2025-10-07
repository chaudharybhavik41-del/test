<?php
declare(strict_types=1);
/** PATH: /public_html/workcenters/capabilities_form.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('workcenters.manage');

$pdo = db();
$errors = [];
$wc_id = (int)($_GET['work_center_id'] ?? 0);

// ✅ Dropdown source: exactly 2 columns for FETCH_KEY_PAIR
$stmt = $pdo->query("
    SELECT id, CONCAT(code, ' — ', name) AS label
    FROM work_centers
    WHERE active=1
    ORDER BY code
");
$centers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [id => "CODE — NAME"]

// Handle add capability
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wc_id = (int)($_POST['work_center_id'] ?? 0);
    $process_id = (int)($_POST['process_id'] ?? 0);
    $rate  = ($_POST['std_rate_per_hour'] ?? '') !== '' ? (float)$_POST['std_rate_per_hour'] : null;
    $batch = ($_POST['std_batch_size'] ?? '') !== '' ? (float)$_POST['std_batch_size'] : null;

    if ($wc_id <= 0) $errors[] = 'Select a work center.';
    if ($process_id <= 0) $errors[] = 'Select a process.';

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO work_center_capabilities 
              (work_center_id, process_id, std_rate_per_hour, std_batch_size) 
              VALUES (?,?,?,?)");
        $stmt->execute([$wc_id,$process_id,$rate,$batch]);
        header('Location: capabilities_form.php?work_center_id='.$wc_id);
        exit;
    }
}

// Active processes for dropdown
$processes = $pdo->query("
    SELECT id, CONCAT(code,' — ',name) AS label
    FROM processes
    WHERE active=1
    ORDER BY code
")->fetchAll(PDO::FETCH_ASSOC);

// Current capabilities of this center
$current = [];
if ($wc_id > 0) {
    $stmt = $pdo->prepare("
        SELECT wcc.id, p.code, p.name, wcc.std_rate_per_hour, wcc.std_batch_size
        FROM work_center_capabilities wcc
        JOIN processes p ON p.id=wcc.process_id
        WHERE wcc.work_center_id=? ORDER BY p.code
    ");
    $stmt->execute([$wc_id]);
    $current = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Work Center Capabilities</h1>
    <a class="btn btn-outline-secondary" href="workcenters_list.php">Back</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
      <label class="form-label">Work Center</label>
      <select name="work_center_id" class="form-select" onchange="this.form.submit()">
        <option value="0">Select…</option>
        <?php foreach ($centers as $cid => $label): ?>
          <option value="<?=$cid?>" <?= $wc_id===$cid?'selected':''?>><?=htmlspecialchars($label)?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if ($wc_id > 0): ?>
  <div class="card mb-3">
    <div class="card-header">Add Capability</div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <input type="hidden" name="work_center_id" value="<?=$wc_id?>">
        <div class="col-md-5">
          <label class="form-label">Process</label>
          <select name="process_id" class="form-select" required>
            <option value="">Select…</option>
            <?php foreach ($processes as $p): ?>
              <option value="<?=$p['id']?>"><?=htmlspecialchars($p['label'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Std Rate / hr</label>
          <input type="number" step="0.001" class="form-control" name="std_rate_per_hour">
        </div>
        <div class="col-md-3">
          <label class="form-label">Std Batch Size</label>
          <input type="number" step="0.001" class="form-control" name="std_batch_size">
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button class="btn btn-primary w-100">Add</button>
        </div>
      </form>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead class="table-light">
        <tr><th>Process</th><th class="text-end">Rate/hr</th><th class="text-end">Batch</th><th class="text-end">Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($current as $c): ?>
          <tr>
            <td><?=htmlspecialchars($c['code'].' — '.$c['name'])?></td>
            <td class="text-end"><?= $c['std_rate_per_hour']!==null ? (float)$c['std_rate_per_hour'] : '—' ?></td>
            <td class="text-end"><?= $c['std_batch_size']!==null ? (float)$c['std_batch_size'] : '—' ?></td>
            <td class="text-end">
              <form method="post" action="capabilities_delete.php" onsubmit="return confirm('Remove capability?')">
                <input type="hidden" name="id" value="<?=$c['id']?>">
                <input type="hidden" name="work_center_id" value="<?=$wc_id?>">
                <button class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$current): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No capabilities defined for this center.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
