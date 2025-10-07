<?php
// PATH: /public_html/tools/_post_probe.php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

// Load CSRF so token names match
require_once __DIR__ . '/../includes/csrf.php';

echo "[1] method=" . ($_SERVER['REQUEST_METHOD'] ?? '') . "\n";
echo "[2] has_csrf_field=" . (isset($_POST['csrf_token']) ? 'yes' : 'no') . "\n";
echo "[3] session_id=" . session_id() . "\n";

// check CSRF (don’t die silently)
try {
  verify_csrf_or_die();
  echo "[4] csrf=OK\n";
} catch (Throwable $e) {
  echo "[4X] csrf=FAIL (" . $e->getMessage() . ")\n";
  exit;
}

echo "[5] username=" . ($_POST['username'] ?? '') . "\n";
echo "[6] done\n";
