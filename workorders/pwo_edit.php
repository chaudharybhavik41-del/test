<?php
/** PATH: /public_html/workorders/pwo_edit.php
 * Conflict-free: helper functions are prefixed (pwo_get_*), no redeclare risk.
 * Also fixes machine label concatenation.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';
require_once $ROOT . '/includes/helpers.php';

/* ---- Polyfills ---- */
if (!function_exists('e')) { function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
if (!function_exists('redirect')) { function redirect(string $url): void { header('Location: '.$url); exit; } }
if (!function_exists('csrf_hidden_input')) {
  function csrf_hidden_input(): string { $t=function_exists('csrf_token')?csrf_token():''; return '<input type="hidden" name="csrf" value="'.e($t).'">'; }
}
if (!function_exists('csrf_require_token')) {
  function csrf_require_token(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
      $tok = $_POST['csrf'] ?? '';
      if (function_exists('csrf_token') && $tok !== csrf_token()) { http_response_code(400); echo 'Invalid CSRF token.'; exit; }
    }
  }
}

require_login();
require_permission('workorders.manage');
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo "Missing id"; exit; }

$UI_PATH     = $ROOT . '/ui';
$PAGE_TITLE  = 'Edit PWO #'.$id;
$ACTIVE_MENU = 'production.pwo';

// actions
$errors = [];
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_require_token();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'save_details') {
      $assign_type   = $_POST['assign_type'] ?? 'company';
      $contractor_id = $assign_type==='contractor' ? ((int)($_POST['contractor_id'] ?? 0) ?: null) : null;
      $work_center_id= (int)($_POST['work_center_id'] ?? 0) ?: null;
      $status        = $_POST['status'] ?? 'planned';
      $plan_start    = trim((string)($_POST['plan_start_date'] ?? '')) ?: null;
      $plan_end      = trim((string)($_POST['plan_end_date'] ?? '')) ?: null;
      $planned_qty   = (float)($_POST['planned_qty'] ?? 0);
      $planned_prod_qty = (float)($_POST['planned_prod_qty'] ?? 0);
      $sql = "UPDATE process_work_orders
                 SET assign_type=:at, contractor_id=:ct, work_center_id=:wc,
                     status=:st, plan_start_date=:ps, plan_end_date=:pe,
                     planned_qty=:pq, planned_prod_qty=:ppq
               WHERE id=:id";
      $st = $pdo->prepare($sql);
      $st->execute([
        ':at'=>$assign_type, ':ct'=>$contractor_id, ':wc'=>$work_center_id,
        ':st'=>$status, ':ps'=>$plan_start, ':pe'=>$plan_end,
        ':pq'=>$planned_qty, ':ppq'=>$planned_prod_qty, ':id'=>$id
      ]);
      $_SESSION['flash'] = ['type'=>'success','msg'=>'PWO updated.'];
      redirect('/workorders/pwo_edit.php?id='.$id);
    }
    if ($action === 'add_resource') {
      $machine_id = (int)($_POST['machine_id'] ?? 0) ?: null;
      $headcount  = (int)($_POST['headcount'] ?? 0) ?: null;
      $notes      = trim((string)($_POST['notes'] ?? '')) ?: null;
      $sql = "INSERT INTO pwo_resources (pwo_id, machine_id, headcount, notes) VALUES (:p, :m, :h, :n)";
      $st = $pdo->prepare($sql);
      $st->execute([':p'=>$id, ':m'=>$machine_id, ':h'=>$headcount, ':n'=>$notes]);
      $_SESSION['flash'] = ['type'=>'success','msg'=>'Resource added.'];
      redirect('/workorders/pwo_edit.php?id='.$id.'#resources');
    }
    if ($action === 'delete_resource') {
      $rid = (int)($_POST['rid'] ?? 0);
      $st = $pdo->prepare("DELETE FROM pwo_resources WHERE id=:r AND pwo_id=:p");
      $st->execute([':r'=>$rid, ':p'=>$id]);
      $_SESSION['flash'] = ['type'=>'success','msg'=>'Resource removed.'];
      redirect('/workorders/pwo_edit.php?id='.$id.'#resources');
    }
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }
}

