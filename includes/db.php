<?php
/** PATH: /public_html/includes/db.php
 * PURPOSE: Central db() using ROOT /config.php first, then /includes/config.php
 * SAFE: Idempotent (won’t redeclare db()) even if included multiple times.
 */
declare(strict_types=1);

// Locate config in project root first, then /includes (fallback)
$rootCfg = dirname(__DIR__) . '/config.php';
$incCfg  = __DIR__ . '/config.php';

if (is_file($rootCfg)) {
  require_once $rootCfg;
} elseif (is_file($incCfg)) {
  require_once $incCfg;
} else {
  header('Content-Type: text/plain; charset=utf-8');
  http_response_code(500);
  echo "CONFIG MISSING: put config.php in project root (preferred) or /includes.\n";
  exit;
}

// Define db() only once, even if this file gets included twice
if (!function_exists('db')) {
  function db(): \PDO {
    static $pdo = null;
    if ($pdo instanceof \PDO) return $pdo;

    foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $c) {
      if (!defined($c)) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo "DB config constant missing: {$c}\n";
        exit;
      }
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new \PDO($dsn, DB_USER, DB_PASS, [
      \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Ensure session collation
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("SET collation_connection = 'utf8mb4_general_ci'");

    return $pdo;
  }
}
