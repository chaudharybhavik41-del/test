<?php
/** PATH: /public_html/bom/routing_form.php
 * Adds per-op variables management for count-based ops (e.g., drilling).
 * You can add variables like: holes, hole_dia_mm, holes_per_meter, etc.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';
require_once $ROOT . '/includes/helpers.php';
require_once $ROOT . '/includes/services/MetricService.php';

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
require_permission('routing.manage');

$pdo = db();
$UI_PATH     = $ROOT . '/ui';
$PAGE_TITLE  = 'Routing Editor';
$ACTIVE_MENU = 'bom.routing';

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// data helpers
function fetch_boms(PDO $pdo): array {
  $sql = "SELECT b.id, b.bom_no FROM bom b ORDER BY b.id DESC LIMIT 200";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_components(PDO $pdo, int $bom_id): array {
  $st = $pdo->prepare("SELECT id, part_id, description, length_mm, width_mm, thickness_mm, qty FROM bom_components WHERE bom_id=:b ORDER BY id ASC");
  $st->execute([':b'=>$bom_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_processes(PDO $pdo): array {
  return $pdo->query("SELECT id, code, name FROM processes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_work_centers(PDO $pdo): array {
  try { return $pdo->query("SELECT id, code, name FROM work_centers WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return []; }
}
function fetch_rules(PDO $pdo): array {
  return $pdo->query("SELECT id, operation_code, expr FROM process_qty_rules ORDER BY operation_code, id DESC")->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_ops(PDO $pdo, int $component_id): array {
  $sql = "SELECT ro.id, ro.seq_no, ro.process_id, ro.work_center_id, ro.inspection_gate, ro.process_qty_rule_id,
                 pr.code AS process_code, pr.name AS process_name,
                 wc.code AS wc_code, wc.name AS wc_name,
                 rqr.operation_code, rqr.expr, rqr.result_uom_id,
                 u.code AS uom_code
            FROM routing_ops ro
      INNER JOIN processes pr ON pr.id = ro.process_id
       LEFT JOIN work_centers wc ON wc.id = ro.work_center_id
       LEFT JOIN process_qty_rules rqr ON rqr.id = ro.process_qty_rule_id
       LEFT JOIN uom u ON u.id = rqr.result_uom_id
           WHERE ro.bom_component_id = :c
        ORDER BY ro.seq_no ASC, ro.id ASC";
  $st = $pdo->prepare($sql); $st->execute([':c'=>$component_id]); return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_op_vars(PDO $pdo, int $op_id): array {
  try {
    $st = $pdo->prepare("SELECT id, name, value FROM routing_op_vars WHERE routing_op_id=:id ORDER BY name");
    $st->execute([':id'=>$op_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return []; }
}

// actions
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_require_token();
  $act = $_POST['action'] ?? '';
  $bom_id = (int)($_POST['bom_id'] ?? 0);
  $component_id = (int)($_POST['component_id'] ?? 0);

  if ($act === 'add_op') {
    $process_id = (int)($_POST['process_id'] ?? 0);
    $seq_no = (int)($_POST['seq_no'] ?? 0);
    $work_center_id = (int)($_POST['work_center_id'] ?? 0) ?: null;
    $inspection_gate = isset($_POST['inspection_gate']) ? 1 : 0;
    $st = $pdo->prepare("INSERT INTO routing_ops (bom_component_id, process_id, seq_no, work_center_id, inspection_gate) VALUES (:c,:p,:s,:w,:g)");
    $st->execute([':c'=>$component_id, ':p'=>$process_id, ':s'=>$seq_no, ':w'=>$work_center_id, ':g'=>$inspection_gate]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Operation added.'];
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
  }

  if ($act === 'set_rule') {
    $id = (int)($_POST['id'] ?? 0);
    $rule_id = (int)($_POST['rule_id'] ?? 0) ?: null;
    $st = $pdo->prepare("UPDATE routing_ops SET process_qty_rule_id=:r WHERE id=:id AND bom_component_id=:c");
    $st->execute([':r'=>$rule_id, ':id'=>$id, ':c'=>$component_id]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Qty rule updated.'];
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
  }

  if ($act === 'add_var') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['var_name'] ?? ''));
    $value = (float)($_POST['var_value'] ?? 0);
    if ($name && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
      try {
        $st = $pdo->prepare("INSERT INTO routing_op_vars (routing_op_id, name, value) VALUES (:id,:n,:v)
                             ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $st->execute([':id'=>$id, ':n'=>$name, ':v'=>$value]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Variable saved.'];
      } catch (Throwable $e) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Save failed (routing_op_vars missing?)'];
      }
    } else {
      $_SESSION['flash'] = ['type'=>'warning','msg'=>'Invalid variable name.'];
    }
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
  }

  if ($act === 'delete_var') {
    $vid = (int)($_POST['vid'] ?? 0);
    try {
      $st = $pdo->prepare("DELETE FROM routing_op_vars WHERE id=:vid");
      $st->execute([':vid'=>$vid]);
      $_SESSION['flash'] = ['type'=>'success','msg'=>'Variable removed.'];
    } catch (Throwable $e) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Delete failed.'];
    }
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
  }

  if ($act === 'update_seq') {
    $id = (int)($_POST['id'] ?? 0);
    $seq_no = (int)($_POST['seq_no'] ?? 0);
    $sql = "UPDATE routing_ops SET seq_no=:s WHERE id=:id AND bom_component_id=:c";
    $st = $pdo->prepare($sql);
    $st->execute([':s'=>$seq_no, ':id'=>$id, ':c'=>$component_id]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Sequence updated.'];
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
  }

  if ($act === 'update_wc') {
    $id = (int)($_POST['id'] ?? 0);
    $work_center_id = (int)($_POST['work_center_id'] ?? 0) ?: null;
    $st = $pdo->prepare("UPDATE routing_ops SET work_center_id=:w WHERE id=:id AND bom_component_id=:c");
    $st->execute([':w'=>$work_center_id, ':id'=>$id, ':c'=>$component_id]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Work center updated.'];
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
  }

  if ($act === 'toggle_gate') {
    $id = (int)($_POST['id'] ?? 0);
    $gate = (int)($_POST['inspection_gate'] ?? 0);
    $st = $pdo->prepare("UPDATE routing_ops SET inspection_gate=:g WHERE id=:id AND bom_component_id=:c");
    $st->execute([':g'=>$gate, ':id'=>$id, ':c'=>$component_id]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Inspection gate updated.'];
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
  }

  if ($act === 'delete_op') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("DELETE FROM routing_ops WHERE id=:id AND bom_component_id=:c");
    $st->execute([':id'=>$id, ':c'=>$component_id]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Operation deleted.'];
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
  }
}

// GET state
$bom_id = (int)($_GET['bom_id'] ?? 0);
$component_id = (int)($_GET['component_id'] ?? 0);
$components = $bom_id ? fetch_components($pdo, $bom_id) : [];
$processes  = fetch_processes($pdo);
$work_centers = fetch_work_centers($pdo);
$rules = fetch_rules($pdo);
$ops = $component_id ? fetch_ops($pdo, $component_id) : [];

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="container my-3">
  <h1 class="h3"><?= e($PAGE_TITLE) ?></h1>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="card p-3 mb-3">
    <form method="get" class="row g-3">
      <div class="col-md-5">
        <label class="form-label">BOM</label>
        <select name="bom_id" class="form-select" onchange="this.form.submit()">
          <option value="">— Select BOM —</option>
          <?php foreach (fetch_boms($pdo) as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $bom_id===(int)$b['id']?'selected':'' ?>><?= e('#'.$b['id'].' '.$b['bom_no']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Component</label>
        <select name="component_id" class="form-select" onchange="this.form.submit()">
          <option value="">— Pick component —</option>
          <?php foreach ($components as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $component_id===(int)$c['id']?'selected':'' ?>>
              <?= e(($c['part_id'] ?: ('#'.$c['id'])) .' — '. $c['description']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <a class="btn btn-outline-primary w-100" href="/routing/qty_rules.php">Qty Rules</a>
      </div>
    </form>
  </div>

  <?php if ($component_id): ?>
  <div class="card p-3 mb-3">
    <h5 class="mb-3">Add Operation</h5>
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_hidden_input() ?>
      <input type="hidden" name="action" value="add_op">
      <input type="hidden" name="bom_id" value="<?= (int)$bom_id ?>">
      <input type="hidden" name="component_id" value="<?= (int)$component_id ?>">

      <div class="col-md-2">
        <label class="form-label">Seq</label>
        <input type="number" name="seq_no" class="form-control" min="1" value="<?= count($ops) ? (int)end($ops)['seq_no'] + 10 : 10 ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Process</label>
        <select name="process_id" class="form-select" required>
          <option value="">— Select process —</option>
          <?php foreach ($processes as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e($p['code'].' — '.$p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Work Center</label>
        <select name="work_center_id" class="form-select" required>
          <option value="">— Select work center —</option>
          <?php foreach ($work_centers as $wc): ?>
            <option value="<?= (int)$wc['id'] ?>"><?= e($wc['code'].' — '.$wc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 form-check mt-4">
        <input class="form-check-input" type="checkbox" name="inspection_gate" id="gate_add">
        <label class="form-check-label" for="gate_add">Inspection gate</label>
      </div>
      <div class="col-md-12">
        <button class="btn btn-success">Add</button>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-bordered align-middle">
      <thead><tr>
        <th style="width:70px;">Seq</th>
        <th>Process</th>
        <th style="width:220px;">Work Center</th>
        <th style="width:260px;">Qty Rule</th>
        <th style="width:220px;">Op Vars</th>
        <th style="width:140px;">Preview</th>
        <th style="width:210px;">Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach ($ops as $op): $opVars = fetch_op_vars($pdo, (int)$op['id']); ?>
          <tr>
            <td>
              <form method="post" class="d-flex gap-2">
                <?= csrf_hidden_input() ?>
                <input type="hidden" name="action" value="update_seq">
                <input type="hidden" name="bom_id" value="<?= (int)$bom_id ?>">
                <input type="hidden" name="component_id" value="<?= (int)$component_id ?>">
                <input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
                <input type="number" class="form-control form-control-sm" style="width:90px" name="seq_no" value="<?= (int)$op['seq_no'] ?>">
                <button class="btn btn-sm btn-outline-primary">Save</button>
              </form>
            </td>

            <td><?= e($op['process_code'].' — '.$op['process_name']) ?></td>

            <td>
              <form method="post" class="d-flex gap-2">
                <?= csrf_hidden_input() ?>
                <input type="hidden" name="action" value="update_wc">
                <input type="hidden" name="bom_id" value="<?= (int)$bom_id ?>">
                <input type="hidden" name="component_id" value="<?= (int)$component_id ?>">
                <input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
                <select name="work_center_id" class="form-select form-select-sm">
                  <?php foreach ($work_centers as $wc): ?>
                    <option value="<?= (int)$wc['id'] ?>" <?= ((int)$op['work_center_id']===(int)$wc['id'])?'selected':'' ?>>
                      <?= e($wc['code'].' — '.$wc['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary">Save</button>
              </form>
            </td>

            <td>
              <form method="post" class="d-flex gap-2">
                <?= csrf_hidden_input() ?>
                <input type="hidden" name="action" value="set_rule">
                <input type="hidden" name="bom_id" value="<?= (int)$bom_id ?>">
                <input type="hidden" name="component_id" value="<?= (int)$component_id ?>">
                <input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
                <select name="rule_id" class="form-select form-select-sm">
                  <option value="">— None —</option>
                  <?php foreach ($rules as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= ((int)$op['process_qty_rule_id']===(int)$r['id'])?'selected':'' ?>>
                      <?= e($r['operation_code'].': '.$r['expr']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary">Save</button>
              </form>
            </td>

            <td class="small">
              <form method="post" class="d-flex gap-2">
                <?= csrf_hidden_input() ?>
                <input type="hidden" name="action" value="add_var">
                <input type="hidden" name="bom_id" value="<?= (int)$bom_id ?>">
                <input type="hidden" name="component_id" value="<?= (int)$component_id ?>">
                <input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
                <input class="form-control form-control-sm" name="var_name" placeholder="holes" style="width:110px">
                <input class="form-control form-control-sm" name="var_value" placeholder="e.g. 12" style="width:90px">
                <button class="btn btn-sm btn-outline-success">Add</button>
              </form>
              <?php if ($opVars): ?>
                <div class="mt-2 d-flex flex-wrap gap-1">
                  <?php foreach ($opVars as $v): ?>
                    <form method="post" class="d-inline">
                      <?= csrf_hidden_input() ?>
                      <input type="hidden" name="action" value="delete_var">
                      <input type="hidden" name="bom_id" value="<?= (int)$bom_id ?>">
                      <input type="hidden" name="component_id" value="<?= (int)$component_id ?>">
                      <input type="hidden" name="vid" value="<?= (int)$v['id'] ?>">
                      <span class="badge bg-light text-dark border">
                        <?= e($v['name']) ?> = <?= e($v['value']) ?>
                        <button class="btn btn-link btn-sm text-danger p-0 ms-1">×</button>
                      </span>
                    </form>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>

            <td class="small">
              <?php if ($op['expr']): ?>
                <?php
                  try {
                    // Build preview vars: BOM dims + op vars
                    $component = null;
                    if ($component_id) {
                      $stc = $pdo->prepare("SELECT * FROM bom_components WHERE id=:id");
                      $stc->execute([':id'=>$component_id]);
                      $component = $stc->fetch(PDO::FETCH_ASSOC);
                    }
                    $vars = $component ? MetricService::varsForBomComponent($component) : [];
                    $vars = MetricService::mergeOpVars($pdo, (int)$op['id'], $vars);
                    $q = MetricService::evalExpr($op['expr'], $vars);
                    echo e($q).' '.e($op['uom_code'] ?: '');
                  } catch (Throwable $e) {
                    echo '<span class="text-danger">! formula error</span>';
                  }
                ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>

            <td class="d-flex gap-2">
              <a class="btn btn-sm btn-outline-success" href="/bom/routing_pwo_actions.php?action=ensure&p=<?= (int)$op['id'] ?>&bom_id=<?= (int)$bom_id ?>&component_id=<?= (int)$component_id ?>&csrf=<?= urlencode(function_exists('csrf_token')?csrf_token():'') ?>">Ensure PWO</a>
              <form method="post" onsubmit="return confirm('Delete this operation?')" class="d-inline">
                <?= csrf_hidden_input() ?>
                <input type="hidden" name="action" value="delete_op">
                <input type="hidden" name="bom_id" value="<?= (int)$bom_id ?>">
                <input type="hidden" name="component_id" value="<?= (int)$component_id ?>">
                <input type="hidden" name="id" value="<?= (int)$op['id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>

          </tr>
        <?php endforeach; ?>
        <?php if (!$ops): ?><tr><td colspan="7" class="text-muted">No operations yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once $UI_PATH . '/layout_end.php'; ?>
