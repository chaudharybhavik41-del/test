<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/csrf.php';

// Set sane defaults
date_default_timezone_set('Asia/Kolkata');
mb_internal_encoding('UTF-8');

// Strict PDO errors as exceptions (done inside db.php)
