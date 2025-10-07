<?php
/** PATH: /public_html/maintenance_alloc/allocations_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();
require_permission('machines.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id       = (int)($_GET['id'] ?? 0);
$editing  = $id > 0;

/**
 * Return the current active allocation (status='issued') for a machine,
 * optionally excluding a specific allocation id (when editing).
 */
function current_active_allocation(PDO $pdo, int $machine_id, ?int $exclude_id = null): ?array {
  if ($machine_id <= 0) return null;

  if ($exclude_id) {
    $st = $pdo->prepare(
      "SELECT a.*, p.name AS contractor_name
         FROM machine_allocations a
         LEFT JOIN parties p ON p.id = a.contractor_id
        WHERE a.machine_id = ? AND a.status = 'issued' AND a.id <> ?
        LIMIT 1"
    );
    $st->execute([$machine_id, $exclude_id]);
  } else {
    $st = $pdo->prepare(
      "SELECT a.*, p.name AS contractor_name
         FROM machine_allocations a
         LEFT JOIN parties p ON p.id = a.contractor_id
        WHERE a.machine_id = ? AND a.status = 'issued'
        LIMIT 1"
    );
    $st->execute([$machine_id]);
  }

  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/** Simple code generator (unchanged logic) */
function next_alloc_code(PDO $pdo): string {
  $y = date('Y');
  $lock = "alloc_code_$y";
  $pdo->query("SELECT GET_LOCK('$lock', 10)");
  try {
    $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(alloc_code, 10) AS UNSIGNED)) FROM machine_allocations WHERE alloc_code LIKE ?");
    $st->execute(["AL-$y-%"]);
    $next = ((int)$st->fetchColumn()) + 1;
    return sprintf("AL-%s-%04d", $y, $next);
  } finally {
    $pdo->query("SELECT RELEASE_LOCK('$lock')");
  }
}

/** Base form model */
$alloc = [
  'alloc_code'       => '',
  'machine_id'       => (int)($_GET['machine_id'] ?? 0),
  'contractor_id'    => 0,
  'alloc_date'       => date('Y-m-d'),
  'expected_return'  => null,
  'meter_issue'      => null,
  'remarks'          => null,
  'status'           => 'issued',
];

/** Load when editing */
if ($editing) {
  $st = $pdo->prepare("SELECT * FROM machine_allocations WHERE id = ?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    // Preserve existing values; fill any missing with defaults
    $alloc = $row + $alloc;
  }
}

/** Dropdown data */
$machines = $pdo->query("SELECT id, CONCAT(machine_id,' - ',name) label FROM machines ORDER BY machine_id")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

$contractors = $pdo->query("SELECT id, CONCAT(code,' - ',name) label FROM parties WHERE type='contractor' ORDER BY name")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);

/** Handle POST */
$errors = [];
$ok     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require_token();

  // Map POST → model (keep existing logic/fields)
  $alloc['machine_id']        = (int)($_POST['machine_id'] ?? 0);
  $alloc['contractor_id']     = (int)($_POST['contractor_id'] ?? 0);
  $alloc['alloc_date']        = (string)($_POST['alloc_date'] ?? date('Y-m-d'));
  $alloc['expected_return']   = (($_POST['expected_return'] ?? '') === '' ? null : (string)$_POST['expected_return']);
  $alloc['remarks']           = (trim((string)($_POST['remarks'] ?? '')) ?: null);
  $alloc['meter_issue']       = (($_POST['meter_issue'] ?? '') === '' ? null : (float)$_POST['meter_issue']);

  if (!$alloc['machine_id'])    { $errors[] = 'Machine is required'; }
  if (!$alloc['contractor_id']) { $errors[] = 'Contractor is required'; }

  // === Conflict check: block double-issue ===
  // Only when creating a NEW allocation. Editing can keep current 'issued' record.
  $machineId = (int)$alloc['machine_id'];
  $existing  = current_active_allocation($pdo, $machineId, $editing ? $id : null);
  if (!$editing && $existing) {
    $errors[] =
      "Machine is already issued to " .
      htmlspecialchars($existing['contractor_name'] ?? ('#'.$existing['contractor_id'])) .
      " since " . htmlspecialchars($existing['alloc_date'] ?? '?') .
      ". Return it before issuing again.";
  }
  // === End conflict check ===

  if (!$errors) {
    $pdo->beginTransaction();
    try {
      if ($editing) {
        $pdo->prepare(
          "UPDATE machine_allocations
              SET machine_id = ?, contractor_id = ?, alloc_date = ?, expected_return = ?, remarks = ?, meter_issue = ?, updated_at = NOW()
            WHERE id = ?"
        )->execute([
          $alloc['machine_id'],
          $alloc['contractor_id'],
          $alloc['alloc_date'],
          $alloc['expected_return'],
          $alloc['remarks'],
          $alloc['meter_issue'],
          $id
        ]);
      } else {
        $code = next_alloc_code($pdo);
        $pdo->prepare(
          "INSERT INTO machine_allocations
             (alloc_code, machine_id, contractor_id, alloc_date, expected_return, remarks, meter_issue, created_by, created_at)
           VALUES
             (?,          ?,          ?,             ?,          ?,                ?,       ?,           ?,          NOW())"
        )->execute([
          $code,
          $alloc['machine_id'],
          $alloc['contractor_id'],
          $alloc['alloc_date'],
          $alloc['expected_return'],
          $alloc['remarks'],
          $alloc['meter_issue'],
          (int)($_SESSION['user_id'] ?? 0)
        ]);
        $id = (int)$pdo->lastInsertId();
        $editing = true;

        // Reflect generated code back into model for the view title
        $alloc['alloc_code'] = $code;
      }

      $pdo->commit();
      $ok = 'Saved.';
    } catch (Throwable $e) {
      $pdo->rollBack();
      $errors[] = 'Save failed: ' . $e->getMessage();
    }
  }
}

