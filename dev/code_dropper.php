<?php
// /dev/code_dropper.php
// Smart DEV-ONLY file dropper with auto folder map, cached index, tree UI, load + overwrite
// RBAC fallback: if rbac_can() is missing, enforce a DEV PIN gate.
// Uses independent dev CSRF token to avoid app-level mismatches.

declare(strict_types=1);

// ---- EARLY SESSION & DEV CSRF ----------------------------------------------
if (!isset($_SESSION)) { session_start(); }
if (empty($_SESSION['_dev_csrf'])) { $_SESSION['_dev_csrf'] = bin2hex(random_bytes(32)); }
function dev_csrf_token(): string { return $_SESSION['_dev_csrf']; }
function dev_csrf_validate(?string $t): bool {
    return is_string($t) && isset($_SESSION['_dev_csrf']) && hash_equals($_SESSION['_dev_csrf'], $t);
}

// ---- CONFIG -----------------------------------------------------------------
$SHOW_RESTRICTED_BADGE = false;     // show "Restricted" badge beside title
$SHOW_DIAGNOSTICS      = true;      // show the diagnostics card
$DEV_PIN               = '9876';    // <<< CHANGE THIS. Used when rbac_can() is missing.
$DEV_IP_ALLOWLIST      = [];        // e.g. ['1.2.3.4'] to restrict by IP; leave [] to allow all
$APP_ROOT              = realpath(__DIR__ . '/..');

// Whitelisted top-level directories relative to APP_ROOT
$ALLOWED_DIRS = [
    'includes',
    'accounts',
    'assets',
    'attachments',
    'audit',
    'bom',
    'classes',
    'common',
    'dpr',
    'identity',
    'items',
    'jobs',
    'machines',
    'maintenance',
    'maintenance_alloc',
    'material',
    'migrations',
    'org',
    'parties',
    'processes',
    'projects',
    'purchase',
    'sales_orders',
    'settings',
    'stores',
    'tools',
    'ui',
    'uom',
    'workcenters',
    'workorders',
    'modules',
    'jobs',
    'public',
    'crm_leads',
    'crm',

];

// Ignore patterns during scan
$IGNORE_DIRS = ['.git', '.idea', '.vscode', 'node_modules', 'vendor', 'backups', 'storage', 'cache', 'logs', '.DS_Store'];
$IGNORE_FILES_SUFFIX = ['.tmp', '.swp', '.lock', '.log'];

// Cache file for the file index
$CACHE_DIR  = __DIR__;
$CACHE_FILE = $CACHE_DIR . '/_cache_file_index.json';

// ---- Includes & UI shell ----------------------------------------------------
@require_once __DIR__ . '/../includes/auth.php';
@require_once __DIR__ . '/../includes/db.php';
@require_once __DIR__ . '/../includes/rbac.php';
@require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../ui/layout_start.php'; // Bootstrap 5 shell

// ---- ENV/SECURITY GUARDS ----------------------------------------------------
$env        = getenv('APP_ENV') ?: '';
$isProd     = $env && strtolower((string)$env) === 'production';
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '';

// Hard-disable in production
if ($isProd) {
    http_response_code(403);
    echo '<div class="container py-4"><div class="alert alert-danger">Disabled in production (APP_ENV=production).</div></div>';
    require_once __DIR__ . '/../ui/layout_end.php';
    exit;
}

// Optional IP allowlist
if (!empty($DEV_IP_ALLOWLIST) && !in_array($clientIp, $DEV_IP_ALLOWLIST, true)) {
    http_response_code(403);
    echo '<div class="container py-4"><div class="alert alert-danger">Your IP is not on the DEV allowlist.</div></div>';
    require_once __DIR__ . '/../ui/layout_end.php';
    exit;
}

// RBAC or DEV PIN gate
$rbacAvailable = function_exists('rbac_can');
$hasRbac       = $rbacAvailable && rbac_can('core.dev.file.write');
$pinUnlocked   = !empty($_SESSION['_dev_pin_ok']) && $_SESSION['_dev_pin_ok'] === true;

