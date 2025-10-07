<?php
declare(strict_types=1);

/**
 * Global config: paths, env, PDO bootstrap
 * PHP 8.4 compatible
 */
if (!defined('APP_ROOT')) {
  define('APP_ROOT', __DIR__);                         // /public_html
  define('INCLUDES_PATH', APP_ROOT . '/includes');     // /public_html/includes
  define('UI_PATH', APP_ROOT . '/ui');                 // /public_html/ui
}

@ini_set('default_charset', 'UTF-8');
@date_default_timezone_set('Asia/Kolkata');

// --- load .env (very small parser) ---
$envFile = APP_ROOT . '/.env';
if (is_file($envFile)) {
  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$k, $v] = array_map('trim', array_pad(explode('=', $line, 2), 2, ''));
    if ($k !== '') $_ENV[$k] = $v;
  }
}

// --- database settings ---
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? '');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// tiny helpers
function app_url(string $path = ''): string {
  $base = rtrim($_ENV['APP_URL'] ?? '', '/');
  return $base . '/' . ltrim($path, '/');
}

// require core includes
require_once INCLUDES_PATH . '/db.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/rbac.php';
require_once INCLUDES_PATH . '/csrf.php';
