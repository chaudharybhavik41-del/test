<?php
/**
 * PATH: /public_html/tools/block_replace.php
 * Block Replace Tool (PHP 8.4 + Bootstrap 5)
 *
 * Features:
 * - Select a file (relative to /public_html).
 * - Paste "Search Block" and "Replace Block" (multi-line).
 * - Options: first/all matches, case sensitive, regex/plain, normalize line endings.
 * - Dry-run: show matches + unified diff.
 * - Apply: atomic write + backup to /public_html/_backups/<file>.<Ymd_His>.bak
 *
 * Security:
 * - Requires login + permission: dev.files.patch
 * - Uses central CSRF (/includes/csrf.php) with fallback validator
 * - Whitelists path to /public_html
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Kolkata');

const BASE_DIR   = __DIR__ . '/..';       // /public_html
const BACKUP_DIR = BASE_DIR . '/_backups';// /public_html/_backups

// Safe esc that tolerates null/ints/floats
if (!function_exists('h')) {
  function h(string|int|float|null $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

$errors = []; $notice = null; $diffText = ''; $resultPreview = null; $matches = 0; $fileSize = 0;

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/rbac.php';
  // CSRF (central) optional include
  @require_once __DIR__ . '/../includes/csrf.php';

  require_login();
  require_permission('dev.files.patch');

  @mkdir(BACKUP_DIR, 0775, true);
} catch (Throwable $e) {
  $errors[] = "Bootstrap failed: ".$e->getMessage();
}

// ---- helpers ----
function require_csrf_local(): void {
  if (function_exists('require_csrf')) { require_csrf(); return; }
  $posted = $_POST['csrf_token'] ?? '';
  $session = function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? ($_SESSION['csrf_token']=bin2hex(random_bytes(32))));
  if (!is_string($posted) || !hash_equals((string)$session, (string)$posted)) {
    http_response_code(400); exit('CSRF token mismatch.');
  }
}

function csrf_value(): string {
  return function_exists('csrf_token')
    ? (string)csrf_token()
    : (string)($_SESSION['csrf_token'] ?? ($_SESSION['csrf_token']=bin2hex(random_bytes(32))));
}

function ensure_inside(string $path, string $base = BASE_DIR): void {
  $rb = rtrim(str_replace('\\','/', realpath($base) ?: $base), '/') . '/';
  $rp = str_replace('\\','/', realpath($path) ?: $path);
  if (!str_starts_with($rp, $rb)) throw new RuntimeException('Path escapes base.');
}

function list_files_flat(string $base = BASE_DIR): array {
  // shallow-ish listing (two levels) to help users, but you can paste any relative path
  $out = [];
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  $max = 1000;
  foreach ($it as $f) {
    if (count($out) >= $max) break;
    if ($f->isFile()) {
      $rel = substr(str_replace('\\','/',$f->getPathname()), strlen(str_replace('\\','/',$base)) + 1);
      // skip system dirs
      if (preg_match('~^(includes/|_backups/|uploads/)~', $rel)) { /* allow includes? keep it visible but safe */ }
      $out[] = $rel;
    }
  }
  sort($out, SORT_STRING);
  return $out;
}

// Simple unified diff for preview
function unified_diff(string $a, string $b, int $context = 3): string {
  $aLines = preg_split("~\r\n|\n|\r~", $a);
  $bLines = preg_split("~\r\n|\n|\r~", $b);
  $m = count($aLines); $n = count($bLines);
  $lcs = array_fill(0,$m+1,array_fill(0,$n+1,0));
  for($i=$m-1;$i>=0;$i--) for($j=$n-1;$j>=0;$j--) {
    $lcs[$i][$j] = ($aLines[$i] === $bLines[$j]) ? $lcs[$i+1][$j+1] + 1 : max($lcs[$i+1][$j], $lcs[$i][$j+1]);
  }
  $ops = []; $i=0; $j=0;
  while($i<$m && $j<$n){
    if($aLines[$i] === $bLines[$j]){ $ops[]=['=',$aLines[$i]]; $i++; $j++; }
    elseif($lcs[$i+1][$j] >= $lcs[$i][$j+1]){ $ops[]=['-',$aLines[$i]]; $i++; }
    else { $ops[]=['+',$bLines[$j]]; $j++; }
  }
  while($i<$m){ $ops[]=['-',$aLines[$i++]]; }
  while($j<$n){ $ops[]=['+',$bLines[$j++]]; }

  // build hunks
  $out = [];
  $aLn=1; $bLn=1;
  $buffer=[]; $aStart=1; $bStart=1; $in=false; $ctx=0;
  $flush=function() use(&$out,&$buffer,&$aStart,&$bStart){
    if(!$buffer) return;
    $aCount=0; $bCount=0;
    foreach($buffer as [$t]){ if($t!='+') $aCount++; if($t!=='-') $bCount++; }
    $out[] = "@@ -{$aStart},{$aCount} +{$bStart},{$bCount} @@";
    foreach($buffer as [$t,$l]){ $out[] = $t.$l; }
    $buffer=[]; 
  };
  foreach($ops as [$t,$l]){
    if($t==='='){
      if($in){
        if($ctx<3){ $buffer[]=[' ',$l]; $ctx++; }
        else { $flush(); $in=false; }
      }
      $aLn++; $bLn++;
    } else {
      if(!$in){ $in=true; $ctx=0; $aStart=$aLn; $bStart=$bLn; }
      $buffer[]=[$t,$l];
      if($t==='-') $aLn++; else $bLn++;
      $ctx=0;
    }
  }
  $flush();
  return implode("\n", $out);
}

