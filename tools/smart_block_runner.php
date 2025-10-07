<?php
/**
 * PATH: /public_html/tools/smart_block_runner.php
 * PURPOSE: Safe bulk patch runner (dry-run + execute) for files and SQL.
 *
 * Features:
 * - Paste "Instruction Block" → Dry-run → Execute
 * - File updates with backups and scoped writes
 * - Optional SQL section runs in a single DB transaction
 * - CSRF + RBAC enforced; audit logging optional
 *
 * Permissions:
 * - View/Run patches:   dev.files.smartpatch
 * - Run SQL (optional): dev.sql.run
 *
 * Kernel alignment:
 * - CSRF: verify_csrf_or_die(), csrf_field() (fallbacks included)
 * - RBAC: has_permission(), require_permission()
 * - DB:   db()
 * - Helpers: h(), set_flash(), render_flash()
 * - Audit: audit_log(PDO $pdo, string $entity, string $action, ?int $rowId, $payload)
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

date_default_timezone_set('Asia/Kolkata');

define('BASE_DIR', realpath(__DIR__ . '/..'));        // /public_html
define('BACKUP_DIR', BASE_DIR . '/_backups');         // backups folder
define('APP_NAME',  'Smart Block Runner');

// ---------- includes ----------
require_once BASE_DIR . '/includes/auth.php';
require_once BASE_DIR . '/includes/db.php';
require_once BASE_DIR . '/includes/rbac.php';
require_once BASE_DIR . '/includes/csrf.php';
require_once BASE_DIR . '/includes/helpers.php';
require_once BASE_DIR . '/includes/audit.php';

// ---------- RBAC gate ----------
require_login();
require_permission('dev.files.smartpatch');

// ---------- helpers ----------
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// CSRF helpers (prefer kernel, keep safe fallback)
function csrf_value(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}
function csrf_field_local(): string {
  if (function_exists('csrf_field')) return (string)csrf_field();
  return '<input type="hidden" name="csrf_token" value="'.e(csrf_value()).'">';
}
function require_csrf_local(): void {
  if (function_exists('verify_csrf_or_die')) { verify_csrf_or_die(); return; }
  $posted  = (string)($_POST['csrf_token'] ?? '');
  $session = csrf_value();
  if ($posted === '' || !hash_equals($session, $posted)) { http_response_code(400); exit('CSRF token mismatch'); }
}

function ensure_inside(string $target, string $base): void {
  $realBase = realpath($base) ?: $base;
  $realT = realpath($target);
  if ($realT === false) $realT = $target;
  $realBase = rtrim(str_replace('\\','/',$realBase),'/') . '/';
  $realT = str_replace('\\','/', $realT);
  if (!str_starts_with($realT, $realBase)) {
    throw new RuntimeException('Resolved path escapes allowed base: ' . $target);
  }
}

// -------- Allowed path prefixes (belt & suspenders) --------
$ALLOWED_PREFIXES = [
  realpath(BASE_DIR) ?: BASE_DIR,
  realpath(BASE_DIR.'/includes') ?: BASE_DIR.'/includes',
  realpath(BASE_DIR.'/ui') ?: BASE_DIR.'/ui',
  realpath(BASE_DIR.'/modules') ?: BASE_DIR.'/modules',
  realpath(BASE_DIR.'/tools') ?: BASE_DIR.'/tools',
];
function is_allowed_path(string $p) : bool {
  $p = str_replace('\\','/', realpath($p) ?: $p);
  foreach ($GLOBALS['ALLOWED_PREFIXES'] as $root) {
    $root = rtrim(str_replace('\\','/', $root), '/').'/';
    if (str_starts_with($p, $root)) return true;
  }
  return false;
}

// ---------- storage ----------
@mkdir(BACKUP_DIR, 0775, true);

// ---------- DB: ensure patch log ----------
function ensure_patch_log(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `_patch_log` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      `run_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `user_id` int unsigned DEFAULT NULL,
      `user_name` varchar(191) DEFAULT NULL,
      `title` varchar(191) DEFAULT NULL,
      `files_changed` int unsigned NOT NULL DEFAULT 0,
      `sql_executed` tinyint(1) NOT NULL DEFAULT 0,
      `notes` text DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
  ");
}

// ---------- parsing ----------
/**
 * Instruction Block format:
 *
 * TITLE: <short title>            (optional)
 * DEFAULTS: REGEX: yes|no         (optional, default: no)
 * FILE_GLOB: public_html/*/*.php  (required)
 * SEARCH: <<<
 *   ...pattern or literal...
 * >>>
 * REPLACE: <<<
 *   ...replacement...
 * >>>
 * SQL: <<<
 *   -- optional; requires dev.sql.run
 *   UPDATE ...
 *   ;
 * >>>
 *
 * Notes:
 * - When DEFAULTS: REGEX: yes → SEARCH is treated as regex (PCRE, with 's' modifier).
 * - When REGEX: no → literal search/replace.
 * - Multiple FILE_GLOB lines allowed (one per line).
 */
