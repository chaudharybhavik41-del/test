<?php
declare(strict_types=1);
// file_report.php
// Usage: place in the folder you want to scan and open in browser.
// Options (GET):
//  - path=/absolute/or/relative/path  (default: current directory)
//  - format=html|csv|json              (default: html)
//  - max_depth=N                       (default: 0 = unlimited)
//  - hide_dotfiles=1                   (hide files/dirs starting with .)
//  - follow_symlinks=1                 (follow symlinks; default no)
//  - download=1                        (force download for csv/json)
// Example: file_report.php?format=csv&hide_dotfiles=1

set_time_limit(0);
ini_set('memory_limit', '-1');

$root = (string)($_GET['path'] ?? getcwd());
$format = (string)($_GET['format'] ?? 'html');
$maxDepth = isset($_GET['max_depth']) ? (int)$_GET['max_depth'] : 0;
$hideDot = isset($_GET['hide_dotfiles']) && in_array($_GET['hide_dotfiles'], ['1','true','on'], true);
$followLinks = isset($_GET['follow_symlinks']) && in_array($_GET['follow_symlinks'], ['1','true','on'], true);

// Validate path
if (!is_dir($root)) {
    http_response_code(400);
    echo "Path not found or not a directory: " . htmlspecialchars($root);
    exit;
}

// Helper: human readable size
function hrsize(float $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units)-1) { $bytes /= 1024; $i++; }
    return round($bytes, ($i ? 2 : 0)) . ' ' . $units[$i];
}

// Recursively walk directory
function scan_dir(string $root, bool $hideDot, int $maxDepth, bool $followLinks): array {
    $results = [];
    // Iterator flags
    $flags = \FilesystemIterator::SKIP_DOTS;
    if ($followLinks) $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;
    $directory = new \RecursiveDirectoryIterator($root, $flags);

    if ($maxDepth > 0) {
        $iter = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::SELF_FIRST);
        $iter->setMaxDepth($maxDepth);
    } else {
        $iter = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::SELF_FIRST);
    }

    foreach ($iter as $fileinfo) {
        /** @var SplFileInfo $fileinfo */
        $path = $fileinfo->getPathname();
        $basename = $fileinfo->getBasename();
        if ($hideDot && strlen($basename) > 0 && $basename[0] === '.') continue;

        // Skip broken symlinks if followLinks is false (RecursiveDirectoryIterator will already handle)
        if ($fileinfo->isLink() && !$followLinks) {
            // treat as file entry with link info
            $results[] = [
                'type' => 'link',
                'path' => $path,
                'name' => $basename,
                'size' => 0,
                'modified' => date('c', $fileinfo->getMTime() ?: time()),
                'perms' => substr(sprintf('%o', $fileinfo->getPerms()), -4),
                'is_dir' => $fileinfo->isDir(),
            ];
            continue;
        }

        $isDir = $fileinfo->isDir();
        $size = $isDir ? 0.0 : (float)$fileinfo->getSize();
        $results[] = [
            'type' => $isDir ? 'dir' : 'file',
            'path' => $path,
            'name' => $basename,
            'size' => $size,
            'modified' => date('c', $fileinfo->getMTime()),
            'perms' => substr(sprintf('%o', $fileinfo->getPerms()), -4),
            'is_dir' => $isDir,
        ];
    }

    return $results;
}

// Collect results
$items = scan_dir($root, $hideDot, $maxDepth, $followLinks);
$totalFiles = 0;
$totalDirs = 0;
$totalBytes = 0.0;
foreach ($items as $it) {
    if ($it['type'] === 'dir') $totalDirs++;
    else $totalFiles++;
    $totalBytes += $it['size'];
}

