<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

echo "<pre>";
echo "PHP: " . PHP_VERSION . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? '-') . "\n";
echo "Script: " . __FILE__ . "\n\n";

$paths = [
  '/includes/db.php',
  '/includes/auth.php',
  '/includes/rbac.php',
  '/ui/layout_start.php',
  '/ui/layout_end.php',
  '/uom/uom_list.php',
  '/material/index.php',
  '/items/items_list.php',
];
foreach ($paths as $p) {
  $full = __DIR__ . $p;
  echo str_pad($p, 35) . (is_file($full) ? "OK" : "MISSING") . "\n";
}

echo "\nInclude Path:\n" . get_include_path() . "\n";

$log = '/home/u989675055/logs/php-error.log';
echo "\nError log path (from .user.ini): $log\n";
echo (is_file($log) ? "Log exists (tail it via panel/ssh)" : "Log not found or no permission") . "\n";
echo "</pre>";