// ---- Controller ----
$action    = $_POST['action'] ?? '';
$relPath   = trim((string)($_POST['rel_path'] ?? '')); // relative to /public_html
$searchRaw = (string)($_POST['search_block'] ?? '');
$replaceRaw= (string)($_POST['replace_block'] ?? '');
$scope     = ($_POST['scope'] ?? 'first') === 'all' ? 'all' : 'first';
$isRegex   = isset($_POST['is_regex']);
$caseSens  = isset($_POST['case_sensitive']);
$normEOL   = isset($_POST['normalize_eol']);

try {
  if ($action !== '') { require_csrf_local(); }

  if ($action === 'dryrun' || $action === 'apply') {
    if ($relPath === '') throw new RuntimeException('Please provide file path.');
    $abs = BASE_DIR . '/' . ltrim($relPath, '/');
    ensure_inside($abs);
    if (!is_file($abs) || !is_readable($abs)) throw new RuntimeException('File not found or not readable.');
    $original = (string)file_get_contents($abs);
    $fileSize = filesize($abs) ?: 0;

    // normalize EOLs if requested
    $src = $original;
    if ($normEOL) {
      $src = str_replace(["\r\n","\r"], "\n", $src);
      $search = str_replace(["\r\n","\r"], "\n", $searchRaw);
      $replace = str_replace(["\r\n","\r"], "\n", $replaceRaw);
    } else {
      $search = $searchRaw;
      $replace = $replaceRaw;
    }

    if ($search === '') throw new RuntimeException('Search Block is empty.');

    if ($isRegex) {
      // delimit with ~ and escape delimiter in search?
      $pattern = '~' . $search . '~' . ($caseSens ? '' : 'i') . 's';
      if (@preg_match($pattern, '') === false) {
        throw new RuntimeException('Invalid regex pattern.');
      }
      if ($scope === 'first') {
        $replaced = preg_replace($pattern, $replace, $src, 1, $matches);
      } else {
        $replaced = preg_replace($pattern, $replace, $src, -1, $matches);
      }
    } else {
      // plain text block replace
      $haystack = $src;
      $needle   = $search;
      if (!$caseSens) {
        // case-insensitive: manual loop
        $offset=0; $replaced=''; $matches=0; 
        $lenN = strlen($needle); 
        $lenH = strlen($haystack);
        while ($offset < $lenH) {
          $pos = stripos($haystack, $needle, $offset);
          if ($pos === false) { $replaced .= substr($haystack, $offset); break; }
          $replaced .= substr($haystack, $offset, $pos - $offset) . $replace;
          $matches++;
          $offset = $pos + $lenN;
          if ($scope === 'first') { $replaced .= substr($haystack, $offset); break; }
        }
        if ($matches === 0) $replaced = $haystack;
      } else {
        if ($scope === 'first') {
          $pos = strpos($haystack, $needle);
          if ($pos !== false) {
            $replaced = substr($haystack, 0, $pos) . $replace . substr($haystack, $pos + strlen($needle));
            $matches = 1;
          } else {
            $replaced = $haystack;
            $matches = 0;
          }
        } else {
          $matches = substr_count($haystack, $needle);
          $replaced = ($matches>0) ? str_replace($needle, $replace, $haystack) : $haystack;
        }
      }
    }

    // show result
    $resultPreview = [
      'path'    => $relPath,
      'matches' => $matches,
      'changed' => ($src !== $replaced),
      'size_before' => strlen($original),
      'size_after'  => strlen($replaced),
    ];
    $diffText = unified_diff($src, $replaced);

    if ($action === 'apply') {
      if ($matches === 0) {
        throw new RuntimeException('No matches found. Nothing to replace. (Tip: try toggling case sensitivity or regex mode.)');
      }
      // atomic write + backup of original on disk content (not normalized preview)
      $backupName = BACKUP_DIR . '/' . str_replace(['/','\\'], '_', $relPath) . '.' . date('Ymd_His') . '.bak';
      if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0775, true);
      if (file_put_contents($backupName, $original) === false) throw new RuntimeException('Backup failed.');
      $tmp = tempnam(dirname($abs), 'patch_');
      if ($tmp === false) throw new RuntimeException('Temp file error.');
      // write with original EOL policy: if normalized, we keep replaced as-is (LF). If you want original style, add conversion here.
      file_put_contents($tmp, $replaced);
      @chmod($tmp, 0664);
      if (!rename($tmp, $abs)) { @unlink($tmp); throw new RuntimeException('Failed to write target file.'); }
      $notice = "Patched successfully. Backup: " . basename($backupName);
    }
  }
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}