function parse_block(string $raw): array {
  $out = [
    'title'    => '',
    'regex'    => false,
    'globs'    => [],
    'search'   => '',
    'replace'  => '',
    'sql'      => '',
  ];
  $raw = str_replace("\r\n", "\n", trim($raw));

  // Title
  if (preg_match('~^TITLE:\s*(.+)$~mi', $raw, $m)) $out['title'] = trim($m[1]);

  // Defaults: REGEX
  if (preg_match('~^DEFAULTS:\s*(.+)$~mi', $raw, $m)) {
    if (preg_match('~REGEX\s*:\s*(yes|true|1)~i', $m[1])) $out['regex'] = true;
  }

  // FILE_GLOB (allow multiple)
  if (preg_match_all('~^FILE_GLOB:\s*(.+)$~mi', $raw, $m)) {
    foreach ($m[1] as $g) {
      $g = trim($g);
      if ($g !== '') $out['globs'][] = $g;
    }
  }

  // Block extract helper
  $grab = function(string $label) use ($raw): string {
    if (preg_match('~^'.$label.':\s*<<<\n(.*?)\n>>>~ms', $raw, $m)) return (string)$m[1];
    return '';
  };

  $out['search']  = $grab('SEARCH');
  $out['replace'] = $grab('REPLACE');
  $out['sql']     = $grab('SQL');

  return $out;
}

// ---------- diff (simple) ----------
function simple_diff_preview(string $old, string $new, int $context = 2): string {
  if ($old === $new) return "No changes.\n";
  $a = explode("\n", $old);
  $b = explode("\n", $new);
  $out = [];
  $max = max(count($a), count($b));
  $changes = 0;
  for ($i=0, $ai=0, $bi=0; $i<$max; $i++, $ai++, $bi++) {
    $al = $a[$ai] ?? null;
    $bl = $b[$bi] ?? null;
    if ($al === $bl) continue;
    $changes++;
    // show small window
    $start = max(0, $ai - $context);
    $end   = min(count($a), $ai + $context + 1);
    $out[] = "@@ -".($ai+1)." @@";
    for ($j=$start; $j<$end; $j++) {
      $out[] = "− " . ($a[$j] ?? '');
    }
    $start = max(0, $bi - $context);
    $end   = min(count($b), $bi + $context + 1);
    for ($j=$start; $j<$end; $j++) {
      $out[] = "+ " . ($b[$j] ?? '');
    }
    if ($changes > 20) { $out[] = "... (truncated)"; break; }
  }
  return implode("\n", $out) . "\n";
}

// ---------- file operations ----------
function backup_file(string $path): string {
  ensure_inside($path, BASE_DIR);
  $rel = ltrim(str_replace(realpath(BASE_DIR),'', realpath($path) ?: $path), '/\\');
  $stamp = date('Ymd_His');
  $dir = BACKUP_DIR . '/' . dirname($rel);
  @mkdir($dir, 0775, true);
  $dest = BACKUP_DIR . '/' . $rel . '.' . $stamp . '.bak';
  @mkdir(dirname($dest), 0775, true);
  if (!copy($path, $dest)) throw new RuntimeException("Backup failed: $path");
  return $dest;
}

