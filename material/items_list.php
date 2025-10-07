<?php
/** PATH: /public_html/material/items_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

// Single source of truth for Items list under /items/
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = '../items/items_list.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $target, true, 301);
exit;