if (!$rbacAvailable) {
    // Use PIN flow
    if (($_POST['_action'] ?? '') === 'dev_pin_login') {
        if (dev_csrf_validate($_POST['_dev_csrf'] ?? '') && isset($_POST['pin'])) {
            if (hash_equals($DEV_PIN, (string)$_POST['pin'])) {
                $_SESSION['_dev_pin_ok'] = true;
                $pinUnlocked = true;
            } else {
                echo '<div class="container py-3"><div class="alert alert-danger">Invalid PIN.</div></div>';
            }
        }
    }
    if (!$pinUnlocked) {
        // Show PIN form and stop
        ?>
        <div class="container py-5" style="max-width:520px;">
          <div class="card shadow-sm">
            <div class="card-body">
              <h1 class="h5 mb-3">Code Dropper – DEV PIN</h1>
              <form method="post">
                <input type="hidden" name="_dev_csrf" value="<?= htmlspecialchars(dev_csrf_token()) ?>">
                <input type="hidden" name="_action" value="dev_pin_login">
                <div class="mb-3">
                  <label class="form-label">Enter DEV PIN</label>
                  <input type="password" name="pin" class="form-control" required>
                </div>
                <button class="btn btn-primary" type="submit">Unlock</button>
              </form>
              <div class="form-text mt-2">RBAC not detected; using PIN gate. Set <code>$DEV_PIN</code> in this file.</div>
            </div>
          </div>
        </div>
        <?php
        require_once __DIR__ . '/../ui/layout_end.php';
        exit;
    }
} else {
    // RBAC present; enforce permission
    if (!$hasRbac) {
        http_response_code(403);
        echo '<div class="container py-4"><div class="alert alert-danger">Forbidden: missing permission <code>core.dev.file.write</code>.</div></div>';
        require_once __DIR__ . '/../ui/layout_end.php';
        exit;
    }
}

// ---- Diagnostics ------------------------------------------------------------
$diag = [
    'app_env'     => $env,
    'rbac_state'  => $rbacAvailable ? ($hasRbac ? 'yes' : 'no') : 'rbac_can() missing',
    'user'        => (function_exists('auth_user') ? (auth_user()['username'] ?? 'unknown') : 'unknown'),
    'app_root'    => $APP_ROOT ?: '(unresolved)',
    'cache_file'  => $CACHE_FILE,
    'ip'          => $clientIp,
    'write_test'  => 'not-run',
    'last_error'  => '',
];

// APP_ROOT check
if ($APP_ROOT === false) {
    http_response_code(500);
    echo '<div class="alert alert-danger">APP_ROOT resolution failed.</div>';
    require_once __DIR__ . '/../ui/layout_end.php';
    exit;
}

// Basic write test (in /dev)
try {
    $testFile = __DIR__ . '/_write_test.txt';
    if (@file_put_contents($testFile, 'ok ' . date('c')) !== false) {
        $diag['write_test'] = 'ok (' . basename($testFile) . ' created)';
        @unlink($testFile);
    } else {
        $diag['write_test'] = 'failed';
        $diag['last_error'] = error_get_last()['message'] ?? '';
    }
} catch (Throwable $t) {
    $diag['write_test'] = 'exception: ' . $t->getMessage();
}

// ---- Utils ------------------------------------------------------------------
function starts_with(string $haystack, string $needle): bool {
    return strncmp($haystack, $needle, strlen($needle)) === 0;
}
function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: $dir");
        }
    }
}
function is_allowed_path(string $relPath, array $allowedTop): bool {
    $firstSeg = explode('/', $relPath, 2)[0];
    return in_array($firstSeg, $allowedTop, true);
}
function should_ignore_name(string $name, array $ignoreDirs): bool {
    return in_array($name, $ignoreDirs, true);
}
function has_ignored_suffix(string $name, array $suffixes): bool {
    foreach ($suffixes as $suf) {
        if ($suf !== '' && str_ends_with($name, $suf)) return true;
    }
    return false;
}