// ---------- SQL runner ----------
function run_sql_block(PDO $pdo, string $sqlRaw): int {
  if (trim($sqlRaw) === '') return 0;
  require_permission('dev.sql.run');

  // Split on ; end of line, tolerate whitespace
  $stmts = array_filter(array_map('trim', preg_split('~;\s*\n~', $sqlRaw) ?: []));
  if (!$stmts) return 0;

  $pdo->beginTransaction();
  try {
    $count = 0;
    foreach ($stmts as $s) {
      if ($s === '') continue;
      $pdo->exec($s);
      $count++;
    }
    $pdo->commit();
    return $count;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

// ---------- controller ----------
$tab   = $_GET['tab'] ?? 'run';
$action = $_POST['action'] ?? '';
$rawBlock = (string)($_POST['block'] ?? '');
$dryResult = [];
$execResult = [];
$restoreMsg = null;

// Handle Restore
if ($tab === 'restore' && $action === 'restore') {
  require_csrf_local();
  $bfile = (string)($_POST['backup_file'] ?? '');
  if ($bfile === '' || !is_file($bfile)) {
    set_flash('danger', 'Backup file not found.');
  } else {
    // Map backup path back to live path
    $live = str_replace(BACKUP_DIR, BASE_DIR, preg_replace('~\.\d{8}_\d{6}\.bak$~', '', $bfile));
    if (!is_allowed_path($live)) {
      set_flash('danger', 'Restore target outside allowed trees.');
    } else {
      ensure_inside($live, BASE_DIR);
      @mkdir(dirname($live), 0775, true);
      if (!copy($bfile, $live)) {
        set_flash('danger', 'Restore failed.');
      } else {
        audit_log(db(), 'smart_patch', 'restore', null, ['src'=>$bfile, 'dst'=>$live]);
        set_flash('success', 'Restored: ' . e($live));
      }
    }
  }
  header('Location: ?tab=restore'); exit;
}

// Handle Dry-run / Execute
if (in_array($action, ['dryrun','execute'], true)) {
  require_csrf_local();

  $parsed = parse_block($rawBlock);
  $title  = $parsed['title'] ?: 'Untitled Patch';
  $regex  = (bool)$parsed['regex'];
  $globs  = $parsed['globs'];
  $search = $parsed['search'];
  $replace= $parsed['replace'];
  $sqlRaw = $parsed['sql'];

  if (!$globs) set_flash('danger', 'FILE_GLOB is required.');
  if ($search === '' && $sqlRaw === '') set_flash('danger', 'Nothing to do. Provide SEARCH/REPLACE and/or SQL.');
  if (!empty($_SESSION['flash'])) { header('Location: ?tab=run'); exit; }

  // Expand & filter files
  $files = [];
  foreach ($globs as $g) {
    $matches = glob(BASE_DIR . '/' . ltrim($g, '/'), GLOB_NOSORT) ?: [];
    foreach ($matches as $m) {
      if (is_file($m) && is_allowed_path($m)) $files[] = $m;
    }
  }
  $files = array_values(array_unique($files));

  $totalChanges = 0;
  $previews = [];

  foreach ($files as $path) {
    $old = file_get_contents($path);
    if ($old === false) { $previews[] = ['path'=>$path, 'error'=>'Read failed']; continue; }

    if ($search !== '') {
      if ($regex) {
        $count = 0;
        // DOTALL + UTF8 + ungreedy safe default
        $new = preg_replace('~'.$search.'~us', $replace, $old, -1, $count);
      } else {
        $count = substr_count($old, $search);
        $new = ($count > 0) ? str_replace($search, $replace, $old) : $old;
      }
    } else {
      $count = 0;
      $new = $old;
    }

    if ($count > 0) {
      $totalChanges += $count;
      $diff = simple_diff_preview($old, $new);
      $previews[] = ['path'=>$path, 'count'=>$count, 'diff'=>$diff, 'new'=>$new];
    } else {
      $previews[] = ['path'=>$path, 'count'=>0];
    }
  }

  if ($action === 'dryrun') {
    $dryResult = [
      'title' => $title,
      'regex' => $regex,
      'files' => $previews,
      'total_changes' => $totalChanges,
      'sql_present' => trim($sqlRaw) !== '',
    ];
  } else {
    // execute: write files with backups + run SQL
    $pdo = db();
    ensure_patch_log($pdo);

    $changedFiles = 0;
    $written = [];
    foreach ($previews as $p) {
      if (($p['count'] ?? 0) > 0) {
        $path = $p['path'];
        ensure_inside($path, BASE_DIR);
        // backup
        $bak = backup_file($path);
        // write atomically
        $tmp = tempnam(dirname($path), 'sbr_');
        file_put_contents($tmp, $p['new']);
        @chmod($tmp, 0664);
        if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException("Write failed: $path"); }
        $written[] = ['path'=>$path, 'backup'=>$bak, 'count'=>$p['count']];
        $changedFiles++;
      }
    }

    $sqlRan = 0;
    if (trim($sqlRaw) !== '') {
      // May throw if no permission
      $sqlRan = run_sql_block($pdo, $sqlRaw);
    }

    // Log
    $user = current_user();
    $st = $pdo->prepare("INSERT INTO `_patch_log`
      (`run_at`,`user_id`,`user_name`,`title`,`files_changed`,`sql_executed`,`notes`)
      VALUES (NOW(), :uid, :uname, :title, :fc, :sx, :notes)");
    $st->execute([
      ':uid'   => (int)($user['id'] ?? 0),
      ':uname' => (string)($user['name'] ?? ''),
      ':title' => $title,
      ':fc'    => $changedFiles,
      ':sx'    => $sqlRan > 0 ? 1 : 0,
      ':notes' => $changedFiles . ' files; SQL stmts: ' . $sqlRan,
    ]);

    // Optional global audit
    try { audit_log($pdo, 'smart_patch', 'run', (int)$pdo->lastInsertId(), ['title'=>$title,'files'=>$changedFiles,'sql'=>$sqlRan]); } catch (Throwable $e) {}

    set_flash('success', "Executed: {$changedFiles} file(s) updated; SQL statements: {$sqlRan}");
    $execResult = ['written'=>$written, 'sql'=>$sqlRan];
  }
}

// ---------- restore list ----------
$backupList = [];
if ($tab === 'restore') {
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(BACKUP_DIR, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.bak')) {
      $backupList[] = $f->getPathname();
    }
  }
  rsort($backupList);
}

// ---------- view ----------
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{padding:20px}
    .mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}
    .diff{white-space:pre-wrap}
    .path{font-family:ui-monospace,Menlo,Consolas,monospace}
  </style>
