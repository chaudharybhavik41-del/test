<?php
/**
 * PATH: /public_html/tools/diff_viewer.php
 * Unified Diff Viewer (PHP 8.4 + Bootstrap 5)
 * Security: requires dev.diff.view, reuses central CSRF, path whitelist = /public_html
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Kolkata');

const BASE_DIR = __DIR__ . '/..'; // /public_html

// Safe esc
if (!function_exists('h')) { function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

$errors = []; $notice = null; $diffText = '';

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/rbac.php';
  require_once __DIR__ . '/../includes/csrf.php';
  require_login();
  require_permission('dev.diff.view');
} catch (Throwable $e) { $errors[] = "Bootstrap failed: ".$e->getMessage(); }

function ensure_inside(string $path, string $base=BASE_DIR): void {
  $rb = rtrim(str_replace('\\','/', realpath($base) ?: $base),'/').'/';
  $rp = str_replace('\\','/', realpath($path) ?: $path);
  if (!str_starts_with($rp, $rb)) throw new RuntimeException('Path escapes base.');
}

function read_lines(string $p): array {
  if (!is_file($p) || !is_readable($p)) throw new RuntimeException('File missing/unreadable: '.$p);
  return preg_split("~\r\n|\n|\r~", (string)file_get_contents($p));
}

/** Minimal Myers-style LCS diff to unified format */
function unified_diff(array $a, array $b, int $context = 3): string {
  // LCS table
  $m = count($a); $n = count($b);
  $lcs = array_fill(0,$m+1,array_fill(0,$n+1,0));
  for($i=$m-1;$i>=0;$i--) for($j=$n-1;$j>=0;$j--) $lcs[$i][$j]=($a[$i]===$b[$j])? $lcs[$i+1][$j+1]+1 : max($lcs[$i+1][$j],$lcs[$i][$j+1]);
  // Backtrack
  $ops = [];
  $i=0; $j=0;
  while($i<$m && $j<$n){
    if($a[$i]===$b[$j]){ $ops[]=['=',$a[$i]]; $i++; $j++; }
    elseif($lcs[$i+1][$j] >= $lcs[$i][$j+1]){ $ops[]=['-',$a[$i]]; $i++; }
    else{ $ops[]=['+',$b[$j]]; $j++; }
  }
  while($i<$m){ $ops[]=['-',$a[$i++]]; }
  while($j<$n){ $ops[]=['+',$b[$j++]]; }

  // Build hunks
  $hunks = []; $hunk=[]; $aLn=1; $bLn=1; $aStart=1; $bStart=1; $aCount=0; $bCount=0; $in=false; $ctx=0;
  $flush = function() use (&$hunks,&$hunk,&$aStart,&$bStart,&$aCount,&$bCount,&$in){ if($in){ $hunks[]=["@$@$ -$aStart,$aCount +$bStart,$bCount @@",$hunk]; } $hunk=[]; $in=false; $aCount=$bCount=0; };
  foreach($ops as [$tag,$line]){
    if($tag==='='){
      if($in){
        if($ctx<$context){ $hunk[]=[' ',$line]; $aCount++; $bCount++; $aLn++; $bLn++; $ctx++; }
        else { $flush(); $ctx=0; $aStart=$aLn; $bStart=$bLn; }
      } else {
        $ctx = min($ctx+1,$context);
      }
      $aLn++; $bLn++;
    } else {
      if(!$in){ $in=true; $ctx=0; $aStart=$aLn; $bStart=$bLn; }
      if($tag==='-'){ $hunk[]=['-',$line]; $aCount++; $aLn++; $ctx=0; }
      else { $hunk[]=['+',$line]; $bCount++; $bLn++; $ctx=0; }
    }
  }
  $flush();

  // Format
  $out = [];
  foreach($hunks as [$hdr,$lines]){
    $out[] = $hdr;
    foreach($lines as [$t,$l]){
      $out[] = $t . $l;
    }
  }
  return implode("\n",$out);
}

$pathA = trim($_POST['path_a'] ?? '');
$pathB = trim($_POST['path_b'] ?? '');
$action = $_POST['action'] ?? '';

try{
  if ($action==='diff') {
    require_csrf();
    if ($pathA==='' || $pathB==='') throw new RuntimeException('Select both files.');
    $absA = BASE_DIR . '/' . ltrim($pathA,'/');
    $absB = BASE_DIR . '/' . ltrim($pathB,'/');
    ensure_inside($absA); ensure_inside($absB);
    $a = read_lines($absA);
    $b = read_lines($absB);
    $diffText = unified_diff($a,$b,3);
    if ($diffText==='') $diffText = "(No differences)";
  }
} catch(Throwable $e){ $errors[] = $e->getMessage(); }

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Unified Diff Viewer</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>.mono{font-family:ui-monospace,Menlo,Consolas,monospace}.code{white-space:pre;overflow:auto}.add{background:#e6ffed}.del{background:#ffeef0}</style>
</head>
<body class="p-3">
<div class="container-lg">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Unified Diff Viewer</h1><span class="badge bg-secondary">PHP 8.4</span>
  </div>
  <?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $er) echo '<li>'.h($er).'</li>'; ?></ul></div><?php endif; ?>
  <form method="post" class="row g-3 mb-3">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <div class="col-lg-6">
      <label class="form-label">File A (relative to /public_html)</label>
      <input class="form-control" name="path_a" placeholder="e.g. modules/items/items_list.php" value="<?= h($pathA) ?>">
    </div>
    <div class="col-lg-6">
      <label class="form-label">File B (relative to /public_html)</label>
      <input class="form-control" name="path_b" placeholder="e.g. modules/items/items_list.php.bak" value="<?= h($pathB) ?>">
    </div>
    <div class="col-12">
      <button class="btn btn-primary" name="action" value="diff">Show Diff</button>
    </div>
  </form>
  <?php if($diffText!==''): ?>
    <div class="card">
      <div class="card-header">Unified Diff</div>
      <div class="card-body mono code">
        <?php
          foreach (explode("\n",$diffText) as $ln){
            $cls=''; if(str_starts_with($ln,'+')) $cls='add'; elseif(str_starts_with($ln,'-')) $cls='del';
            echo '<div class="'.$cls.'">'.h($ln).'</div>';
          }
        ?>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>