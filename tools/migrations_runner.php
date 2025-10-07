<?php
/**
 * PATH: /public_html/tools/migrations_runner.php
 * PHP 8.4 + Bootstrap 5
 *
 * - Uses central CSRF helpers from /includes/csrf.php
 * - Standard includes order: auth.php, db.php, rbac.php, csrf.php
 * - Requires permission: core.migrations.run
 * - Applies /public_html/migrations/*.sql in order; tracks in `_migrations`
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Kolkata');

// escape helper (guard)
if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$errors = []; $notice = null; $log = []; $files = [];

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/rbac.php';
  require_once __DIR__ . '/../includes/csrf.php'; // 🔥 reuse central CSRF

  require_login();
  require_permission('core.migrations.run');

  $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS `_migrations` (
      `id` int unsigned NOT NULL AUTO_INCREMENT,
      `filename` varchar(255) NOT NULL,
      `checksum` varchar(64) NOT NULL,
      `applied_at` datetime NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `filename` (`filename`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $dirPath = __DIR__ . '/../migrations';
  if (!is_dir($dirPath)) @mkdir($dirPath, 0775, true);
  $dir = realpath($dirPath);
  if (!$dir || !is_dir($dir)) throw new RuntimeException('Migrations folder not found at /public_html/migrations');

  $files = array_values(array_filter(scandir($dir) ?: [], fn($f)=>preg_match('~\.sql$~i', $f)));
  sort($files, SORT_STRING);

  if (($_POST['action'] ?? '') === 'run') {
    require_csrf();

    foreach ($files as $f) {
      $path = $dir . '/' . $f;
      $sql = file_get_contents($path);
      if ($sql === false) throw new RuntimeException('Failed to read ' . $f);
      $checksum = hash('sha256', $sql);

      $st = $pdo->prepare("SELECT checksum FROM `_migrations` WHERE filename = ?");
      $st->execute([$f]);
      $existing = $st->fetchColumn();
      if ($existing) {
        if ($existing !== $checksum) {
          $log[] = "SKIP {$f} (already applied, checksum changed! manual review needed)";
        } else {
          $log[] = "SKIP {$f} (already applied)";
        }
        continue;
      }

      $pdo->beginTransaction();
      try {
        $pdo->exec($sql);
        $ins = $pdo->prepare("INSERT INTO `_migrations` (filename, checksum, applied_at) VALUES (?, ?, NOW())");
        $ins->execute([$f, $checksum]);
        $pdo->commit();
        $log[] = "APPLIED {$f}";
      } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException("Failed on {$f}: " . $e->getMessage());
      }
    }
    $notice = 'Migrations run complete.';
  }
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Migrations Runner</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>.mono{font-family:ui-monospace,Menlo,Consolas,monospace}</style>
</head>
<body class="p-3">
<div class="container-lg">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">SQL Migrations Runner</h1>
    <span class="badge bg-secondary">PHP 8.4</span>
  </div>

  <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>'.h($er).'</li>'; ?></ul></div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-body">
      <p>Looks for <code>/public_html/migrations/*.sql</code> and applies them in order, tracking progress in <code>_migrations</code>.</p>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <button class="btn btn-primary" name="action" value="run">Run Migrations</button>
      </form>
    </div>
  </div>

  <h2 class="h6">Detected files</h2>
  <?php if (!empty($files)): ?>
    <ul class="mono"><?php foreach ($files as $f) echo '<li>'.h($f).'</li>'; ?></ul>
  <?php else: ?>
    <div class="text-muted">No .sql files found. Create the folder <code>/public_html/migrations</code> and add scripts.</div>
  <?php endif; ?>

  <?php if (!empty($log)): ?>
    <hr class="my-4">
    <h2 class="h6">Run Log</h2>
    <pre class="bg-light p-3 mono" style="white-space:pre-wrap;"><?= h(implode("\n", $log)) ?></pre>
  <?php endif; ?>
</div>
</body>
</html>