</head>
<body>
<div class="container-lg">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><?= e(APP_NAME) ?></h1>
    <div class="btn-group">
      <a class="btn btn-outline-secondary <?= $tab==='run'?'active':'' ?>" href="?tab=run">Run</a>
      <a class="btn btn-outline-secondary <?= $tab==='restore'?'active':'' ?>" href="?tab=restore">Restore</a>
    </div>
  </div>

  <?php render_flash(); ?>

  <?php if ($tab === 'run'): ?>
    <form method="post" class="mb-3">
      <?= csrf_field_local() ?>
      <label class="form-label">Instruction Block</label>
      <textarea name="block" class="form-control mono" rows="18" placeholder="TITLE: My Patch
DEFAULTS: REGEX: no
FILE_GLOB: public_html/*/*_list.php
SEARCH: <<<
:q
>>>
REPLACE: <<<
:q1
>>>
SQL: <<<
-- optional
-- UPDATE table SET ...
;
>>>"><?= e($rawBlock) ?></textarea>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-outline-primary" name="action" value="dryrun">Dry-run</button>
        <button class="btn btn-primary" name="action" value="execute"
          onclick="return confirm('Execute changes? Backups will be created automatically.')">Execute</button>
      </div>
    </form>

    <?php if ($dryResult): ?>
      <div class="card mb-3">
        <div class="card-header">Dry-run Summary</div>
        <div class="card-body">
          <div><strong>Title:</strong> <?= e($dryResult['title']) ?></div>
          <div><strong>Regex Mode:</strong> <?= $dryResult['regex'] ? 'Yes' : 'No' ?></div>
          <div><strong>Total replacements:</strong> <?= (int)$dryResult['total_changes'] ?></div>
          <div><strong>SQL present:</strong> <?= $dryResult['sql_present'] ? 'Yes' : 'No' ?></div>
        </div>
      </div>

      <?php foreach ($dryResult['files'] as $f): ?>
        <div class="card mb-3">
          <div class="card-header">
            <span class="path"><?= e(str_replace(BASE_DIR.'/','', $f['path'] ?? '')) ?></span>
            <?php if (($f['count'] ?? 0) > 0): ?>
              <span class="badge bg-success ms-2"><?= (int)$f['count'] ?> change(s)</span>
            <?php else: ?>
              <span class="badge bg-secondary ms-2">no changes</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($f['error'])): ?>
            <div class="card-body text-danger"><?= e($f['error']) ?></div>
          <?php elseif (($f['count'] ?? 0) > 0): ?>
            <div class="card-body mono diff"><?= e($f['diff']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($execResult): ?>
      <div class="card mb-3">
        <div class="card-header">Execution Result</div>
        <div class="card-body">
          <div><strong>Files updated:</strong> <?= count($execResult['written'] ?? []) ?></div>
          <div><strong>SQL statements executed:</strong> <?= (int)($execResult['sql'] ?? 0) ?></div>
        </div>
        <?php if (!empty($execResult['written'])): ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($execResult['written'] as $w): ?>
              <li class="list-group-item">
                <div class="small text-muted">Backup: <?= e(str_replace(BASE_DIR.'/','', $w['backup'])) ?></div>
                <div class="path"><?= e(str_replace(BASE_DIR.'/','', $w['path'])) ?></div>
                <span class="badge bg-success"><?= (int)$w['count'] ?> change(s)</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'restore'): ?>
    <div class="card">
      <div class="card-header">Backups</div>
      <div class="card-body">
        <?php if (!$backupList): ?>
          <div class="text-muted">No backups found in <code><?= e(str_replace(BASE_DIR.'/','', BACKUP_DIR)) ?></code>.</div>
        <?php else: ?>
          <form method="post" class="row g-2">
            <?= csrf_field_local() ?>
            <input type="hidden" name="action" value="restore">
            <div class="col-12">
              <label class="form-label">Choose Backup File</label>
              <select name="backup_file" class="form-select mono" size="12">
                <?php foreach ($backupList as $f): ?>
                  <option value="<?= e($f) ?>"><?= e(str_replace(BASE_DIR.'/','', $f)) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Latest first. Selecting a backup restores it to its original live path.</div>
            </div>
            <div class="col-12">
              <button class="btn btn-warning" onclick="return confirm('Restore selected backup to live path?')">Restore Selected</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</div>
</body>
</html>