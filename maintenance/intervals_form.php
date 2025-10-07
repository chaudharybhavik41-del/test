<?php
declare(strict_types=1);
/** PATH: /public_html/maintenance/intervals_form.php */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';

require_login();
require_permission('maintenance.manage');

$pdo = db();

$program_id = (int)($_GET['program_id'] ?? 0);
$id         = (int)($_GET['id'] ?? 0);
$editing    = $id > 0;

// Fallback: if program_id missing but id is present, look it up
if ($program_id <= 0 && $editing) {
  $st = $pdo->prepare("SELECT program_id FROM maintenance_intervals WHERE id = ?");
  $st->execute([$id]);
  $program_id = (int)$st->fetchColumn();
}

// Still missing? hard-stop with a clear message
if ($program_id <= 0) { http_response_code(400); exit('program_id required'); }
// load program header (for context/breadcrumb) — NOTE: no p.title in schema
$ph = $pdo->prepare("
  SELECT p.id, p.machine_id AS machine_fk, m.machine_id, m.name AS machine_name
  FROM maintenance_programs p
  LEFT JOIN machines m ON m.id = p.machine_id
  WHERE p.id = ?
");
$ph->execute([$program_id]);
$program = $ph->fetch(PDO::FETCH_ASSOC);
if (!$program) { http_response_code(404); exit('Program not found'); }

// dropdown: maintenance types
$mtypes = $pdo->query("SELECT id, name FROM maintenance_types ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

// default row
$row = [
  'program_id'           => $program_id,
  'maintenance_type_id'  => null,
  'title'                => '',
  'frequency'            => 'monthly',     // enum in your DB: daily/weekly/monthly/quarterly/semiannual/annual/custom_days
  'interval_count'       => 1,
  'custom_days'          => null,
  'notify_before_days'   => null,
  'grace_days'           => null,
  'requires_shutdown'    => 0,
  'priority'             => 0,
  'active'               => 1,
  'last_completed_on'    => null,
  'next_due_date'        => null,
];

// load existing when editing
if ($editing) {
  $st = $pdo->prepare("SELECT * FROM maintenance_intervals WHERE id=? AND program_id=?");
  $st->execute([$id,$program_id]);
  $db = $st->fetch(PDO::FETCH_ASSOC);
  if (!$db) { http_response_code(404); exit('Interval not found'); }
  $row = array_merge($row, $db);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require_token();
  $row['program_id']          = $program_id; // lock to current program
  $row['maintenance_type_id'] = (int)($_POST['maintenance_type_id'] ?? 0) ?: null;
  $row['title']               = trim((string)($_POST['title'] ?? '')) ?: null;
  $row['frequency']           = (string)($_POST['frequency'] ?? 'monthly');
  $row['interval_count']      = (int)($_POST['interval_count'] ?? 1);
  $row['custom_days']         = ($_POST['custom_days'] === '' ? null : (int)$_POST['custom_days']);
  $row['notify_before_days']  = ($_POST['notify_before_days'] === '' ? null : (int)$_POST['notify_before_days']);
  $row['grace_days']          = ($_POST['grace_days'] === '' ? null : (int)$_POST['grace_days']);
  $row['requires_shutdown']   = isset($_POST['requires_shutdown']) ? 1 : 0;
  $row['priority']            = (int)($_POST['priority'] ?? 0);
  $row['active']              = isset($_POST['active']) ? 1 : 0;
  $row['last_completed_on']   = $_POST['last_completed_on'] ?: null;
  $row['next_due_date']       = $_POST['next_due_date'] ?: null;

  if (!$row['maintenance_type_id']) $errors[] = 'Maintenance Type is required.';
  if (!$row['title'])               $errors[] = 'Title is required.';
  if (!in_array($row['frequency'], ['daily','weekly','monthly','quarterly','semiannual','annual','custom_days'], true))
    $errors[] = 'Invalid frequency.';
  if ($row['interval_count'] <= 0) $errors[] = 'Interval count must be positive.';
  if ($row['frequency'] === 'custom_days' && !$row['custom_days'])
    $errors[] = 'Custom days required for frequency = custom_days.';

  if (!$errors) {
    try {
      if ($editing) {
        $sql = "UPDATE maintenance_intervals SET
                  maintenance_type_id=?, title=?, frequency=?, interval_count=?, custom_days=?,
                  notify_before_days=?, grace_days=?, requires_shutdown=?, priority=?, active=?,
                  last_completed_on=?, next_due_date=?
                WHERE id=? AND program_id=?";
        $pdo->prepare($sql)->execute([
          $row['maintenance_type_id'], $row['title'], $row['frequency'], $row['interval_count'], $row['custom_days'],
          $row['notify_before_days'], $row['grace_days'], $row['requires_shutdown'], $row['priority'], $row['active'],
          $row['last_completed_on'], $row['next_due_date'], $id, $program_id
        ]);
      } else {
        $sql = "INSERT INTO maintenance_intervals
                  (program_id, maintenance_type_id, title, frequency, interval_count, custom_days,
                   notify_before_days, grace_days, requires_shutdown, priority, active,
                   last_completed_on, next_due_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
          $program_id, $row['maintenance_type_id'], $row['title'], $row['frequency'], $row['interval_count'], $row['custom_days'],
          $row['notify_before_days'], $row['grace_days'], $row['requires_shutdown'], $row['priority'], $row['active'],
          $row['last_completed_on'], $row['next_due_date']
        ]);
        $id = (int)$pdo->lastInsertId();
      }
      header("Location: /maintenance/intervals_list.php?program_id=".$program_id);
      exit;
    } catch (Throwable $e) {
      $errors[] = 'Save failed: '.$e->getMessage();
    }
  }
}

// UI
$UI = $ROOT.'/ui';
$PAGE_TITLE = $editing ? 'Edit Interval' : 'Add Interval';
require_once $UI.'/init.php';
require_once $UI.'/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h5 mb-0"><?= htmlspecialchars($PAGE_TITLE) ?></h1>
    <div class="small text-muted">
      Program #<?= (int)$program['id'] ?> · Machine:
      <strong><?= htmlspecialchars(($program['machine_id'] ?? '') . ($program['machine_name'] ? ' — '.$program['machine_name'] : '')) ?></strong>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-light btn-sm" href="/maintenance/intervals_list.php?program_id=<?= (int)$program_id ?>">Back</a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_hidden_input() ?>
  <div class="col-md-6">
    <label class="form-label">Interval Title</label>
    <input name="title" class="form-control" required value="<?= htmlspecialchars((string)($row['title'] ?? '')) ?>" placeholder="e.g., Weekly Inspection">
  </div>

  <div class="col-md-6">
    <label class="form-label">Maintenance Type</label>
    <select name="maintenance_type_id" class="form-select" required>
      <option value="">— choose —</option>
      <?php foreach ($mtypes as $tid => $name): ?>
        <option value="<?= (int)$tid ?>" <?= ((int)($row['maintenance_type_id'] ?? 0) === (int)$tid ? 'selected' : '') ?>>
          <?= htmlspecialchars($name) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-6">
    <label class="form-label">Frequency</label>
    <div class="input-group">
      <select name="frequency" id="frequency" class="form-select">
        <?php foreach (['daily','weekly','monthly','quarterly','semiannual','annual','custom_days'] as $f): ?>
          <option value="<?=$f?>" <?= (($row['frequency'] ?? '')===$f?'selected':'') ?>><?= ucwords(str_replace('_',' ',$f)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="number" min="1" name="interval_count" class="form-control" value="<?= (int)($row['interval_count'] ?? 1) ?>">
      <span class="input-group-text">count</span>
    </div>
    <div class="form-text">For “custom days”, also set Custom Days below.</div>
  </div>

  <div class="col-md-2">
    <label class="form-label">Custom Days</label>
    <input type="number" min="1" name="custom_days" class="form-control" value="<?= htmlspecialchars((string)($row['custom_days'] ?? '')) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Notify Before (days)</label>
    <input type="number" min="0" name="notify_before_days" class="form-control" value="<?= htmlspecialchars((string)($row['notify_before_days'] ?? '')) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Grace Days</label>
    <input type="number" min="0" name="grace_days" class="form-control" value="<?= htmlspecialchars((string)($row['grace_days'] ?? '')) ?>">
  </div>

  <div class="col-md-3">
    <label class="form-label">Priority</label>
    <input type="number" name="priority" class="form-control" value="<?= (int)($row['priority'] ?? 0) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label d-block">Requires Shutdown</label>
    <input class="form-check-input" type="checkbox" name="requires_shutdown" <?= !empty($row['requires_shutdown'])?'checked':'' ?>>
  </div>
  <div class="col-md-3">
    <label class="form-label d-block">Active</label>
    <input class="form-check-input" type="checkbox" name="active" <?= !empty($row['active'])?'checked':'' ?>>
  </div>

  <div class="col-md-3">
    <label class="form-label">Next Due Date</label>
    <input type="date" name="next_due_date" class="form-control" value="<?= htmlspecialchars((string)($row['next_due_date'] ?? '')) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Last Completed On</label>
    <input type="date" name="last_completed_on" class="form-control" value="<?= htmlspecialchars((string)($row['last_completed_on'] ?? '')) ?>">
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary">Save</button>
    <?php if ($editing): ?>
      <a class="btn btn-outline-danger" href="?program_id=<?= (int)$program_id ?>&id=<?= (int)$id ?>&delete=1" onclick="return confirm('Delete this interval?')">Delete</a>
    <?php endif; ?>
  </div>
</form>

<script>
(function(){
  function toggleCustom(){
    var f = document.getElementById('frequency').value;
    var cd = document.querySelector('input[name="custom_days"]');
    cd.disabled = (f !== 'custom_days');
  }
  document.getElementById('frequency').addEventListener('change', toggleCustom);
  toggleCustom();
})();
</script>

<?php require_once $UI.'/layout_end.php'; ?>