$csrf = csrf_value();
$filesList = [];
try { $filesList = list_files_flat(); } catch(Throwable $e){ /* ignore */ }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Block Replace Tool</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .mono{font-family:ui-monospace,Menlo,Consolas,monospace}
    .code{white-space:pre;overflow:auto}
    .add{background:#e6ffed}
    .del{background:#ffeef0}
  </style>
</head>
<body class="p-3">
<div class="container-lg">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Block Replace Tool</h1>
    <span class="badge bg-secondary">PHP 8.4</span>
  </div>

  <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>'.h($er).'</li>'; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="row g-3 mb-3">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

    <div class="col-12">
      <label class="form-label">File (relative to /public_html)</label>
      <div class="input-group">
        <input class="form-control" name="rel_path" value="<?= h($relPath) ?>" placeholder="e.g. modules/items/items_list.php">
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filehelp">Browse…</button>
      </div>
      <div class="collapse mt-2" id="filehelp" style="max-height:260px;overflow:auto;">
        <div class="list-group">
          <?php foreach ($filesList as $f): ?>
            <button class="list-group-item list-group-item-action" type="button" onclick="document.querySelector('input[name=rel_path]').value='<?= h($f) ?>';">
              <?= h($f) ?>
            </button>
          <?php endforeach; ?>
          <?php if (!$filesList): ?><div class="list-group-item text-muted">No files listed (you can still type the path).</div><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <label class="form-label">Search Block</label>
      <textarea name="search_block" class="form-control mono" rows="10" placeholder="Paste the exact block to find (or regex if enabled)"><?= h($searchRaw) ?></textarea>
    </div>
    <div class="col-lg-6">
      <label class="form-label">Replace With</label>
      <textarea name="replace_block" class="form-control mono" rows="10" placeholder="Paste the replacement block"><?= h($replaceRaw) ?></textarea>
    </div>

    <div class="col-12 d-flex flex-wrap gap-3">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="is_regex" id="optRegex" <?= $isRegex?'checked':'' ?>>
        <label class="form-check-label" for="optRegex">Use regex (PCRE /s <?= $caseSens ? '' : '+ i' ?>)</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="case_sensitive" id="optCase" <?= $caseSens?'checked':'' ?>>
        <label class="form-check-label" for="optCase">Case sensitive</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="normalize_eol" id="optEOL" <?= $normEOL?'checked':'' ?>>
        <label class="form-check-label" for="optEOL">Normalize line endings (CRLF/CR → LF)</label>
      </div>
      <div>
        <select class="form-select" name="scope">
          <option value="first" <?= $scope==='first'?'selected':'' ?>>Replace first match</option>
          <option value="all"   <?= $scope==='all'?'selected':''   ?>>Replace all matches</option>
        </select>
      </div>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-outline-secondary" name="action" value="dryrun">Dry-run (Preview)</button>
      <button class="btn btn-primary" name="action" value="apply" onclick="return confirm('Apply changes to file? A backup will be created.')">Apply</button>
    </div>
  </form>

  <?php if ($resultPreview): ?>
    <div class="card mb-3">
      <div class="card-header"><strong>Result</strong></div>
      <div class="card-body">
        <div><strong>File:</strong> <span class="mono"><?= h($resultPreview['path']) ?></span></div>
        <div><strong>Matches:</strong> <?= (int)$resultPreview['matches'] ?></div>
        <div><strong>Changed:</strong> <?= $resultPreview['changed'] ? 'Yes' : 'No' ?></div>
        <div><strong>Size:</strong> <?= (int)$resultPreview['size_before'] ?> → <?= (int)$resultPreview['size_after'] ?> bytes</div>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Unified Diff (preview)</div>
      <div class="card-body mono code">
        <?php
          if ($diffText==='') echo '<div class="text-muted">No differences</div>';
          else {
            foreach (explode("\n",$diffText) as $ln){
              $cls = str_starts_with($ln,'+') ? 'add' : (str_starts_with($ln,'-') ? 'del' : '');
              echo '<div class="'.$cls.'">'.h($ln).'</div>';
            }
          }
        ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
