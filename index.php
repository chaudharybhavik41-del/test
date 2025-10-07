<?php
require_once __DIR__ . '/config.php';
if (is_logged_in()) {
  header('Location: ' . app_url('dashboard.php')); exit;
}
header('Location: ' . app_url('login.php')); exit;
