<?php
/**
 * PATH: /public_html/tools/scaffold.php
 * PHP 8.4 + Bootstrap 5
 * CRUD Scaffolder for EMS Infra ERP
 *
 * - Includes: auth.php, db.php, rbac.php, csrf.php, helpers.php, numbering.php, audit.php
 * - Requires permission: dev.scaffold.run
 * - Generates /public_html/<slug>/{slug}_list.php, _form.php, _save.php
 * - Update: list pages build search with UNIQUE placeholders (:q1, :q2, …) to avoid HY093.
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

const OUTPUT_BASE = __DIR__ . '/..';
date_default_timezone_set('Asia/Kolkata');

// ---------- includes ----------
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/numbering.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
require_permission('dev.scaffold.run');

// ---------- helpers ----------
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function read_columns(PDO $pdo, string $dbName, string $table): array {
  $sql = "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
          ORDER BY ORDINAL_POSITION";
  $st = $pdo->prepare($sql);
  $st->execute([$dbName, $table]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
function detect_pk(array $cols): string {
  foreach ($cols as $c) if (strtoupper((string)$c['COLUMN_KEY']) === 'PRI') return (string)$c['COLUMN_NAME'];
  return 'id';
}
function is_system_col(string $c): bool {
  $c = strtolower($c);
  return in_array($c, ['id','created_at','updated_at','deleted_at'], true);
}
function has_soft_delete(array $cols): bool {
  foreach ($cols as $c) if (strtolower((string)$c['COLUMN_NAME']) === 'deleted_at') return true;
  return false;
}
function input_kind(array $col): string {
  $type = strtolower((string)$col['COLUMN_TYPE']);
  $data = strtolower((string)$col['DATA_TYPE']);
  if (str_starts_with($data, 'enum') || str_starts_with($type, 'enum(')) return 'enum';
  if ($data === 'tinyint' && preg_match('~tinyint\(1\)~i', $type)) return 'checkbox';
  if (preg_match('~(int|decimal|numeric|float|double)~', $data)) return 'number';
  if ($data === 'date') return 'date';
  if (in_array($data, ['datetime','timestamp'])) return 'datetime-local';
  if (in_array($data, ['text','mediumtext','longtext'])) return 'textarea';
  return 'text';
}
function enum_options(string $columnType): array {
  if (!preg_match("~^enum\\((.*)\\)$~i", $columnType, $m)) return [];
  $parts = preg_split("~,(?=(?:[^']*'[^']*')*[^']*$)~", $m[1]);
  $opts = [];
  foreach ($parts as $p) {
    $p = trim($p);
    if (str_starts_with($p, "'") && str_ends_with($p, "'")) $opts[] = stripcslashes(substr($p, 1, -1));
  }
  return $opts;
}
function ensure_inside(string $target, string $base): void {
  $realBase = realpath($base) ?: $base;
  $realT = realpath($target);
  if ($realT === false) $realT = $target;
  $realBase = rtrim(str_replace('\\','/',$realBase),'/') . '/';
  $realT = str_replace('\\','/',$realT);
  if (!str_starts_with($realT, $realBase)) throw new RuntimeException('Resolved path escapes output base.');
}

// ---------- page state ----------
$errors = [];
$notice = null; $generated = [];

$module_slug     = trim($_POST['module_slug'] ?? '');
$table_name      = trim($_POST['table_name'] ?? '');
$perm_base       = trim($_POST['perm_base'] ?? '');
$entity_title    = trim($_POST['entity_title'] ?? '');
$series_for_code = trim($_POST['series_for_code'] ?? ''); // optional auto-code series
$action          = $_POST['action'] ?? '';

try {
  $pdo = db();
  $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

  if ($action === 'generate') {
    verify_csrf_or_die();
    if ($module_slug === '' || $table_name === '' || $perm_base === '') {
      throw new RuntimeException('Module Slug, Table Name, and Permission Base are required.');
    }
    if ($entity_title === '') $entity_title = ucwords(str_replace(['_','-'], ' ', $module_slug));

    $cols = read_columns($pdo, $dbName, $table_name);
    if (!$cols) throw new RuntimeException("Table `{$table_name}` not found or not readable.");
    $pk   = detect_pk($cols);
    $soft = has_soft_delete($cols);

    $slug = basename($module_slug);
    $outDir = realpath(OUTPUT_BASE) . '/' . $slug;
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) throw new RuntimeException('Failed to create output directory: ' . $outDir);
    ensure_inside($outDir, OUTPUT_BASE);

    // ----------- choose display & search columns -----------
    $preferred = [];
    foreach ($cols as $c) {
      $n = strtolower((string)$c['COLUMN_NAME']); $t = strtolower((string)$c['DATA_TYPE']);
      if (in_array($n, [$pk,'created_at','updated_at','deleted_at'], true)) continue;
      if (str_contains($n,'name') || str_contains($n,'code') || in_array($t,['varchar','char','text'])) $preferred[] = $c['COLUMN_NAME'];
    }
    $others = [];
    foreach ($cols as $c) {
      $n = strtolower((string)$c['COLUMN_NAME']);
      if (in_array($n, [$pk,'created_at','updated_at','deleted_at'], true)) continue;
      if (!in_array($c['COLUMN_NAME'], $preferred, true)) $others[] = $c['COLUMN_NAME'];
    }
    $displayCols = array_slice(array_merge($preferred,$others), 0, 6);
    if (!in_array($pk, $displayCols, true)) array_unshift($displayCols, $pk);

    $searchable = [];
    foreach ($cols as $c) {
      $n = strtolower((string)$c['COLUMN_NAME']); $t = strtolower((string)$c['DATA_TYPE']);
      if (in_array($t,['varchar','char','text','mediumtext','longtext']) || str_contains($n,'name') || str_contains($n,'code')) $searchable[] = $c['COLUMN_NAME'];
    }
    if (!$searchable) $searchable = [$pk];

    // ----------- LIST PAGE (HY093-proof) -----------
    $softWhere = $soft ? " AND `deleted_at` IS NULL " : "";

    $displayColsPhp = var_export($displayCols, true);
    $searchablePhp  = var_export($searchable, true);

    $listPhp = <<<PHP
<?php
/** PATH: /public_html/{$slug}/{$slug}_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_permission('{$perm_base}.view');

\$pdo = db();
\$q = trim(\$_GET['q'] ?? '');

\$displayCols = {$displayColsPhp};
\$searchable  = {$searchablePhp};

\$softWhere = "{$softWhere}";

// Build WHERE with unique placeholders (q1, q2, ...)
\$params = [];
\$where = " WHERE 1=1" . \$softWhere;
if (\$q !== '') {
  \$terms = [];
  \$i = 1;
  foreach (\$searchable as \$col) {
    \$ph = ":q" . \$i++;
    \$terms[] = "`{\$col}` LIKE {\$ph}";
    \$params[\$ph] = "%" . \$q . "%";
  }
  if (\$terms) {
    \$where .= " AND (" . implode(' OR ', \$terms) . ")";
  }
}

\$sql = "SELECT * FROM `{$table_name}`" . \$where . " ORDER BY `{$pk}` DESC LIMIT 200";
\$stmt = \$pdo->prepare(\$sql);
\$stmt->execute(\$params);
\$rows = \$stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
render_flash();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0">{$entity_title}</h1>
  <div>
    <?php if (has_permission('{$perm_base}.create')): ?>
      <a href="<?= h('{$slug}_form.php') ?>" class="btn btn-primary">+ New</a>
    <?php endif; ?>
  </div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><input class="form-control" name="q" value="<?= h(\$q) ?>" placeholder="Search..."></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Search</button></div>
</form>

<div class="table-responsive">
<table class="table table-striped align-middle">
  <thead>
    <tr>
      <?php foreach (\$displayCols as \$c): ?>
        <th><?= h(\$c) ?></th>
      <?php endforeach; ?>
      <th style="width:200px">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!\$rows): ?>
      <tr><td colspan="999" class="text-center text-muted py-4">No records found.</td></tr>
    <?php endif; ?>
    <?php foreach (\$rows as \$r): ?>
      <tr>
        <?php foreach (\$displayCols as \$c): ?>
          <td><?= h((string)(\$r[\$c] ?? '')) ?></td>
        <?php endforeach; ?>
        <td>
          <div class="btn-group btn-group-sm">
            <?php if (has_permission('{$perm_base}.edit')): ?>
              <a class="btn btn-outline-secondary" href="<?= h('{$slug}_form.php?id='.(int)\$r['{$pk}']) ?>">Edit</a>
            <?php endif; ?>
            <?php if (has_permission('{$perm_base}.delete')): ?>
              <form method="post" action="<?= h('{$slug}_save.php') ?>" onsubmit="return confirm('Delete this record?')">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)\$r['{$pk}'] ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn btn-outline-danger">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
PHP;

    // ----------- FORM PAGE -----------
    $formCols = array_values(array_filter($cols, fn($c)=>!is_system_col((string)$c['COLUMN_NAME'])));
    $fieldsHtml = '';
    foreach ($formCols as $c) {
      $name = (string)$c['COLUMN_NAME']; $ctype = (string)$c['COLUMN_TYPE'];
      $kind = input_kind($c); $label = ucwords(str_replace('_',' ', $name));

      if ($kind === 'textarea') {
        $fieldsHtml .= <<<HTML

  <div class="col-12">
    <label class="form-label">{$label}</label>
    <textarea name="{$name}" rows="3" class="form-control"><?= h((string)(\$row['{$name}'] ?? '')) ?></textarea>
  </div>
HTML;
      } elseif ($kind === 'enum') {
        $opts = enum_options($ctype);
        $optsHtml = '';
        foreach ($opts as $opt) {
          $optEsc = e($opt);
          $optsHtml .= "<option value=\"{$optEsc}\" <?= ((string)(\$row['{$name}'] ?? '') === '{$optEsc}') ? 'selected' : '' ?>>{$optEsc}</option>";
        }
        $fieldsHtml .= <<<HTML

  <div class="col-md-4">
    <label class="form-label">{$label}</label>
    <select name="{$name}" class="form-select">
      <option value="">-- Select --</option>
      {$optsHtml}
    </select>
  </div>
HTML;
      } elseif ($kind === 'checkbox') {
        $fieldsHtml .= <<<HTML

  <div class="col-md-3 form-check mt-4">
    <input class="form-check-input" type="checkbox" id="chk_{$name}" name="{$name}" <?= ((int)(\$row['{$name}'] ?? 0) ? 'checked' : '') ?>>
    <label class="form-check-label" for="chk_{$name}">{$label}</label>
  </div>
HTML;
      } else {
        $typeAttr = $kind === 'text' ? 'text' : $kind;
        $fieldsHtml .= <<<HTML

  <div class="col-md-4">
    <label class="form-label">{$label}</label>
    <input type="{$typeAttr}" class="form-control" name="{$name}" value="<?= h((string)(\$row['{$name}'] ?? '')) ?>">
  </div>
HTML;
      }
    }

    $formPhp = <<<PHP
<?php
/** PATH: /public_html/{$slug}/{$slug}_form.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

\$id = (int)(\$_GET['id'] ?? 0);
\$isEdit = \$id > 0;
require_permission(\$isEdit ? '{$perm_base}.edit' : '{$perm_base}.create');

\$pdo = db();
\$row = [];
if (\$isEdit) {
  \$st = \$pdo->prepare("SELECT * FROM `{$table_name}` WHERE `{$pk}` = ?");
  \$st->execute([\$id]);
  \$row = \$st->fetch(PDO::FETCH_ASSOC) ?: [];
}

include __DIR__ . '/../ui/layout_start.php';
render_flash();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0"><?= \$isEdit ? 'Edit' : 'New' ?> {$entity_title}</h1>
  <a href="<?= h('{$slug}_list.php') ?>" class="btn btn-outline-secondary">Back</a>
</div>

<form method="post" action="<?= h('{$slug}_save.php') ?>" class="row g-3">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)\$id ?>">
{$fieldsHtml}
  <div class="col-12">
    <button class="btn btn-primary"><?= \$isEdit ? 'Update' : 'Create' ?></button>
  </div>
</form>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
PHP;

    // ----------- SAVE CONTROLLER -----------
    $postCollect = '';
    $insertCols = []; $insertVals = []; $updateSets = [];
    foreach ($formCols as $c) {
      $name = (string)$c['COLUMN_NAME'];
      $kind = input_kind($c);

      if ($kind === 'checkbox') {
        $postCollect .= "  \$data['{$name}'] = isset(\$_POST['{$name}']) ? 1 : 0;\n";
      } elseif ($kind === 'number') {
        $postCollect .= "  \$data['{$name}'] = (\$_POST['{$name}'] ?? '') === '' ? null : \$_POST['{$name}'];\n";
      } elseif (in_array($kind, ['date','datetime-local'], true)) {
        $postCollect .= "  \$data['{$name}'] = (\$_POST['{$name}'] ?? '') ?: null;\n";
      } else {
        $postCollect .= "  \$data['{$name}'] = trim((string)(\$_POST['{$name}'] ?? ''));\n";
      }

      $insertCols[] = "`{$name}`";
      $insertVals[] = ":{$name}";
      if ($name !== $pk) $updateSets[] = "`{$name}` = :{$name}";
    }

    $insertColsSql = implode(', ', $insertCols);
    $insertValsSql = implode(', ', $insertVals);
    $updateSetsSql = implode(', ', $updateSets);
    $softDeleteSql = $soft
      ? "UPDATE `{$table_name}` SET `deleted_at` = NOW() WHERE `{$pk}` = :id AND `deleted_at` IS NULL"
      : "DELETE FROM `{$table_name}` WHERE `{$pk}` = :id";

    $autoCodeLogic = '';
    if ($series_for_code !== '') {
      $hasCode = false;
      foreach ($cols as $cc) { if (strtolower($cc['COLUMN_NAME'])==='code') { $hasCode = true; break; } }
      if ($hasCode) {
        $seriesEsc = e($series_for_code);
        $autoCodeLogic = <<<PHP
  // Auto-generate code if empty
  if (array_key_exists('code', \$data) && \$data['code'] === '') {
    \$data['code'] = next_no('{$seriesEsc}');
  }
PHP;
      }
    }

    $savePhp = <<<PHP
<?php
/** PATH: /public_html/{$slug}/{$slug}_save.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/numbering.php';

require_login();
verify_csrf_or_die();

\$pdo = db();

\$action = (string)(\$_POST['action'] ?? '');
\$id = (int)(\$_POST['id'] ?? 0);

if (\$action === 'delete') {
  require_permission('{$perm_base}.delete');
  if (\$id <= 0) { http_response_code(400); exit('Invalid id'); }
  \$st = \$pdo->prepare("{$softDeleteSql}");
  \$st->execute([':id'=>\$id]);
  audit_log(\$pdo, '{$slug}', 'delete', \$id, null);
  set_flash('success', 'Deleted successfully.');
  header('Location: {$slug}_list.php'); exit;
}

// create or update
\$isEdit = \$id > 0;
require_permission(\$isEdit ? '{$perm_base}.edit' : '{$perm_base}.create');

\$data = [];
{$postCollect}
{$autoCodeLogic}

try {
  if (!\$isEdit) {
    \$sql = "INSERT INTO `{$table_name}` ({$insertColsSql}) VALUES ({$insertValsSql})";
    \$st = \$pdo->prepare(\$sql);
    \$st->execute(\$data);
    \$newId = (int)\$pdo->lastInsertId();
    audit_log(\$pdo, '{$slug}', 'create', \$newId, \$data);
    set_flash('success', 'Created successfully.');
    header('Location: {$slug}_list.php'); exit;
  } else {
    \$sql = "UPDATE `{$table_name}` SET {$updateSetsSql} WHERE `{$pk}` = :id";
    \$data['id'] = \$id;
    \$st = \$pdo->prepare(\$sql);
    \$st->execute(\$data);
    audit_log(\$pdo, '{$slug}', 'update', \$id, \$data);
    set_flash('success', 'Updated successfully.');
    header('Location: {$slug}_list.php'); exit;
  }
} catch (Throwable \$e) {
  set_flash('danger', \$e->getMessage());
  header('Location: {$slug}_form.php' . (\$isEdit ? ('?id='.(int)\$id) : ''));
  exit;
}
PHP;

    // ----------- write files -----------
    $files = [
      "{$outDir}/{$slug}_list.php" => $listPhp,
      "{$outDir}/{$slug}_form.php" => $formPhp,
      "{$outDir}/{$slug}_save.php" => $savePhp,
    ];
    foreach ($files as $path=>$code) {
      ensure_inside($path, OUTPUT_BASE);
      $tmp = tempnam(dirname($path), 'scf_');
      if ($tmp === false) throw new RuntimeException('Temp file error.');
      file_put_contents($tmp, $code);
      @chmod($tmp, 0664);
      if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Failed to write: ' . $path); }
      $generated[$path] = $code;
    }

    $notice = "Generated in /public_html/{$slug}/ (soft delete: " . ($soft ? 'ON' : 'OFF') . ", auto-code: " . ($series_for_code ? ('ON ['.$series_for_code.']') : 'OFF') . ")";
  }
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}

// ---------- view ----------
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>CRUD Scaffolder</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>body{padding:20px}.mono{font-family:ui-monospace,Menlo,Consolas,monospace}</style>
</head>
<body>
<div class="container-lg">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">CRUD Scaffolder</h1>
    <span class="badge bg-secondary">PHP 8.4</span>
  </div>

  <?php if ($notice): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <?= csrf_field() ?>
    <div class="col-md-3">
      <label class="form-label">Module Slug (folder)</label>
      <input class="form-control" name="module_slug" placeholder="e.g. crm_leads" value="<?= e($module_slug) ?>">
      <div class="form-text">Files in /public_html/&lt;slug&gt;/</div>
    </div>
    <div class="col-md-3">
      <label class="form-label">DB Table Name</label>
      <input class="form-control" name="table_name" placeholder="e.g. crm_leads" value="<?= e($table_name) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Permission Base</label>
      <input class="form-control" name="perm_base" placeholder="e.g. crm.lead" value="<?= e($perm_base) ?>">
      <div class="form-text">Enforces .view / .create / .edit / .delete</div>
    </div>
    <div class="col-md-3">
      <label class="form-label">Auto-Code Series (optional)</label>
      <input class="form-control" name="series_for_code" placeholder="e.g. CRM-LEAD" value="<?= e($series_for_code) ?>">
      <div class="form-text">Uses next_no(series) if the table has a <code>code</code> column.</div>
    </div>
    <div class="col-12">
      <button class="btn btn-primary" name="action" value="generate">Generate Files</button>
    </div>
  </form>

  <?php if (!empty($generated)): ?>
    <hr class="my-4">
    <h2 class="h5">Generated</h2>
    <?php foreach ($generated as $p => $c): ?>
      <details class="mb-3">
        <summary class="mono"><?= e($p) ?></summary>
        <pre class="mono bg-light p-3" style="white-space:pre-wrap;"><?= e($c) ?></pre>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</body>
</html>