$PAGE_TITLE  = $editing ? ("Allocation " . (string)($alloc['alloc_code'] ?? '')) : "Issue Machine";
$ACTIVE_MENU = 'machines.list';

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?= htmlspecialchars($PAGE_TITLE) ?></h1>

  <?php
  // Optional UI banner: show current active allocation for selected machine
  if (!empty($alloc['machine_id'])):
    $conf = current_active_allocation($pdo, (int)$alloc['machine_id'], $editing ? $id : null);
    if ($conf): ?>
      <div class="alert alert-warning py-2 mb-0" role="alert" style="min-width:320px;">
        Currently issued to <strong><?= htmlspecialchars($conf['contractor_name'] ?? ('#'.$conf['contractor_id'])) ?></strong>
        since <?= htmlspecialchars((string)($conf['alloc_date'] ?? '?')) ?>.
        A return is required before a new issue.
      </div>
  <?php endif; endif; ?>

  <div class="d-flex gap-2">
    <a class="btn btn-light btn-sm" href="/maintenance_alloc/allocations_list.php">Back</a>
    <?php if ($editing && ($alloc['status'] === 'issued')): ?>
      <a class="btn btn-success btn-sm" href="/maintenance_alloc/allocations_return.php?id=<?= (int)$id ?>">Return</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars((string)$e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php elseif (!empty($ok)): ?>
  <div class="alert alert-success"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_hidden_input() ?>

  <div class="col-md-4">
    <label class="form-label">Machine</label>
    <select name="machine_id" class="form-select" required>
      <option value="">— choose —</option>
      <?php foreach ($machines as $mid => $label): ?>
        <option value="<?= (int)$mid ?>" <?= ((int)$alloc['machine_id'] === (int)$mid) ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$label) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-4">
    <label class="form-label">Contractor</label>
    <select name="contractor_id" class="form-select" required>
      <option value="">— choose —</option>
      <?php foreach ($contractors as $cid => $label): ?>
        <option value="<?= (int)$cid ?>" <?= ((int)$alloc['contractor_id'] === (int)$cid) ? 'selected' : '' ?>>
          <?= htmlspecialchars((string)$label) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Contractors come from Parties. Add/edit there if missing.</div>
  </div>

  <div class="col-md-2">
    <label class="form-label">Issue Date</label>
    <input type="date" name="alloc_date" class="form-control" value="<?= htmlspecialchars((string)$alloc['alloc_date']) ?>">
  </div>

  <div class="col-md-2">
    <label class="form-label">Expected Return</label>
    <input type="date" name="expected_return" class="form-control" value="<?= htmlspecialchars((string)($alloc['expected_return'] ?? '')) ?>">
  </div>

  <div class="col-md-3">
    <label class="form-label">Meter @ Issue</label>
    <input type="number" step="0.01" name="meter_issue" class="form-control" value="<?= htmlspecialchars((string)($alloc['meter_issue'] ?? '')) ?>">
  </div>

  <div class="col-md-9">
    <label class="form-label">Remarks</label>
    <input name="remarks" class="form-control" maxlength="255" value="<?= htmlspecialchars((string)($alloc['remarks'] ?? '')) ?>">
  </div>

  <div class="col-12 text-end">
    <button class="btn btn-primary"><?= $editing ? 'Save' : 'Issue' ?></button>
  </div>
</form>

<?php include __DIR__ . '/../ui/layout_end.php';
