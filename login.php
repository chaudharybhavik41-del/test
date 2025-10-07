<?php
// PATH: /public_html/login.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/csrf.php';

// app_url() fallback
if (!function_exists('app_url')) {
  function app_url(): string { return '/'; }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>EMS Infra ERP — Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background: #f8f9fa;
      min-height: 100vh;
      display: flex;
      align-items: center;
    }
    .login-card {
      border-radius: 1rem;
    }
    .logo {
      max-width: 120px;
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-sm-10 col-md-6 col-lg-4">
        <div class="card shadow-sm login-card">
          <div class="card-body p-4">
            <div class="text-center">
              <img src="/assets/logo.jpg" alt="EMS Infra Logo" class="logo rounded-circle shadow-sm">
              <h4 class="fw-bold">EMS Infra ERP</h4>
              <p class="text-muted small mb-4">Secure Access Portal</p>
            </div>

            <?php if (!empty($_SESSION['flash_error'])): ?>
              <div class="alert alert-danger">
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
              </div>
              <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <form method="post" action="login_handler.php" autocomplete="off">
              <?= csrf_field(); ?>
              <div class="mb-3">
                <label class="form-label">Username or Email</label>
                <input name="username" class="form-control form-control-lg" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Password</label>
                <input name="password" type="password" class="form-control form-control-lg" required>
              </div>
              <button class="btn btn-primary w-100 btn-lg">Login</button>
            </form>
          </div>
          <div class="card-footer text-center small bg-light">
            <a href="<?= app_url(); ?>" class="text-decoration-none">
              <?= parse_url(app_url(), PHP_URL_HOST) ?: 'Home' ?>
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