// ---- Scanner & Cache --------------------------------------------------------
function build_tree(string $APP_ROOT, array $ALLOWED_DIRS, array $IGNORE_DIRS, array $IGNORE_FILES_SUFFIX, int $maxFiles = 50000): array {
    $tree = [];
    $count = 0;
    foreach ($ALLOWED_DIRS as $top) {
        $abs = $APP_ROOT . DIRECTORY_SEPARATOR . $top;
        if (!is_dir($abs)) continue;
        $tree[$top] = ['_type' => 'dir', 'children' => []];

        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($rii as $fi) {
            $name = $fi->getFilename();

            if ($fi->isDir()) {
                if (should_ignore_name($name, $IGNORE_DIRS)) continue;
            } else {
                if (has_ignored_suffix($name, $IGNORE_FILES_SUFFIX)) continue;
            }

            $absPath = $fi->getPathname();
            $relPath = ltrim(str_replace('\\', '/', substr($absPath, strlen($APP_ROOT))), '/');
            if (!is_allowed_path($relPath, $ALLOWED_DIRS)) continue;

            $parts = explode('/', $relPath);
            $node =& $tree;
            foreach ($parts as $i => $part) {
                if ($i === count($parts)-1) {
                    if ($fi->isDir()) {
                        if (!isset($node[$part])) $node[$part] = ['_type' => 'dir', 'children' => []];
                    } else {
                        $node[$part] = ['_type' => 'file'];
                        $count++;
                        if ($count >= $maxFiles) {
                            unset($node);
                            break 3;
                        }
                    }
                } else {
                    if (!isset($node[$part])) $node[$part] = ['_type' => 'dir', 'children' => []];
                    $children =& $node[$part]['children'];
                    unset($node);
                    $node =& $children;
                }
            }
            unset($node);
        }
    }
    return $tree;
}

function flatten_files(array $tree, string $prefix = ''): array {
    $out = [];
    foreach ($tree as $name => $data) {
        if (!is_array($data) || !isset($data['_type'])) continue;
        if ($data['_type'] === 'file') {
            $out[] = ltrim($prefix . $name, '/');
        } elseif ($data['_type'] === 'dir') {
            $childPrefix = $prefix . $name . '/';
            if (isset($data['children']) && is_array($data['children'])) {
                $out = array_merge($out, flatten_files($data['children'], $childPrefix));
            }
        }
    }
    return $out;
}

function load_cached_index(string $cacheFile): ?array {
    if (!is_file($cacheFile)) return null;
    $raw = @file_get_contents($cacheFile);
    if ($raw === false) return null;
    $arr = json_decode($raw, true);
    return (is_array($arr) && isset($arr['tree'], $arr['ts'])) ? $arr : null;
}
function save_cached_index(string $cacheFile, array $tree): bool {
    $payload = ['ts' => date('c'), 'tree' => $tree];
    return (bool)@file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_SLASHES));
}

// ---- Actions ----------------------------------------------------------------
$messages = [];
$errors = [];
$loadedCode = '';
$postedPath = '';

