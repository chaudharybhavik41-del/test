<?php
/** PATH: /public_html/material/items_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

// Keep all Item create/edit in the canonical module
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = '../items/items_form.php' . ($qs ? ('?' . $qs) : '');
header('Location: ' . $target);
exit;
