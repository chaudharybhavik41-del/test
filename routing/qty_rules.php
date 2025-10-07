<?php
/** PATH: /public_html/routing/qty_rules.php
 * CRUD for process_qty_rules and a test harness to try a rule against a bom_component.
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
$PAGE_TITLE  = 'Process Qty Rules';
$ACTIVE_MENU = 'routing.rules';

$errors = [];
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

function fetch_rules(PDO $pdo): array {
  $sql = "SELECT rqr.*, u.code AS uom_code
            FROM process_qty_rules rqr
       LEFT JOIN uom u ON u.id = rqr.result_uom_id
        ORDER BY rqr.operation_code, rqr.id DESC";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_bom_components(PDO $pdo, int $bom_id): array {
  $st = $pdo->prepare("SELECT id, part_id, description FROM bom_components WHERE bom_id=:b ORDER BY id ASC");
  $st->execute([':b'=>$bom_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function fetch_boms(PDO $pdo): array {
  $sql = "SELECT b.id, b.bom_no FROM bom b ORDER BY b.id DESC LIMIT 200";
  return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_require_token();
  $act = $_POST['action'] ?? '';
  if ($act === 'create') {
    $op = trim((string)($_POST['operation_code'] ?? ''));
    $expr = trim((string)($_POST['expr'] ?? ''));
    $uom_code = trim((string)($_POST['uom_code'] ?? ''));
    if (!$op || !$expr || !$uom_code) { $errors[] = 'Operation, expression, and UOM are required.'; }
    else {
      $uom_id = MetricService::uomIdByCode($pdo, $uom_code);
      if (!$uom_id) { $errors[] = "Unknown UOM code: $uom_code"; }
      else {
        $st = $pdo->prepare("INSERT INTO process_qty_rules (operation_code, expr, result_uom_id, required_vars_json) VALUES (:op, :ex, :u, '[]')");
        $st->execute([':op'=>$op, ':ex'=>$expr, ':u'=>$uom_id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Rule created.'];
        redirect('/routing/qty_rules.php');
      }
    }
  }
  if ($act === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("DELETE FROM process_qty_rules WHERE id=:id");
    $st->execute([':id'=>$id]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Rule deleted.'];
    redirect('/routing/qty_rules.php');
  }
  if ($act === 'test') {
    $rule_id = (int)($_POST['rule_id'] ?? 0);
    $bomc_id = (int)($_POST['bomc_id'] ?? 0);
    // Fetch rows
    $r = $pdo->prepare("SELECT * FROM process_qty_rules WHERE id=:id");
    $r->execute([':id'=>$rule_id]);
    $rule = $r->fetch(PDO::FETCH_ASSOC);
    $b = $pdo->prepare("SELECT * FROM bom_components WHERE id=:id");
    $b->execute([':id'=>$bomc_id]);
    $bc = $b->fetch(PDO::FETCH_ASSOC);
    if ($rule && $bc) {
      $vars = MetricService::varsForBomComponent($bc);
      try {
        $qty = MetricService::evalExpr((string)$rule['expr'], $vars);
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Test result: $qty"];
      } catch (Throwable $e) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>$e->getMessage()];
      }
    } else {
      $_SESSION['flash'] = ['type'=>'warning','msg'=>'Pick a valid rule and component to test.'];
    }
    redirect('/routing/qty_rules.php');
  }
}

$rules = fetch_rules($pdo);
$boms  = fetch_boms($pdo);

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="container my-3">
  <h1 class="h3"><?= e($PAGE_TITLE) ?></h1>

  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">Create rule</div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <?= csrf_hidden_input() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-md-3"><input class="form-control" name="operation_code" placeholder="CUT, WELD, PAINT"></div>
        <div class="col-md-6"><input class="form-control" name="expr" placeholder="e.g., qty * L"></div>
        <div class="col-md-2"><input class="form-control" name="uom_code" placeholder="m | m2 | kg"></div>
        <div class="col-md-1"><button class="btn btn-success w-100">Add</button></div>
      </form>
      <div class="form-text">Vars: L,W,T,D,H (meters), L_mm,W_mm,... (mm), qty, density_kg_m3. Functions: abs,round,ceil,floor,min,max,pow,pi.</div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">Rules</div>
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle">
        <thead><tr><th>ID</th><th>Operation</th><th>Expression</th><th>UOM</th><th>Test</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rules as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= e($r['operation_code']) ?></td>
              <td><code><?= e($r['expr']) ?></code></td>
              <td><?= e($r['uom_code']) ?></td>
              <td>
                <form method="post" class="d-flex align-items-center gap-2">
                  <?= csrf_hidden_input() ?>
                  <input type="hidden" name="action" value="test">
                  <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                  <select name="bomc_id" class="form-select form-select-sm" style="min-width:220px">
                    <option value="">Pick component (by latest 200 BOMs)</option>
                    <?php foreach ($boms as $b): ?>
                      <?php foreach (fetch_bom_components($pdo, (int)$b['id']) as $c): ?>
                        <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> <?= e(($c['part_id'] ?: ('#'.$c['id'])).' — '.$c['description']) ?></option>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary">Run</button>
                </form>
              </td>
              <td>
                <form method="post" onsubmit="return confirm('Delete rule?')" class="d-inline">
                  <?= csrf_hidden_input() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rules): ?><tr><td colspan="6" class="text-muted">No rules yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once $UI_PATH . '/layout_end.php'; ?>