// Output in requested format
if ($format === 'csv' || $format === 'json') {
    $download = isset($_GET['download']) && in_array($_GET['download'], ['1','true','on'], true);
    if ($format === 'csv') {
        if ($download) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="file_report.csv"');
        } else {
            header('Content-Type: text/csv; charset=utf-8');
        }
        $out = fopen('php://output', 'w');
        fputcsv($out, ['type','path','name','size_bytes','size_readable','modified','perms','is_dir']);
        foreach ($items as $r) {
            fputcsv($out, [
                $r['type'],
                $r['path'],
                $r['name'],
                (string)$r['size'],
                hrsize($r['size']),
                $r['modified'],
                $r['perms'],
                $r['is_dir'] ? '1':'0'
            ]);
        }
        fclose($out);
        exit;
    } else { // json
        if ($download) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="file_report.json"');
        } else {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'scanned_at' => date('c'),
            'root' => $root,
            'summary' => [
                'files' => $totalFiles,
                'directories' => $totalDirs,
                'total_bytes' => $totalBytes,
                'total_readable' => hrsize($totalBytes)
            ],
            'items' => $items
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Default: HTML output (Bootstrap 5)
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>File report — <?= htmlspecialchars($root) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { padding: 18px; }
    pre.small { font-size: .85rem; white-space: pre-wrap; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, "Roboto Mono", monospace; font-size:.9rem; }
    td.path { max-width: 520px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="d-flex align-items-center mb-3">
    <h3 class="me-auto">File report</h3>
    <div class="text-muted">Scanned: <strong><?= date('Y-m-d H:i:s') ?></strong></div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2" method="get">
        <div class="col-md-6">
          <label class="form-label">Path to scan</label>
          <input name="path" class="form-control mono" value="<?= htmlspecialchars($root) ?>">
          <div class="form-text">Absolute or relative path. Default is current directory (where script lives).</div>
        </div>

        <div class="col-md-2">
          <label class="form-label">Format</label>
          <select name="format" class="form-select">
            <option value="html" <?= $format==='html'?'selected':'' ?>>HTML</option>
            <option value="csv" <?= $format==='csv'?'selected':'' ?>>CSV</option>
            <option value="json" <?= $format==='json'?'selected':'' ?>>JSON</option>
          </select>
        </div>

        <div class="col-md-1">
          <label class="form-label">Max depth</label>
          <input name="max_depth" type="number" min="0" class="form-control" value="<?= $maxDepth ?>">
        </div>

        <div class="col-md-1">
          <label class="form-label">Hide dotfiles</label>
          <select name="hide_dotfiles" class="form-select">
            <option value="0" <?= !$hideDot?'selected':'' ?>>No</option>
            <option value="1" <?= $hideDot?'selected':'' ?>>Yes</option>
          </select>
        </div>

        <div class="col-md-2 d-flex align-items-end gap-2">
          <button class="btn btn-primary">Scan</button>
          <a class="btn btn-outline-secondary" href="?<?= http_build_query(array_merge($_GET, ['format'=>'csv','download'=>'1'])) ?>">Download CSV</a>
          <a class="btn btn-outline-secondary" href="?<?= http_build_query(array_merge($_GET, ['format'=>'json','download'=>'1'])) ?>">Download JSON</a>
        </div>
      </form>
    </div>
  </div>

  <div class="row mb-3">
    <div class="col">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Summary</h5>
          <div class="row">
            <div class="col-sm-3"><strong>Root</strong><div class="mono small"><?= htmlspecialchars($root) ?></div></div>
            <div class="col-sm-2"><strong>Files</strong><div><?= number_format($totalFiles) ?></div></div>
            <div class="col-sm-2"><strong>Directories</strong><div><?= number_format($totalDirs) ?></div></div>
            <div class="col-sm-2"><strong>Total size</strong><div><?= hrsize($totalBytes) ?></div></div>
            <div class="col-sm-3"><strong>Options</strong>
              <div class="small">Max depth: <?= $maxDepth ?: 'unlimited' ?><br>Hide dotfiles: <?= $hideDot ? 'yes' : 'no' ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="table-responsive" style="max-height:60vh; overflow:auto;">
    <table class="table table-sm table-hover align-middle">
      <thead class="table-light sticky-top">
        <tr>
          <th>Type</th>
          <th>Path</th>
          <th>Name</th>
          <th class="text-end">Size</th>
          <th>Modified</th>
          <th>Perms</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $r): ?>
          <tr>
            <td class="align-middle">
              <?php if ($r['type'] === 'dir'): ?>
                <span class="badge bg-primary">DIR</span>
              <?php elseif ($r['type'] === 'link'): ?>
                <span class="badge bg-warning text-dark">LINK</span>
              <?php else: ?>
                <span class="badge bg-secondary">FILE</span>
              <?php endif; ?>
            </td>
            <td class="path mono" title="<?= htmlspecialchars($r['path']) ?>"><?= htmlspecialchars($r['path']) ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td class="text-end"><?= $r['is_dir'] ? '-' : hrsize($r['size']) ?></td>
            <td><?= htmlspecialchars($r['modified']) ?></td>
            <td><?= htmlspecialchars($r['perms']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <footer class="text-muted small mt-3">
    Run locally. Scan can be slow for very large trees. If you want, I can add filters (extensions only), grouping by folder, or a CSV scheduled export.
  </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
