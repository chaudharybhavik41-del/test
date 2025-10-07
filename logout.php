<?php
require_once __DIR__ . '/config.php';
logout_user();
header('Location: login.php'); exit;
