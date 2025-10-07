<?php
declare(strict_types=1);
/** PATH: /public_html/maintenance/programs_form.php */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';

require_login();
// If you already have a 'maintenance.manage' permission, switch to that:
require_permission('machines.manage');

$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$machine_id = (int)($_GET['machine_id'] ?? 0);
$editing = $id > 0;

$row = [
  'machine_id'   => $machine_id ?: 0,
  'anchor_date'  => null,
  'default_team' => null,
  'notes'        => null,
];

// Load program when editing
if ($editing) {
  $st = $pdo->prepare("SELECT * FROM maintenance_programs WHERE id = ?");
  $st->execute([$id]);
  if ($db = $st->fetch(PDO::FETCH_ASSOC)) {
    $row = array_merge($row, $db);
  } else {
    http_response_code(404);
    exit('Program not found');
  }
}

// Machines for dropdown
$machines = $pdo->query("SELECT id, CONCAT(machine_id,' - ',name) AS label FROM machines ORDER BY machine_id")->fetchAll(PDO::FETCH_KEY_PAIR);

$errors = [];
$okMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require_token();
  $row['machine_id']   = (int)($_POST['machine_id'] ?? 0);
  $row['anchor_date']  = $_POST['anchor_date'] ?? null;
  $row['default_team'] = trim((string)($_POST['default_team'] ?? '')) ?: null;
  $row['notes']        = trim((string)($_POST['notes'] ?? '')) ?: null;

  if ($row['machine_id'] <= 0) $errors[] = 'Machine is required.';

  // Enforce one program per machine (schema has UNIQUE uq_mp_machine)
  if (!$errors) {
    $u = $pdo->prepare("SELECT id FROM maintenance_programs WHERE machine_id = ? " . ($editing ? "AND id <> ?" : ""));
    $u->execute($editing ? [$row['machine_id'], $id] : [$row['machine_id']]);
    if ($u->fetch()) $errors[] = 'This machine already has a maintenance program.';
  }

  if (!$errors) {
    try {
      if ($editing) {
        $sql = "UPDATE maintenance_programs
                   SET machine_id=?, anchor_date=?, default_team=?, notes=?, updated_at=NOW()
                 WHERE id=?";
        $pdo->prepare($sql)->execute([
          $row['machine_id'], $row['anchor_date'], $row['default_team'], $row['notes'], $id
        ]);
        $okMsg = 'Program updated.';
      } else {
        $sql = "INSERT INTO maintenance_programs
                  (machine_id, anchor_date, default_team, notes, created_at, updated_at)
                VALUES (?,?,?,?,NOW(),NOW())";
        $pdo->prepare($sql)->execute([
          $row['machine_id'], $row['anchor_date'], $row['default_team'], $row['notes']
        ]);
        $id = (int)$pdo->lastInsertId();
        $editing = true;
        $okMsg = 'Program created.';
      }
      // After save, stay on the same page so you can add intervals
      header("Location: programs_form.php?id=".$id."&ok=1");
      exit;
    } catch (Throwable $e) {
      $errors[] = 'Save failed: '.$e->getMessage();
    }
  }
}

// UI
$PAGE_TITLE = $editing ? 'Edit Maintenance Program' : 'Add Maintenance Program';
include $ROOT . '/ui/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?= htmlspecialchars($PAGE_TITLE) ?></h1>
  <div class="d-flex gap-2">
    <?php if ($editing): ?>
      <a class="btn btn-outline-primary btn-sm" href="/maintenance/intervals_list.php?program_id=<?= (int)$id ?>">+ Manage Intervals</a>
    <?php endif; ?>
    <a class="btn btn-light btn-sm" href="/maintenance/programs_list.php">Back</a>
  </div>
</div>

<?php if (!empty($_GET['ok']) && !$errors): ?>
  <div class="alert alert-success">Saved successfully.</div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_hidden_input() ?>
  <div class="col-md-6">
    <label class="form-label">Machine</label>
    <select name="machine_id" class="form-select" required>
      <option value="">-- choose --</option>
      <?php foreach ($machines as $mid => $label): ?>
        <option value="<?= (int)$mid ?>" <?= ((int)$row['machine_id']===(int)$mid)?'selected':'' ?>>
          <?= htmlspecialchars($label) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">One program per machine (enforced by DB unique key).</div>
  </div>

  <div class="col-md-3">
    <label class="form-label">Anchor Date</label>
    <input type="date" name="anchor_date" class="form-control" value="<?= htmlspecialchars((string)($row['anchor_date'] ?? '')) ?>">
    <div class="form-text">Used as base for interval scheduling.</div>
  </div>

  <div class="col-md-3">
    <label class="form-label">Default Team</label>
    <input name="default_team" class="form-control" value="<?= htmlspecialchars((string)($row['default_team'] ?? '')) ?>">
  </div>

  <div class="col-12">
    <label class="form-label">Notes</label>
    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars((string)($row['notes'] ?? '')) ?></textarea>
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary">Save</button>
    <?php if ($editing): ?>
      <a class="btn btn-outline-secondary" href="/maintenance/intervals_list.php?program_id=<?= (int)$id ?>">Manage Intervals</a>
    <?php endif; ?>
  </div>
</form>

<?php include $ROOT . '/ui/layout_end.php';