<?php
/** PATH: /public_html/workorders/pwo_form.php
 * Uses bom_components.part_id + bom_components.description
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';
require_once $ROOT . '/includes/helpers.php';
require_once $ROOT . '/includes/services/WorkOrderService.php';

/* ---- Safe fallbacks ---- */
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
$UI_PATH     = $ROOT . '/ui';
$PAGE_TITLE  = 'Generate Process Work Orders';
$ACTIVE_MENU = 'production.pwo';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// Dropdown helpers
function fetch_boms(PDO $pdo): array {
  $sql = "SELECT b.id,
                 b.bom_no,
                 p.name AS project_name,
                 prt.name AS client_name
            FROM bom b
       LEFT JOIN projects p   ON p.id = b.project_id
       LEFT JOIN parties  prt ON prt.id = p.client_party_id
        ORDER BY b.id DESC
           LIMIT 200";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_components(PDO $pdo, int $bom_id): array {
  $st = $pdo->prepare("SELECT id, part_id, description FROM bom_components WHERE bom_id=:b ORDER BY id ASC");
  $st->execute([':b'=>$bom_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_contractors(PDO $pdo): array {
  $sql = "SELECT id, name FROM parties WHERE type='contractor' AND status=1 AND deleted_at IS NULL ORDER BY name";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

$errors = [];
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'generate') {
  csrf_require_token();
  $bom_id       = (int)($_POST['bom_id'] ?? 0);
  $component_id = (int)($_POST['component_id'] ?? 0) ?: null;
  $assign_type  = $_POST['assign_type'] ?? 'company';
  $contractor_id= $assign_type === 'contractor' ? (int)($_POST['contractor_id'] ?? 0) ?: null : null;
  $plan_start   = trim((string)($_POST['plan_start_date'] ?? ''));
  $plan_end     = trim((string)($_POST['plan_end_date'] ?? ''));

  if ($bom_id <= 0) $errors[] = "Select a BOM.";
  if ($assign_type === 'contractor' && !$contractor_id) $errors[] = "Select a contractor for contractor assignment.";
  if ($plan_start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $plan_start)) $errors[] = "Plan Start must be YYYY-MM-DD.";
  if ($plan_end !== ''   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $plan_end))   $errors[] = "Plan End must be YYYY-MM-DD.";
  if (!$errors) {
      try {
          $overrides = [
            'assign_type'     => $assign_type,
            'contractor_id'   => $contractor_id,
            'plan_start_date' => $plan_start ?: null,
            'plan_end_date'   => $plan_end   ?: null,
          ];
          $result = WorkOrderService::bulkGenerateForBom($pdo, $bom_id, $component_id, $overrides);
          $_SESSION['flash'] = [
            'type' => 'success',
            'msg'  => "PWOs generated. Created: {$result['created']}, Skipped: {$result['skipped']}."
          ];
          redirect('/workorders/pwo_form.php?bom_id='.$bom_id.($component_id?('&component_id='.$component_id):''));
      } catch (Throwable $e) {
          $errors[] = $e->getMessage();
      }
  }
}

$bom_id = (int)($_GET['bom_id'] ?? 0);
$component_id_get = (int)($_GET['component_id'] ?? 0);
$components  = $bom_id ? fetch_components($pdo, $bom_id) : [];
$contractors = fetch_contractors($pdo);
$boms = fetch_boms($pdo);
?>
<div class="container my-3">
  <h1 class="h3"><?= e($PAGE_TITLE) ?></h1>
  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="card p-3">
    <?= csrf_hidden_input() ?>

    <div class="row g-3">
      <div class="col-md-5">
        <label class="form-label">BOM</label>
        <select name="bom_id" id="bom_id" class="form-select" required
                onchange="window.location='?bom_id='+encodeURIComponent(this.value)">
          <option value="">— Select BOM —</option>
          <?php foreach ($boms as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $bom_id===(int)$b['id']?'selected':'' ?>>
              <?= e('#'.$b['id'].' '.$b['bom_no'].' — '.$b['project_name'].' / '.$b['client_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Component (optional)</label>
        <select name="component_id" class="form-select"
                onchange="window.location='?bom_id=<?= (int)$bom_id ?>&component_id='+encodeURIComponent(this.value)">
          <option value="">— All components —</option>
          <?php foreach ($components as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $component_id_get===(int)$c['id']?'selected':'' ?>>
              <?= e(($c['part_id'] ?: ('#'.$c['id'])) .' — '. $c['description']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Assignment</label>
        <select name="assign_type" class="form-select" required id="assign_type" onchange="toggleContractor()">
          <option value="company">Company</option>
          <option value="contractor" <?= (($_POST['assign_type'] ?? '')==='contractor')?'selected':'' ?>>Contractor</option>
        </select>
      </div>

      <div class="col-md-4" id="contractor_wrap" style="display:none;">
        <label class="form-label">Contractor</label>
        <select name="contractor_id" class="form-select">
          <option value="">— Select contractor —</option>
          <?php foreach ($contractors as $ct): ?>
            <option value="<?= (int)$ct['id'] ?>"><?= e($ct['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Plan Start (YYYY-MM-DD)</label>
        <input type="text" name="plan_start_date" class="form-control" placeholder="2025-10-05">
      </div>
      <div class="col-md-4">
        <label class="form-label">Plan End (YYYY-MM-DD)</label>
        <input type="text" name="plan_end_date" class="form-control" placeholder="2025-10-12">
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" type="submit" name="do" value="generate">Generate PWOs</button>
      <a class="btn btn-outline-secondary" href="/workorders/pwo_list.php">Go to PWO list</a>
    </div>
  </form>
</div>

<script>
function toggleContractor(){
  var at = document.getElementById('assign_type').value;
  document.getElementById('contractor_wrap').style.display = (at==='contractor')?'block':'none';
}
toggleContractor();
</script>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
