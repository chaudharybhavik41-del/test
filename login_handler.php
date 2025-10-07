<?php
/** PATH: /public_html/login_handler.php */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

function fail_login(string $msg, string $to = 'login.php'): void {
  $_SESSION['flash_error'] = $msg;
  header('Location: ' . $to);
  exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') fail_login('Invalid request method');

try {
  verify_csrf_or_die();
} catch (Throwable $e) {
  fail_login('Security token expired. Please try again.');
}

$username = trim((string)($_POST['username'] ?? ''));
$password = rtrim((string)($_POST['password'] ?? ''), "\r\n");
if ($username === '' || $password === '') fail_login('Username and password are required');

try {
  $pdo = db();
  $st = $pdo->prepare("SELECT id, username, name, email, password, status FROM users WHERE username = ? OR email = ? LIMIT 1");
  $st->execute([$username, $username]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  if (!$u)                       fail_login('User not found');
  if ($u['status'] !== 'active') fail_login('Account is inactive');
  if (!password_verify($password, $u['password'])) fail_login('Invalid credentials');

  $rs = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
  $rs->execute([(int)$u['id']]);
  $roleIds = array_map('intval', array_column($rs->fetchAll(PDO::FETCH_ASSOC), 'role_id'));

  if (function_exists('login_user')) {
    login_user([
      'id'       => (int)$u['id'],
      'username' => $u['username'],
      'name'     => $u['name'],
      'email'    => $u['email'],
      'role_ids' => $roleIds,
    ]);
  } else {
    $_SESSION['user'] = [
      'id'       => (int)$u['id'],
      'username' => $u['username'],
      'name'     => $u['name'],
      'email'    => $u['email'],
      'role_ids' => $roleIds,
    ];
    $_SESSION['regenerated_at'] = time();
    session_regenerate_id(true);
  }

  header('Location: dashboard.php'); exit;

} catch (Throwable $e) {
  fail_login('Internal error, please try again');
}
