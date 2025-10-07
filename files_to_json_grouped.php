<?php
declare(strict_types=1);
/**
 * files_to_json_grouped.php
 *
 * Output ONE grouped JSON file mapping:
 *   { "absolute/path/to/file": ["line1","line2", ...], ... }
 *
 * Works in browser (GET) or CLI (key=value args).
 *
 * Params:
 *   path=/abs/or/relative/path         (default: getcwd())
 *   out=/abs/or/relative/out.json      (default: ./files_grouped.json)
 *   gzip=1                              (default: 0) -> writes out.json.gz
 *   hide_dotfiles=1                     (default: 0)
 *   follow_symlinks=1                   (default: 0)
 *   include_ext=php,js,css,html         (optional; omit = all)
 *   exclude_dir=vendor,.git,node_modules (optional)
 *   max_size_mb=5                       (default: 5; per-file cap; 0 = unlimited)
 *   skip_blank=1                        (default: 0; omit empty lines)
 */

ini_set('memory_limit', '-1');
set_time_limit(0);

// -------- args (GET + CLI key=value) ----------
function argv_kv(): array {
    $kv = [];
    if (PHP_SAPI === 'cli' && isset($_SERVER['argv'])) {
        foreach (array_slice($_SERVER['argv'], 1) as $arg) {
            if (str_contains($arg, '=')) {
                [$k,$v] = explode('=', $arg, 2);
                $kv[$k] = $v;
            }
        }
    }
    return $kv;
}
$args = array_merge($_GET ?? [], argv_kv());

$root        = (string)($args['path'] ?? getcwd());
$hideDot     = isset($args['hide_dotfiles']) && in_array((string)$args['hide_dotfiles'], ['1','true','on'], true);
$followLinks = isset($args['follow_symlinks']) && in_array((string)$args['follow_symlinks'], ['1','true','on'], true);
$includeExt  = isset($args['include_ext']) ? array_filter(array_map('strtolower', array_map('trim', explode(',', (string)$args['include_ext'])))) : [];
$excludeDir  = isset($args['exclude_dir']) ? array_filter(array_map('trim', explode(',', (string)$args['exclude_dir']))) : [];
$maxSizeMB   = isset($args['max_size_mb']) ? (int)$args['max_size_mb'] : 5;
$skipBlank   = isset($args['skip_blank']) && in_array((string)$args['skip_blank'], ['1','true','on'], true);
$gzip        = isset($args['gzip']) && in_array((string)$args['gzip'], ['1','true','on'], true);

$outDefault  = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'files_grouped.json';
$outPath     = (string)($args['out'] ?? $outDefault);
if ($gzip && !str_ends_with($outPath, '.gz')) {
    $outPath .= '.gz';
}

// -------- validations ----------
if (!is_dir($root)) {
    http_response_code(400);
    echo "Invalid path: " . htmlspecialchars($root);
    exit(1);
}
$dir = dirname($outPath);
if (!is_dir($dir) || !is_writable($dir)) {
    http_response_code(400);
    echo "Output directory not writable: " . htmlspecialchars($dir);
    exit(1);
}

// -------- helpers ----------
function is_binary_file(string $path, int $probeBytes = 4096): bool {
    $h = @fopen($path, 'rb');
    if (!$h) return true;
    $buf = @fread($h, $probeBytes);
    @fclose($h);
    if ($buf === false) return true;
    if (strpos($buf, "\0") !== false) return true; // NUL in text is rare
    // Count non-textish bytes (allow UTF-8 bytes)
    $non = 0; $len = strlen($buf);
    for ($i=0; $i<$len; $i++) {
        $c = ord($buf[$i]);
        if ($c === 9 || $c === 10 || $c === 13) continue;        // \t \n \r
        if ($c >= 32 && $c <= 126) continue;                      // ASCII printable
        if ($c >= 128) continue;                                  // assume UTF-8 continuation OK
        $non++;
    }
    return $non > ($len * 0.10);
}
function should_skip_entry(SplFileInfo $fi, bool $hideDot, array $excludeDir): bool {
    $name = $fi->getBasename();
    if ($hideDot && $name !== '' && $name[0] === '.') return true;
    $path = $fi->getPathname();
    foreach ($excludeDir as $ex) {
        if ($ex !== '' && str_contains($path, DIRECTORY_SEPARATOR . $ex . DIRECTORY_SEPARATOR)) {
            return true;
        }
    }
    return false;
}
function ext_ok(string $path, array $includeExt): bool {
    if (!$includeExt) return true;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, $includeExt, true);
}
function norm_line(string $line): string {
    return rtrim($line, "\r\n");
}

// -------- open output (supports gzip) ----------
$out = null;
$write = null;
$close = null;

if ($gzip) {
    $gz = gzopen($outPath, 'w9');
    if ($gz === false) { http_response_code(500); echo "Failed to open gzip file."; exit(1); }
    $write = function(string $s) use ($gz): void { gzwrite($gz, $s); };
    $close = function() use ($gz): void { gzclose($gz); };
} else {
    $fp = fopen($outPath, 'wb');
    if ($fp === false) { http_response_code(500); echo "Failed to open output file."; exit(1); }
    $write = function(string $s) use ($fp): void { fwrite($fp, $s); };
    $close = function() use ($fp): void { fclose($fp); };
}

// -------- scan + stream JSON ----------
$flags = \FilesystemIterator::SKIP_DOTS;
if ($followLinks) $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;

$it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, $flags),
    \RecursiveIteratorIterator::SELF_FIRST
);

$filesWritten = 0;
$linesWritten = 0;
$skipped = ['binary'=>0,'too_large'=>0,'ext'=>0,'hidden'=>0,'dir_excluded'=>0];

$write("{\n");
$firstFile = true;

foreach ($it as $fi) {
    /** @var SplFileInfo $fi */
    if (should_skip_entry($fi, $hideDot, $excludeDir)) {
        if ($fi->isDir()) $skipped['dir_excluded']++;
        else $skipped['hidden']++;
        continue;
    }
    if ($fi->isDir()) continue;

    $path = $fi->getPathname();
    if (!ext_ok($path, $includeExt)) { $skipped['ext']++; continue; }

    $size = (int)@$fi->getSize();
    if ($maxSizeMB > 0 && $size > ($maxSizeMB * 1024 * 1024)) { $skipped['too_large']++; continue; }
    if (is_binary_file($path)) { $skipped['binary']++; continue; }

    $fh = @fopen($path, 'rb');
    if (!$fh) continue;

    // Write file key
    if (!$firstFile) { $write(",\n"); }
    $firstFile = false;
    $write(json_encode($path, JSON_UNESCAPED_SLASHES) . ": [");

    $firstLine = true;
    $ln = 0;
    while (($line = fgets($fh)) !== false) {
        $line = norm_line($line);
        if ($skipBlank && $line === '') continue;
        if (!$firstLine) { $write(","); }
        $firstLine = false;
        $write(json_encode($line, JSON_UNESCAPED_SLASHES));
        $ln++;
        $linesWritten++;
    }
    @fclose($fh);

    $write("]");
    $filesWritten++;
}

$write("\n}\n");
$close();

// -------- response (browser prints a summary; CLI prints JSON to stderr) ----------
$summary = [
    'root'          => $root,
    'out'           => $outPath,
    'gzip'          => $gzip,
    'files_written' => $filesWritten,
    'lines_written' => $linesWritten,
    'skipped'       => $skipped,
    'finished_at'   => date('c'),
];

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
