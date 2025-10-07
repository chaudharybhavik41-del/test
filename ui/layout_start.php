<?php
declare(strict_types=1);
/** UI Layout Start (Bootstrap 5.3) — UI only, no business logic */

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/** HTML escape */
if (!function_exists('h')) {
  function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Flash helper (fallback).
 * - If already defined elsewhere, we won't redeclare.
 * - Usage:
 *    flash('success','Saved')  // queue
 *    foreach (flash() as $f) { ... }  // fetch & clear
 */
if (!function_exists('flash')) {
  function flash(?string $type = null, ?string $msg = null): array {
    if ($type !== null && $msg !== null) {
      $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
      return [];
    }
    $out = $_SESSION['_flash'] ?? [];
    if (!is_array($out)) $out = [];
    unset($_SESSION['_flash']); // clear after reading
    return $out;
  }
}

/** Soft-depend on components; don't fatal if missing */
@include __DIR__ . '/components.php';

/** Title/Brand fallbacks */
$app_name   = $app_name   ?? ($_ENV['APP_NAME'] ?? 'EMS Infra ERP');
$page_title = $page_title ?? ($PAGE_TITLE ?? $app_name);
$currentPath = $_SERVER['SCRIPT_NAME'] ?? '';
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($page_title) ?></title>

  <!-- Bootstrap & Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <!-- Optional site styles (served from public_html root if present) -->
  <link rel="stylesheet" href="/styles.css">

  <style>
    :root { --sidebar-width: 260px; }
    html, body { height: 100%; }
    body { min-height: 100vh; }
    .app-shell { display: flex; min-height: calc(100vh - 56px); }
    .app-sidebar { width: var(--sidebar-width); border-right: 1px solid #eef0f2; background: #fff; }
    @media (max-width: 991.98px) { .app-sidebar { display: none; } }
    .app-content { flex: 1; }
    .brand { font-weight: 600; letter-spacing: .2px; }
    .navbar-brand img { height: 32px; width: auto; }
    .nav-link.active { background: #f1f5f9; font-weight: 600; }
    .offcanvas-sidebar { width: var(--sidebar-width); }
    .card-tile { transition: transform .15s ease; }
    .card-tile:hover { transform: translateY(-2px); }
  </style>
</head>
<body>

  <?php foreach (flash() as $f): ?>
    <div class="alert alert-<?= h($f['type'] ?? 'info') ?> m-2">
      <?= h($f['msg'] ?? '') ?>
    </div>
  <?php endforeach; ?>

  <!-- Header -->
  <nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom sticky-top">
    <div class="container-fluid">
      <button class="btn btn-outline-primary d-lg-none me-2" type="button"
              data-bs-toggle="offcanvas" data-bs-target="#offcanvasSidebar" aria-controls="offcanvasSidebar"
              aria-label="Open menu">
        <i class="bi bi-list"></i>
      </button>

      <a class="navbar-brand d-flex align-items-center" href="/dashboard.php">
        <img src="/assets/logo.jpg" alt="EMS Infra ERP Logo" class="me-2">
        <span class="brand"><?= h($app_name) ?></span>
      </a>

      <div class="ms-auto d-flex align-items-center gap-2">
        <?php @include __DIR__ . '/partials/notifications_bell.php'; ?>

        <div class="dropdown">
          <a href="#" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-person-circle me-1"></i><?= h($_SESSION['user_name'] ?? 'User') ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="/identity/profile.php"><i class="bi bi-gear me-2"></i> Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
          </ul>
        </div>
      </div>
    </div>
  </nav>

  <!-- App shell -->
  <div class="app-shell">
    <!-- Desktop sidebar -->
    <aside class="app-sidebar">
      <?php @include __DIR__ . '/sidebar.php'; ?>
    </aside>

    <!-- Main content -->
    <main class="app-content">
      <div class="container-fluid py-3">
