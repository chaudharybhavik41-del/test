<?php
declare(strict_types=1);
/**
 * files_to_json.php
 *
 * Walk a directory tree and write a single JSON file containing:
 *  - A header record with the file path
 *  - Then each line of that file as a separate record (NDJSON by default)
 *
 * Query params (for browser) or CLI args (key=value):
 *   path=/abs/or/relative/path    (default: current dir)
 *   out=/abs/or/relative/out.json (default: ./files_dump.ndjson for ndjson, ./files_dump.json for json)
 *   mode=ndjson|json              (default: ndjson)
 *   hide_dotfiles=1               (default: 0)
 *   follow_symlinks=1             (default: 0)
 *   include_ext=php,js,css,html   (optional, comma-separated; else include all)
 *   exclude_dir=vendor,.git,node_modules (optional, comma-separated)
 *   max_size_mb=5                 (default: 5; per-file cap; 0 = unlimited)
 */

ini_set('memory_limit', '-1');
set_time_limit(0);

// --------- args (works in web or CLI) ----------
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

$root           = (string)($args['path'] ?? getcwd());
$mode           = (string)($args['mode'] ?? 'ndjson'); // ndjson | json
$hideDot        = isset($args['hide_dotfiles']) && in_array((string)$args['hide_dotfiles'], ['1','true','on'], true);
$followLinks    = isset($args['follow_symlinks']) && in_array((string)$args['follow_symlinks'], ['1','true','on'], true);
$includeExt     = isset($args['include_ext']) ? array_filter(array_map('strtolower', array_map('trim', explode(',', (string)$args['include_ext'])))) : [];
$excludeDir     = isset($args['exclude_dir']) ? array_filter(array_map('trim', explode(',', (string)$args['exclude_dir']))) : [];
$maxSizeMB      = isset($args['max_size_mb']) ? (int)$args['max_size_mb'] : 5;
$outDefault     = $mode === 'json' ? 'files_dump.json' : 'files_dump.ndjson';
$outPath        = (string)($args['out'] ?? ($root . DIRECTORY_SEPARATOR . $outDefault));

// --------- validations ----------
if (!is_dir($root)) {
    http_response_code(400);
    echo "Invalid path: " . htmlspecialchars($root);
    exit(1);
}
if (!is_writable(dirname($outPath))) {
    http_response_code(400);
    echo "Output directory not writable: " . htmlspecialchars(dirname($outPath));
    exit(1);
}

// --------- helpers ----------
function is_binary_file(string $path, int $probeBytes = 4096): bool {
    $h = @fopen($path, 'rb');
    if (!$h) return true;
    $buf = @fread($h, $probeBytes);
    @fclose($h);
    if ($buf === false) return true;
    // If it contains NULs or a high ratio of non-text bytes, treat as binary
    if (strpos($buf, "\0") !== false) return true;
    $nonText = 0; $len = strlen($buf);
    for ($i=0; $i<$len; $i++) {
        $ord = ord($buf[$i]);
        if ($ord === 9 || $ord === 10 || $ord === 13) continue; // \t \n \r
        if ($ord >= 32 && $ord <= 126) continue; // printable ASCII
        if ($ord >= 128) { $nonText++; continue; } // UTF-8 bytes count as non-ASCII
    }
    return ($len > 0 && ($nonText / $len) > 0.30);
}
function should_skip_entry(SplFileInfo $fi, bool $hideDot, array $excludeDir): bool {
    $name = $fi->getBasename();
    if ($hideDot && strlen($name) > 0 && $name[0] === '.') return true;
    foreach ($excludeDir as $ex) {
        if ($ex !== '' && str_contains($fi->getPathname(), DIRECTORY_SEPARATOR . $ex . DIRECTORY_SEPARATOR)) {
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
function normalize_line(string $line): string {
    // Strip only trailing CR/LF; keep leading/trailing spaces
    return rtrim($line, "\r\n");
}
function open_out(string $out): SplFileObject {
    $f = new SplFileObject($out, 'w');
    return $f;
}
function write_ndjson_header(SplFileObject $out, string $filePath, int $sizeBytes): void {
    $rec = [
        'type'      => 'header',
        'file'      => $filePath,
        'size'      => $sizeBytes,
        'modified'  => @date('c', @filemtime($filePath) ?: time()),
    ];
    $out->fwrite(json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n");
}
function write_ndjson_line(SplFileObject $out, string $filePath, int $lineNo, string $text): void {
    $rec = [
        'type'     => 'line',
        'file'     => $filePath,
        'line_no'  => $lineNo,
        'text'     => $text,
    ];
    $out->fwrite(json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n");
}

// --------- main ----------
$flags = \FilesystemIterator::SKIP_DOTS;
if ($followLinks) $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;

$it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, $flags),
    \RecursiveIteratorIterator::SELF_FIRST
);

$fileCount = 0;
$lineCount = 0;
$skipped   = ['binary'=>0, 'too_large'=>0, 'ext'=>0, 'hidden'=>0, 'dir_excluded'=>0];

$out = open_out($outPath);

if ($mode === 'json') {
    // Stream a single JSON object: { "path": ["line1","line2", ...], ... }
    $out->fwrite("{\n");
    $firstMap = true;
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

        // Write map key
        if (!$firstMap) $out->fwrite(",\n");
        $firstMap = false;

        // JSON key must be quoted; we also stream lines array
        $out->fwrite(json_encode($path, JSON_UNESCAPED_SLASHES) . ": [");

        $firstLine = true; $ln = 0;
        while (($line = fgets($fh)) !== false) {
            $ln++;
            if (!$firstLine) $out->fwrite(",");
            $firstLine = false;
            $out->fwrite(json_encode(normalize_line($line), JSON_UNESCAPED_SLASHES));
            $lineCount++;
        }
        @fclose($fh);
        $out->fwrite("]");

        $fileCount++;
    }
    $out->fwrite("\n}\n");
} else {
    // NDJSON: header + line objects
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

        write_ndjson_header($out, $path, $size);

        $fh = @fopen($path, 'rb');
        if (!$fh) continue;
        $ln = 0;
        while (($line = fgets($fh)) !== false) {
            $ln++;
            write_ndjson_line($out, $path, $ln, normalize_line($line));
            $lineCount++;
        }
        @fclose($fh);

        $fileCount++;
    }
}

// Final note (stdout or small HTML page if in browser)
$summary = [
    'root'        => $root,
    'out'         => $outPath,
    'mode'        => $mode,
    'files_written' => $fileCount,
    'lines_written' => $lineCount,
    'skipped'       => $skipped,
    'finished_at'   => date('c'),
];

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