$action = $_POST['_action'] ?? 'init';
$needsRescan = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['_dev_csrf'] ?? '';
    if (!dev_csrf_validate($csrf)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $relPath = trim((string)($_POST['path'] ?? ''));
        $postedPath = $relPath;

        if ($action === 'rescan') {
            $needsRescan = true;

        } elseif ($action === 'load') {
            if ($relPath === '') $errors[] = 'Path is required.';
            if (str_contains($relPath, '..') || str_starts_with($relPath, '/') || preg_match('#^[A-Za-z]:\\\\#', $relPath)) {
                $errors[] = 'Path must be relative (no ".." or absolute).';
            }
            if (!is_allowed_path($relPath, $ALLOWED_DIRS)) {
                $errors[] = 'Top-level directory is not allowed.';
            }
            $dest = $APP_ROOT . DIRECTORY_SEPARATOR . $relPath;
            if (!$errors) {
                if (!file_exists($dest)) {
                    $errors[] = 'File not found to load.';
                } else {
                    $real = realpath($dest);
                    if ($real === false || !starts_with($real, $APP_ROOT)) {
                        $errors[] = 'Resolved path escapes APP_ROOT.';
                    } else {
                        $loaded = @file_get_contents($real);
                        if ($loaded === false) {
                            $errors[] = 'Failed to read file.';
                        } else {
                            $loadedCode = $loaded;
                            $messages[] = 'Loaded file into editor.';
                        }
                    }
                }
            }

        } elseif ($action === 'write') {
            $code    = (string)($_POST['code'] ?? '');
            $overwrite = isset($_POST['overwrite']);
            $makeDirs  = isset($_POST['makedirs']);

            if ($relPath === '') $errors[] = 'Path is required.';
            if ($code === '')    $errors[] = 'Code/content is required.';
            if (str_contains($relPath, '..') || str_starts_with($relPath, '/') || preg_match('#^[A-Za-z]:\\\\#', $relPath)) {
                $errors[] = 'Path must be relative (no ".." or absolute).';
            }
            if (!is_allowed_path($relPath, $ALLOWED_DIRS)) {
                $errors[] = 'Top-level directory is not allowed.';
            }

            $dest = $APP_ROOT . DIRECTORY_SEPARATOR . $relPath;

            $destRealParent = realpath(dirname($dest)) ?: dirname($dest);
            if (is_dir($destRealParent)) {
                $realParent = realpath($destRealParent);
                if ($realParent === false || !starts_with($realParent, $APP_ROOT)) {
                    $errors[] = 'Resolved parent directory escapes APP_ROOT.';
                }
            }
            if (!$errors && !is_dir(dirname($dest))) {
                if ($makeDirs) {
                    try { ensure_dir(dirname($dest)); } catch (Throwable $t) { $errors[] = $t->getMessage(); }
                } else {
                    $errors[] = 'Parent directory does not exist. Tick "Create missing folders".';
                }
            }
            if (!$errors && file_exists($dest) && !$overwrite) {
                $errors[] = 'File exists. Tick "Overwrite if exists" to replace.';
            }

            if (!$errors) {
                // Backup existing
                $backupPath = null;
                if (file_exists($dest)) {
                    $stamp = date('Ymd_His');
                    $backupDir = $APP_ROOT . '/backups/' . $stamp;
                    $backupLeaf = str_replace(['/', '\\'], '.', $relPath);
                    try {
                        ensure_dir($backupDir);
                        $backupPath = $backupDir . '/' . $backupLeaf;
                        if (!copy($dest, $backupPath)) {
                            $errors[] = 'Failed to create backup of existing file.';
                        }
                    } catch (Throwable $t) {
                        $errors[] = 'Backup error: ' . $t->getMessage();
                    }
                }
            }

            if (!$errors) {
                $tmp = $dest . '.tmp-' . bin2hex(random_bytes(6));
                $bytes = file_put_contents($tmp, $code, LOCK_EX);
                if ($bytes === false) {
                    $errors[] = 'Failed to write temporary file.';
                } else {
                    if (file_exists($dest)) {
                        $perms = fileperms($dest) & 0777;
                        @chmod($tmp, $perms ?: 0664);
                    } else {
                        @chmod($tmp, 0664);
                    }
                    if (!@rename($tmp, $dest)) {
                        @unlink($tmp);
                        $errors[] = 'Failed to move file into place.';
                    } else {
                        $messages[] = 'File written: <code>' . htmlspecialchars($relPath) . '</code>';
                        if (isset($backupPath)) {
                            $messages[] = 'Backup: <code>' . htmlspecialchars(str_replace($APP_ROOT . '/', '', $backupPath)) . '</code>';
                        }
                        // lightweight audit
                        try {
                            $logDir = $APP_ROOT . '/backups/_write_logs';
                            ensure_dir($logDir);
                            $who = function_exists('auth_user') ? (auth_user()['username'] ?? 'unknown') : 'unknown';
                            $entry = json_encode([
                                'ts' => date('c'),
                                'user' => $who,
                                'path' => $relPath,
                                'overwrite' => $overwrite,
                                'size' => strlen($code),
                                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                                'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            ], JSON_UNESCAPED_SLASHES);
                            file_put_contents($logDir . '/' . date('Ymd') . '.log', $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
                        } catch (Throwable $t) { /* ignore */ }
                    }
                }
            }
        }
    }
}

// Load or build index
$indexPayload = load_cached_index($CACHE_FILE);
if (($action === 'init' && $indexPayload === null) || ($action === 'rescan')) {
    try {
        $tree = build_tree($APP_ROOT, $ALLOWED_DIRS, $IGNORE_DIRS, $IGNORE_FILES_SUFFIX);
        save_cached_index($CACHE_FILE, $tree);
        $indexPayload = ['ts' => date('c'), 'tree' => $tree];
        $messages[] = ($action === 'rescan') ? 'Folder map rebuilt.' : 'Folder map created.';
    } catch (Throwable $t) {
        $errors[] = 'Scan error: ' . $t->getMessage();
        $indexPayload = ['ts' => date('c'), 'tree' => []];
    }
}
$TREE = $indexPayload['tree'] ?? [];
$INDEX_TS = $indexPayload['ts'] ?? '';
$ALL_FILES = flatten_files($TREE);

// ---- Page -------------------------------------------------------------------
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h5 mb-0">Code Dropper</h1>
    <?php if ($SHOW_RESTRICTED_BADGE): ?>
      <span class="badge bg-danger ms-2">Restricted</span>
    <?php endif; ?>
    <form method="post" class="ms-auto d-flex gap-2 align-items-center">
      <input type="hidden" name="_dev_csrf" value="<?= htmlspecialchars(dev_csrf_token()) ?>">
      <input type="hidden" name="_action" value="rescan">
      <span class="text-muted small me-2">Indexed: <?= htmlspecialchars($INDEX_TS) ?> | Files: <?= number_format(count($ALL_FILES)) ?></span>
      <button class="btn btn-sm btn-outline-secondary" type="submit" title="Rescan folders">Rescan</button>
    </form>
  </div>

  <?php if ($SHOW_DIAGNOSTICS): ?>
    <div class="card mb-3 shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <h6 class="mb-0">Diagnostics</h6>
        </div>
        <div class="row small mt-2">
          <div class="col-md-3">User: <code><?= htmlspecialchars($diag['user']) ?></code></div>
          <div class="col-md-3">RBAC: <code><?= htmlspecialchars($diag['rbac_state']) ?></code></div>
          <div class="col-md-3">APP_ENV: <code><?= htmlspecialchars($diag['app_env']) ?></code></div>
          <div class="col-md-3">Write test: <code><?= htmlspecialchars($diag['write_test']) ?></code></div>
        </div>
        <div class="row small mt-1">
          <div class="col-md-6">APP_ROOT: <code><?= htmlspecialchars($diag['app_root']) ?></code></div>
          <div class="col-md-6">Cache file: <code><?= htmlspecialchars(str_replace($APP_ROOT . '/', '', $diag['cache_file'])) ?></code></div>
        </div>
        <div class="row small mt-1">
          <div class="col-md-6">Client IP: <code><?= htmlspecialchars($diag['ip']) ?></code></div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach (($messages ?? []) as $m): ?>
    <div class="alert alert-success py-2 my-2"><?= $m ?></div>
  <?php endforeach; ?>
  <?php foreach (($errors ?? []) as $e): ?>
    <div class="alert alert-danger py-2 my-2"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="d-flex align-items-center mb-2">
            <h2 class="h6 mb-0">Folders & Files</h2>
          </div>
          <input type="text" id="treeFilter" class="form-control form-control-sm mb-2" placeholder="Filter files/folders…">
          <div id="tree" class="border rounded" style="max-height: 65vh; overflow:auto; padding: .5rem;">
            <?php
              function render_tree(array $node, string $prefix = ''): void {
                  echo '<ul class="list-unstyled ms-2 mb-0">';
                  foreach ($node as $name => $data) {
                      if (!is_array($data) || !isset($data['_type'])) continue;
                      if ($data['_type'] === 'dir') {
                          $id = htmlspecialchars($prefix . $name);
                          echo '<li class="mb-1">';
                          echo '<div class="tree-dir d-flex align-items-center" data-name="' . $id . '" style="cursor:pointer;">';
                          echo '<span class="me-1">📁</span><span class="dir-name">' . htmlspecialchars($name) . '</span>';
                          echo '</div>';
                          if (isset($data['children']) && is_array($data['children']) && !empty($data['children'])) {
                              echo '<div class="tree-children ms-3 mt-1">';
                              render_tree($data['children'], $prefix . $name . '/');
                              echo '</div>';
                          }
                          echo '</li>';
                      } elseif ($data['_type'] === 'file') {
                          $full = htmlspecialchars($prefix . $name);
                          echo '<li class="tree-file py-1" data-path="' . $full . '" style="cursor:pointer;">';
                          echo '📄 <span class="file-name">' . htmlspecialchars($name) . '</span>';
                          echo '</li>';
                      }
                  }
                  echo '</ul>';
              }
              render_tree($TREE);
            ?>
          </div>
          <datalist id="pathlist">
            <?php foreach ($ALL_FILES as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-body">
          <form method="post" id="dropperForm">
            <input type="hidden" name="_dev_csrf" value="<?= htmlspecialchars(dev_csrf_token()) ?>">
            <input type="hidden" name="_action" id="actionField" value="write">

            <div class="mb-2">
              <label class="form-label fw-semibold">Path</label>
              <div class="d-flex gap-2">
                <input type="text" name="path" id="pathInput" class="form-control" list="pathlist" placeholder="Select from tree or type…" value="<?= htmlspecialchars($postedPath) ?>" required>
                <button type="button" class="btn btn-outline-secondary" id="loadButton" title="Load existing file contents">Load</button>
              </div>
              <div class="form-text">Whitelisted: <?= htmlspecialchars(implode(', ', $ALLOWED_DIRS)) ?>.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">File Contents</label>
              <textarea name="code" id="codeArea" class="form-control" rows="20" spellcheck="false" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;" required><?= htmlspecialchars($loadedCode) ?></textarea>
            </div>

            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite">
              <label class="form-check-label" for="overwrite">Overwrite if file exists (backup will be created)</label>
            </div>

            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="makedirs" name="makedirs" checked>
              <label class="form-check-label" for="makedirs">Create missing folders</label>
            </div>

            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit">Write File</button>
              <a class="btn btn-outline-secondary" href="/ui/sidebar.php">Cancel</a>
            </div>
          </form>
        </div>
      </div>

      <div class="mt-2 small text-muted">
        Safety: relative paths only; traversal blocked; writes confined to whitelisted folders; backups under
        <code>/backups/&lt;timestamp&gt;/</code>; audit logs in <code>/backups/_write_logs/</code>. Production locked via <code>APP_ENV=production</code>.
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const tree = document.getElementById('tree');
  const filter = document.getElementById('treeFilter');
  const pathInput = document.getElementById('pathInput');
  const actionField = document.getElementById('actionField');
  const form = document.getElementById('dropperForm');
  const loadBtn = document.getElementById('loadButton');

  // Collapse/expand folders + click file to auto-load
  if (tree) {
    tree.addEventListener('click', function(e){
      const dir = e.target.closest('.tree-dir');
      const file = e.target.closest('.tree-file');
      if (dir) {
        const container = dir.nextElementSibling;
        if (container && container.classList.contains('tree-children')) {
          container.style.display = (container.style.display === 'none') ? '' : 'none';
        }
      } else if (file) {
        const p = file.getAttribute('data-path');
        if (p) {
          pathInput.value = p;
          actionField.value = 'load';
          form.submit();
        }
      }
    });
  }

  // Filter tree
  if (filter && tree) {
    filter.addEventListener('input', function(){
      const q = this.value.toLowerCase().trim();
      const items = tree.querySelectorAll('.tree-dir, .tree-file');
      items.forEach(el => {
        const text = el.textContent.toLowerCase();
        const row = el.classList.contains('tree-dir') ? el.parentElement : el;
        row.style.display = text.includes(q) ? '' : 'none';
      });
    });
  }

  if (loadBtn) {
    loadBtn.addEventListener('click', function(){
      actionField.value = 'load';
      form.submit();
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../ui/layout_end.php';
