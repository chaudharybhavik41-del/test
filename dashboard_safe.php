<?php
declare(strict_types=1);

/**
 * Dashboard (SAFE MODE)
 * - Does NOT require auth/ui/helpers — so it cannot fatal on missing includes
 * - Tries to read counts if /includes/db.php exists; otherwise shows 0
 * - Turn on verbose errors with ?debug=1
 */

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

function try_require_once(string $path): bool {
  if (is_file($path)) { require_once $path; return true; }
  return false;
}

// Try to get $pdo (optional)
$pdo = null;
if (try_require_once(__DIR__ . '/includes/db.php') && isset($pdo) && $pdo instanceof PDO) {
  // ok
} else {
  $pdo = null; // keep null-safe
}

// Null-safe counter (never fatal)
function safeCount(?PDO $pdo, string $sql): int {
  if (!$pdo) return 0;
  try { return (int)$pdo->query($sql)->fetchColumn(); }
  catch (Throwable $e) { return 0; }
}

$counts = [
  'uom'           => safeCount($pdo, "SELECT COUNT(*) FROM uom"),
  'categories'    => safeCount($pdo, "SELECT COUNT(*) FROM material_categories WHERE is_active=1"),
  'subcategories' => safeCount($pdo, "SELECT COUNT(*) FROM material_subcategories WHERE is_active=1"),
  'items'         => safeCount($pdo, "SELECT COUNT(*) FROM items WHERE is_active=1"),
  'projects'      => safeCount($pdo, "SELECT COUNT(*) FROM projects WHERE is_active=1"),
  'notifications' => safeCount($pdo, "SELECT COUNT(*) FROM notifications WHERE is_read=0"),
  'audit'         => safeCount($pdo, "SELECT COUNT(*) FROM audit_log"),
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard (Safe) — EMS Infra ERP</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body{background:#f7f7fb}
  .card .display-6{font-weight:600}
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white border-bottom">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="#">EMS Infra ERP</a>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-primary btn-sm" href="/material/index.php"><i class="bi bi-diagram-3"></i> Taxonomy</a>
      <a class="btn btn-primary btn-sm" href="/items/items_form.php"><i class="bi bi-plus-lg"></i> New Item</a>
    </div>
  </div>
</nav>

<main class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">Dashboard (Safe Mode)</h1>
      <div class="text-muted small">No auth/ui includes — for debugging only</div>
      <?php if ($DEBUG): ?><span class="badge text-bg-warning mt-2">DEBUG ON</span><?php endif; ?>
    </div>
    <div>
      <a class="btn btn-outline-secondary btn-sm" href="?debug=1">Enable Debug</a>
    </div>
  </div>

  <h2 class="h6 text-uppercase text-muted mb-2">Master Data</h2>
  <div class="row g-3">
    <div class="col-12 col-sm-6 col-lg-3">
      <a href="/uom/uom_list.php" class="text-decoration-none">
        <div class="card shadow-sm h-100"><div class="card-body d-flex justify-content-between">
          <div><div class="fw-semibold">UOM</div><div class="display-6"><?= $counts['uom'] ?></div></div>
          <i class="bi bi-rulers fs-1 opacity-50"></i>
        </div></div>
      </a>
    </div>

    <div class="col-12 col-sm-6 col-lg-3">
      <a href="/material/categories_list.php" class="text-decoration-none">
        <div class="card shadow-sm h-100"><div class="card-body d-flex justify-content-between">
          <div><div class="fw-semibold">Categories</div><div class="display-6"><?= $counts['categories'] ?></div></div>
          <i class="bi bi-collection fs-1 opacity-50"></i>
        </div></div>
      </a>
    </div>

    <div class="col-12 col-sm-6 col-lg-3">
      <a href="/material/subcategories_list.php" class="text-decoration-none">
        <div class="card shadow-sm h-100"><div class="card-body d-flex justify-content-between">
          <div><div class="fw-semibold">Subcategories</div><div class="display-6"><?= $counts['subcategories'] ?></div></div>
          <i class="bi bi-diagram-3 fs-1 opacity-50"></i>
        </div></div>
      </a>
    </div>

    <div class="col-12 col-sm-6 col-lg-3">
      <a href="/items/items_list.php" class="text-decoration-none">
        <div class="card shadow-sm h-100"><div class="card-body d-flex justify-content-between">
          <div><div class="fw-semibold">Items</div><div class="display-6"><?= $counts['items'] ?></div></div>
          <i class="bi bi-box-seam fs-1 opacity-50"></i>
        </div></div>
      </a>
    </div>
  </div>

  <h2 class="h6 text-uppercase text-muted mt-4 mb-2">Operations</h2>
  <div class="row g-3">
    <div class="col-12 col-sm-6 col-lg-3">
      <a href="/projects/projects_list.php" class="text-decoration-none">
        <div class="card shadow-sm h-100"><div class="card-body d-flex justify-content-between">
          <div><div class="fw-semibold">Projects</div><div class="display-6"><?= $counts['projects'] ?></div></div>
          <i class="bi bi-kanban fs-1 opacity-50"></i>
        </div></div>
      </a>
    </div>

    <div class="col-12 col-sm-6 col-lg-3">
      <a href="/notifications/center.php" class="text-decoration-none">
        <div class="card shadow-sm h-100"><div class="card-body d-flex justify-content-between">
          <div><div class="fw-semibold">Notifications</div><div class="display-6"><?= $counts['notifications'] ?></div></div>
          <i class="bi bi-bell fs-1 opacity-50"></i>
        </div></div>
      </a>
    </div>

    <div class="col-12 col-sm-6 col-lg-3">
      <a href="/audit_log.php" class="text-decoration-none">
        <div class="card shadow-sm h-100"><div class="card-body d-flex justify-content-between">
          <div><div class="fw-semibold">Audit Log</div><div class="display-6"><?= $counts['audit'] ?></div></div>
          <i class="bi bi-clipboard-data fs-1 opacity-50"></i>
        </div></div>
      </a>
    </div>
  </div>

  <h2 class="h6 text-uppercase text-muted mt-4 mb-2">Quick Actions</h2>
  <div class="row g-2">
    <div class="col-auto"><a class="btn btn-outline-secondary" href="/material/categories_form.php"><i class="bi bi-plus-circle"></i> New Category</a></div>
    <div class="col-auto"><a class="btn btn-outline-secondary" href="/material/subcategories_form.php"><i class="bi bi-plus-circle"></i> New Subcategory</a></div>
    <div class="col-auto"><a class="btn btn-outline-secondary" href="/uom/uom_form.php"><i class="bi bi-plus-circle"></i> New UOM</a></div>
    <div class="col-auto"><a class="btn btn-primary" href="/items/items_form.php"><i class="bi bi-plus-circle"></i> New Item</a></div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