// load data (prefixed names to avoid collision)
if (!function_exists('pwo_get_pwo')) {
  function pwo_get_pwo(PDO $pdo, int $id): array {
    $sql = "SELECT pwo.*,
                   pr.name AS process_name,
                   bc.part_id, bc.description AS comp_desc
              FROM process_work_orders pwo
        INNER JOIN processes pr ON pr.id = pwo.process_id
        INNER JOIN bom_components bc ON bc.id = pwo.bom_component_id
             WHERE pwo.id = :id";
    $st = $pdo->prepare($sql);
    $st->execute([':id'=>$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo "PWO not found"; exit; }
    return $row;
  }
}
if (!function_exists('pwo_get_work_centers')) {
  function pwo_get_work_centers(PDO $pdo): array {
    return $pdo->query("SELECT id, code, name FROM work_centers WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
}
if (!function_exists('pwo_get_contractors')) {
  function pwo_get_contractors(PDO $pdo): array {
    return $pdo->query("SELECT id, name FROM parties WHERE type='contractor' AND status=1 AND deleted_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
}
if (!function_exists('pwo_get_machines')) {
  function pwo_get_machines(PDO $pdo): array {
    return $pdo->query("SELECT id, machine_id, name FROM machines ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
}
if (!function_exists('pwo_get_resources')) {
  function pwo_get_resources(PDO $pdo, int $pwo_id): array {
    $st = $pdo->prepare("SELECT r.id, r.machine_id, r.headcount, r.notes, m.name AS machine_name
                           FROM pwo_resources r
                      LEFT JOIN machines m ON m.id = r.machine_id
                          WHERE r.pwo_id = :p");
    $st->execute([':p'=>$pwo_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

$pwo = pwo_get_pwo($pdo, $id);
$wcs = pwo_get_work_centers($pdo);
$cts = pwo_get_contractors($pdo);
$mcs = pwo_get_machines($pdo);
$res = pwo_get_resources($pdo, $id);

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="container my-3">
  <h1 class="h3"><?= e($PAGE_TITLE) ?></h1>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post">
        <?= csrf_hidden_input() ?>
        <input type="hidden" name="action" value="save_details">

        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label">Component</label>
            <div class="form-control-plaintext"><?= e(($pwo['part_id'] ?: ('#'.$pwo['bom_component_id'])).' — '.$pwo['comp_desc']) ?></div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Process</label>
            <div class="form-control-plaintext"><?= e($pwo['process_name']) ?></div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Work Center</label>
            <select name="work_center_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($wcs as $wc): ?>
                <option value="<?= (int)$wc['id'] ?>" <?= ((int)$pwo['work_center_id']===(int)$wc['id'])?'selected':'' ?>>
                  <?= e($wc['code'].' — '.$wc['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Status</label>
            <?php $st = (string)$pwo['status']; ?>
            <select name="status" class="form-select">
              <?php foreach (['planned','in_progress','hold','completed','closed'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $st===$s?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$s))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Assignment</label>
            <?php $at = (string)$pwo['assign_type']; ?>
            <select name="assign_type" class="form-select" id="assign_type" onchange="toggleContractor()">
              <option value="company" <?= $at==='company'?'selected':'' ?>>Company</option>
              <option value="contractor" <?= $at==='contractor'?'selected':'' ?>>Contractor</option>
            </select>
          </div>
          <div class="col-md-3" id="contractor_wrap" style="display:none;">
            <label class="form-label">Contractor</label>
            <select name="contractor_id" class="form-select">
              <option value="">— Select contractor —</option>
              <?php foreach ($cts as $ct): ?>
                <option value="<?= (int)$ct['id'] ?>" <?= ((int)$pwo['contractor_id']===(int)$ct['id'])?'selected':'' ?>>
                  <?= e($ct['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Plan Start</label>
            <input type="text" name="plan_start_date" class="form-control" value="<?= e($pwo['plan_start_date']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Plan End</label>
            <input type="text" name="plan_end_date" class="form-control" value="<?= e($pwo['plan_end_date']) ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Planned Qty</label>
            <input type="number" step="0.000001" name="planned_qty" class="form-control" value="<?= e($pwo['planned_qty']) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Planned Prod Qty</label>
            <input type="number" step="0.000001" name="planned_prod_qty" class="form-control" value="<?= e($pwo['planned_prod_qty']) ?>">
          </div>
        </div>

        <div class="mt-3">
          <button class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary" href="/workorders/pwo_list.php">Back</a>
        </div>
      </form>
    </div>
  </div>

  <a name="resources"></a>
  <div class="card mt-4">
    <div class="card-header">Resources</div>
    <div class="card-body">
      <form method="post" class="row g-2 align-items-end">
        <?= csrf_hidden_input() ?>
        <input type="hidden" name="action" value="add_resource">
        <div class="col-md-5">
          <label class="form-label">Machine</label>
          <select name="machine_id" class="form-select">
            <option value="">— Select machine —</option>
            <?php foreach ($mcs as $m): ?>
              <option value="<?= (int)$m['id'] ?>"><?= e($m['machine_id'].' — '.$m['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Headcount</label>
          <input type="number" name="headcount" class="form-control" min="0">
        </div>
        <div class="col-md-4">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control">
        </div>
        <div class="col-md-1">
          <button class="btn btn-success w-100">Add</button>
        </div>
      </form>

      <div class="table-responsive mt-3">
        <table class="table table-sm table-bordered">
          <thead><tr><th>#</th><th>Machine</th><th>Headcount</th><th>Notes</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($res as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= e($r['machine_name'] ?: ('#'.$r['machine_id'])) ?></td>
                <td><?= e($r['headcount']) ?></td>
                <td><?= e($r['notes']) ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Remove this resource?')" style="display:inline;">
                    <?= csrf_hidden_input() ?>
                    <input type="hidden" name="action" value="delete_resource">
                    <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$res): ?>
              <tr><td colspan="5" class="text-muted">No resources added yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function toggleContractor(){
  var at = document.getElementById('assign_type').value;
  var wrap = document.getElementById('contractor_wrap');
  if (wrap) wrap.style.display = (at==='contractor')?'block':'none';
}
toggleContractor();
</script>

<?php
require_once $UI_PATH . '/layout_end.php';
?>