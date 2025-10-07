<?php
/** PATH: /public_html/dev/tools/export_code.php
 * PURPOSE: Create a small zip bundle of code files (default: php/inc/phtml) for external review.
 * OUTPUT : Writes zip into /public_html/dev/share/ and prints a link + summary.
 * USAGE  :
 *   - /dev/tools/export_code.php
 *   - /dev/tools/export_code.php?since=2025-10-01&max_mb=10
 *   - /dev/tools/export_code.php?include=crm,purchase&max_mb=8
 *   - /dev/tools/export_code.php?exts=php,inc,phtml,css,js&max_mb=12
 *   - /dev/tools/export_code.php?key=YOURSECRET   (if $RUN_KEY is set)
 */

declare(strict_types=1);
ini_set('memory_limit', '512M');
@set_time_limit(180);

header('Content-Type: text/plain; charset=utf-8');

/* ---- Security knob (optional) ----
 * Set a secret here to require ?key=... to run the exporter.
 * Leave empty ("") to disable the requirement.
 */
$RUN_KEY = ''; // e.g. 'ems-2025-secret'; then call with ?key=ems-2025-secret
if ($RUN_KEY !== '') {
  $k = (string)($_GET['key'] ?? '');
  if (!hash_equals($RUN_KEY, $k)) { http_response_code(403); exit("Forbidden\n"); }
}

/* ---- Paths ---- */
$TOOLS = __DIR__;                   // /public_html/dev/tools
$DEV   = dirname($TOOLS);           // /public_html/dev
$PUB   = dirname($DEV);             // /public_html
$OUT   = $PUB . '/dev/share';       // output folder (public, but PHP disabled via .htaccess)
if (!is_dir($OUT) && !@mkdir($OUT, 0775, true)) {
  http_response_code(500); exit("Cannot create output dir: $OUT\n");
}

/* ---- Params ---- */
$exts     = array_filter(array_map('strtolower', array_map('trim', explode(',', (string)($_GET['exts'] ?? 'php,inc,phtml')))));
$include  = array_filter(array_map(fn($s)=>trim($s,'/ '), explode(',', (string)($_GET['include'] ?? '')))); // path prefixes under public_html
$exclude  = array_filter(array_map(fn($s)=>trim($s,'/ '), explode(',', (string)($_GET['exclude'] ?? 'vendor,uploads,storage,cache,logs,node_modules,.git,dev/share,dev/_backups,.well-known'))));
$sinceStr = (string)($_GET['since'] ?? ''); // YYYY-MM-DD or epoch
$maxMB    = max(1, (int)($_GET['max_mb'] ?? 10)); // default 10MB budget (approx by uncompressed size)
$name     = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($_GET['name'] ?? ''));

$sinceTs = 0;
if ($sinceStr !== '') {
  if (preg_match('/^\d{10}$/', $sinceStr)) {
    $sinceTs = (int)$sinceStr;
  } else {
    $ts = strtotime($sinceStr);
    if ($ts !== false) $sinceTs = $ts;
  }
}

if (empty($exts)) { $exts = ['php','inc','phtml']; }

$maxBytes = $maxMB * 1024 * 1024;

/* ---- Helpers ---- */
function path_rel(string $abs, string $root): string {
  return ltrim(str_replace($root, '', $abs), '/');
}
function starts_with(string $hay, string $needle): bool {
  return strncmp($hay, $needle, strlen($needle)) === 0;
}
function is_allowed_ext(string $file, array $exts): bool {
  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  return in_array($ext, $exts, true);
}
function excluded(string $rel, array $ex): bool {
  foreach ($ex as $e) {
    $e = rtrim($e, '/');
    if ($e === '') continue;
    if (starts_with($rel, $e.'/') || $rel === $e) return true;
  }
  return false;
}
function included_by_prefix(string $rel, array $incs): bool {
  if (!$incs) return true; // no include filter = include everything
  foreach ($incs as $i) {
    $i = rtrim($i, '/');
    if ($i === '') continue;
    if (starts_with($rel, $i.'/') || $rel === $i) return true;
  }
  return false;
}

/* ---- Collect candidates ---- */
$rii = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($PUB, FilesystemIterator::SKIP_DOTS)
);

$files = []; // [ [rel, full, size, mtime], ... ]
foreach ($rii as $sf) {
  /** @var SplFileInfo $sf */
  if ($sf->isDir() || $sf->isLink()) continue;
  $full = $sf->getPathname();
  $rel  = path_rel($full, $PUB);

  if (excluded($rel, $exclude)) continue;
  if (!included_by_prefix($rel, $include)) continue;
  if (!is_allowed_ext($rel, $exts)) continue;
  if ($sinceTs > 0 && $sf->getMTime() < $sinceTs) continue;

  $files[] = [$rel, $full, $sf->getSize(), $sf->getMTime()];
}

/* Sort newest-first so the budget captures recent work */
usort($files, fn($a,$b) => $b[3] <=> $a[3]);

/* ---- Prepare bundle ---- */
if (!class_exists(ZipArchive::class)) {
  http_response_code(500);
  echo "ZipArchive PHP extension not available on this host.\n";
  echo "Please enable it in PHP settings or tell me to provide a tar.gz fallback.\n";
  exit;
}

$stamp = date('Ymd_His');
$bundleName = $name !== '' ? $name : "codebundle_{$stamp}";
$zipPath = "{$OUT}/{$bundleName}.zip";

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE|ZipArchive::OVERWRITE) !== true) {
  http_response_code(500); exit("Failed to open zip for writing: $zipPath\n");
}

/* Add files until we hit the budget (approx by uncompressed size) */
$added = 0;
$bytes = 0;
$list  = [];

foreach ($files as [$rel,$full,$size,$mtime]) {
  if ($bytes + $size > $maxBytes && $added > 0) {
    // stop once we filled the budget (keep at least one file)
    continue;
  }
  if (!$zip->addFile($full, $rel)) {
    // skip on failure
    continue;
  }
  // Best effort compression
  $zip->setCompressionName($rel, ZipArchive::CM_DEFLATE);
  $zip->setCompressionIndex($zip->locateName($rel), ZipArchive::CM_DEFLATE);

  $added++;
  $bytes += $size;
  $list[] = [
    'path' => $rel,
    'size' => $size,
    'mtime'=> date('c', $mtime)
  ];
}

/* Add a manifest inside the zip */
$manifest = [
  'generated_at' => date('c'),
  'root'         => '/public_html',
  'filters'      => [
    'exts'    => $exts,
    'include' => $include,
    'exclude' => $exclude,
    'since'   => $sinceTs ? date('c', $sinceTs) : null,
    'budget_mb' => $maxMB
  ],
  'counts'       => [
    'candidates' => count($files),
    'added'      => $added,
  ],
  'files'        => $list,
];
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$zip->close();

/* ---- Output summary ---- */
$url = str_replace($PUB, '', $zipPath); // /dev/share/...
$kb  = number_format($bytes/1024, 1);
echo "Bundle created: {$url}\n";
echo "Approx raw size included: {$kb} KB across {$added} files (from ".count($files)." candidates)\n";
echo "Filters: exts=".implode(',', $exts)."; include=".implode(',', $include)."; exclude=".implode(',', $exclude)."; since=".($sinceTs?$manifest['filters']['since']:'(none)')."; budget={$maxMB}MB\n";
echo "Share this link with your reviewer:\n";
echo $url . "\n";