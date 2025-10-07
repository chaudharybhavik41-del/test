<?php
/**
 * PATH: /public_html/tools/code_pattern_manager.php
 * Code Pattern Manager (PHP 8.4 + Bootstrap 5)
 *
 * - Define templates like {CAT}-{SUB}-{YYYY}-{SEQ4}
 * - Preview with variables (JSON), or Generate (increments stored counter)
 * - reset_scope: 'year' (default) or 'none'
 *
 * Security:
 * - Requires login + permission: codepatterns.manage
 * - Uses central CSRF: /includes/csrf.php
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Kolkata');

/** Safe escape: tolerate null/ints/floats */
if (!function_exists('h')) {
  function h(string|int|float|null $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

$errors=[]; $notice=null; $preview=''; $pdo=null;

try{
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/rbac.php';
  require_once __DIR__ . '/../includes/csrf.php';

  require_login();
  require_permission('codepatterns.manage');

  $pdo = db();

  // Schema (idempotent, allows existing installs)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS code_patterns (
      pattern_key VARCHAR(100) PRIMARY KEY,
      template VARCHAR(255) NOT NULL,
      reset_scope ENUM('year','none') NOT NULL DEFAULT 'year'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS code_counters (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      pattern_key VARCHAR(100) NOT NULL,
      scope_key VARCHAR(32) NOT NULL,
      seq_value INT UNSIGNED NOT NULL DEFAULT 0,
      UNIQUE KEY(pattern_key, scope_key),
      CONSTRAINT fk_cc_pat FOREIGN KEY(pattern_key) REFERENCES code_patterns(pattern_key) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
} catch(Throwable $e){
  $errors[]="Bootstrap failed: ".$e->getMessage();
}

/** Resolve non-sequence tokens + custom vars */
function resolve_template(string $tpl, array $vars): string {
  $now = new DateTimeImmutable('now');
  $repl = [
    '{YYYY}' => $now->format('Y'),
    '{YY}'   => $now->format('y'),
    '{MM}'   => $now->format('m'),
    '{DD}'   => $now->format('d'),
  ];
  foreach ($vars as $k=>$v) {
    $repl['{'.strtoupper((string)$k).'}'] = strtoupper((string)$v);
  }
  return strtr($tpl, $repl); // leaves {SEQn} for later
}
function seq_pad(int $num, int $digits): string {
  return str_pad((string)$num, $digits, '0', STR_PAD_LEFT);
}
function scope_key(string $resetScope): string {
  return $resetScope === 'none' ? 'global' : (new DateTimeImmutable('now'))->format('Y');
}

$action = $_POST['action'] ?? '';
$csrf = function_exists('csrf_token') ? csrf_token() : '';

try {
  if ($action !== '') {
    if (function_exists('require_csrf')) require_csrf();
  }

  if ($action === 'add') {
    $key  = trim((string)($_POST['pattern_key'] ?? ''));
    $tpl  = trim((string)($_POST['template'] ?? ''));
    $scopeSel = ($_POST['reset_scope'] ?? 'year');
    $scope = $scopeSel === 'none' ? 'none' : 'year';
    if ($key === '' || $tpl === '') throw new RuntimeException('Key and Template are required.');
    $st = $pdo->prepare("REPLACE INTO code_patterns(pattern_key, template, reset_scope) VALUES(?,?,?)");
    $st->execute([$key, $tpl, $scope]);
    $notice = 'Pattern saved.';
  }

  if ($action === 'preview') {
    $tpl  = trim((string)($_POST['template'] ?? ''));
    $varsJson = trim((string)($_POST['vars'] ?? '{}'));
    $vars = json_decode($varsJson, true);
    if (!is_array($vars)) $vars = [];
    $resolved = resolve_template($tpl, $vars);
    // Replace {SEQn} with 1 (no DB increment)
    $preview = preg_replace_callback('~\{SEQ(\d)\}~', fn($m)=>seq_pad(1, (int)$m[1]), $resolved) ?? '';
  }

  if ($action === 'generate') {
    $key  = trim((string)($_POST['pattern_key'] ?? ''));
    $varsJson = trim((string)($_POST['vars'] ?? '{}'));
    $vars = json_decode($varsJson, true);
    if (!is_array($vars)) $vars = [];

    $st = $pdo->prepare("SELECT template, reset_scope FROM code_patterns WHERE pattern_key=?");
    $st->execute([$key]);
    $pat = $st->fetch(PDO::FETCH_ASSOC);
    if (!$pat) throw new RuntimeException('Pattern not found for key: ' . $key);

    $tpl   = (string)($pat['template'] ?? '');
    $reset = (string)($pat['reset_scope'] ?? 'year');

    $resolved = resolve_template($tpl, $vars);
    $scope = scope_key($reset);

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("SELECT seq_value FROM code_counters WHERE pattern_key=? AND scope_key=? FOR UPDATE");
      $st->execute([$key, $scope]);
      $cur = $st->fetch(PDO::FETCH_ASSOC);
      $seq = $cur ? (int)$cur['seq_value'] + 1 : 1;

      // Fill {SEQn}
      $code = preg_replace_callback('~\{SEQ(\d)\}~', fn($m)=>seq_pad($seq, (int)$m[1]), $resolved);
      if ($code === null) throw new RuntimeException('Failed to render code.');

      if ($cur) {
        $u = $pdo->prepare("UPDATE code_counters SET seq_value=? WHERE pattern_key=? AND scope_key=?");
        $u->execute([$seq, $key, $scope]);
      } else {
        $i = $pdo->prepare("INSERT INTO code_counters(pattern_key, scope_key, seq_value) VALUES(?,?,?)");
        $i->execute([$key, $scope, $seq]);
      }

      $pdo->commit();
      $preview = (string)$code;
      $notice = 'Generated successfully.';
    } catch (Throwable $e) {
      $pdo->rollBack();
      throw $e;
    }
  }
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}

// Load patterns (safe even if none)
$patterns = [];
try {
  if ($pdo instanceof PDO) {
    $patterns = $pdo->query("SELECT pattern_key, template, reset_scope FROM code_patterns ORDER BY pattern_key")
                    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Code Pattern Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>.mono{font-family:ui-monospace,Menlo,Consolas,monospace}</style>
</head>
<body class="p-3">
<div class="container-lg">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Code Pattern Manager</h1>
    <span class="badge bg-secondary">PHP 8.4</span>
  </div>

  <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>'.h($er).'</li>'; ?></ul></div>
  <?php endif; ?>

  <!-- Create / Update Pattern -->
  <div class="card mb-3">
    <div class="card-header"><strong>Create / Update Pattern</strong></div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="col-md-3">
          <label class="form-label">Pattern Key</label>
          <input class="form-control" name="pattern_key" placeholder="e.g. IND, INQ, PO">
        </div>
        <div class="col-md-6">
          <label class="form-label">Template</label>
          <input class="form-control mono" name="template" placeholder="{CAT}-{SUB}-{YYYY}-{SEQ4}">
        </div>
        <div class="col-md-2">
          <label class="form-label">Reset Scope</label>
          <select name="reset_scope" class="form-select">
            <option value="year">Yearly</option>
            <option value="none">Never</option>
          </select>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button class="btn btn-primary w-100" name="action" value="add">Save</button>
        </div>
      </form>
      <div class="form-text">Tokens: {YYYY} {YY} {MM} {DD} {SEQn} (n=2..9) + custom vars like {CAT} {SUB} from JSON.</div>
    </div>
  </div>

  <div class="row g-3">
    <!-- Patterns list -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Patterns</strong></div>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead><tr><th>Key</th><th>Template</th><th>Reset</th></tr></thead>
            <tbody>
              <?php foreach ($patterns as $p): ?>
                <tr>
                  <td class="mono"><?= h($p['pattern_key'] ?? '') ?></td>
                  <td class="mono"><?= h($p['template'] ?? '') ?></td>
                  <td><?= h($p['reset_scope'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$patterns): ?>
                <tr><td colspan="3" class="text-muted">No patterns yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Preview / Generate -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Preview / Generate</strong></div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="col-12">
              <label class="form-label">Variables (JSON)</label>
              <textarea class="form-control mono" name="vars" rows="4" placeholder='{"CAT":"IND","SUB":"HQ"}'>{"CAT":"IND","SUB":"HQ"}</textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Quick Preview (Template)</label>
              <input class="form-control mono" name="template" placeholder="{CAT}-{SUB}-{YYYY}-{SEQ4}">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-outline-secondary" name="action" value="preview">Preview Template</button>
            </div>
          </form>
          <hr>
          <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <div class="col-6">
              <label class="form-label">Pattern Key</label>
              <input class="form-control" name="pattern_key" placeholder="e.g. IND">
            </div>
            <div class="col-6">
              <label class="form-label">Variables (JSON)</label>
              <input class="form-control mono" name="vars" value='{"CAT":"IND","SUB":"HQ"}'>
            </div>
            <div class="col-12">
              <button class="btn btn-primary" name="action" value="generate">Generate Next Code</button>
            </div>
          </form>
        </div>
        <?php if ($preview !== ''): ?>
          <div class="card-footer">
            <div><strong>Result:</strong> <span class="mono"><?= h($preview) ?></span></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>