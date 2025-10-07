<?php
/**
 * PATH: /public_html/login_handler_debug.php
 * PURPOSE: Self-contained login (no app includes). Prints clear messages instead of redirecting.
 * NOTE: After testing, switch back to the normal handler.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

echo "[0] start\n";
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { echo "[0X] not POST\n"; exit; }

/* ---- BASIC CSRF inline (matches your form field name) ---- */
$ok = isset($_POST['csrf_token']) && isset($_SESSION['csrf_token'])
   && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token']);
if (!$ok) { echo "[1X] CSRF mismatch\n"; exit; }
echo "[1] CSRF ok\n";

/* ---- READ CONFIG (ROOT) ---- */
$cfg = __DIR__ . '/config.php';
if (!is_file($cfg)) { echo "[2X] config.php not found in root\n"; exit; }
require_once $cfg;
foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $c) {
  if (!defined($c)) { echo "[2X] missing constant: $c\n"; exit; }
}
echo "[2] config ok\n";

/* ---- CONNECT PDO DIRECTLY ---- */
try {
  $pdo = new PDO(
    'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
    DB_USER, DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
  echo "[3] db ok\n";
} catch (Throwable $e) {
  echo "[3X] db error: ".$e->getMessage()."\n"; exit;
}

/* ---- AUTH ---- */
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$password = rtrim($password, "\r\n"); // avoid accidental newline from clipboard
if ($username === '' || $password === '') { echo "[4X] missing username/password\n"; exit; }

try {
  $st = $pdo->prepare("SELECT id, username, name, email, password, status FROM users WHERE username = ? OR email = ? LIMIT 1");
  $st->execute([$username, $username]);
  $u = $st->fetch();
  if (!$u) { echo "[5X] user not found\n"; exit; }
  echo "[5] user found status=".$u['status']."\n";
  if ($u['status'] !== 'active') { echo "[5X] user inactive\n"; exit; }

  if (!password_verify($password, $u['password'])) { echo "[6X] password mismatch\n"; exit; }
  echo "[6] password ok\n";

  // load role ids (RBAC expects these on session)
  $rs = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
  $rs->execute([(int)$u['id']]);
  $roleIds = array_map('intval', array_column($rs->fetchAll(), 'role_id'));
  echo "[7] roles=".json_encode($roleIds)."\n";

  // set session user (like login_user would)
  $_SESSION['user'] = [
    'id' => (int)$u['id'],
    'username' => $u['username'],
    'name' => $u['name'],
    'email' => $u['email'],
    'role_ids' => $roleIds,
  ];
  $before = session_id();
  session_regenerate_id(true);
  echo "[8] session set (sid $before -> ".session_id().")\n";

  echo "[9] SUCCESS. Next step would redirect to dashboard.php\n";
  echo "Open /dashboard.php now in a new tab.\n";
} catch (Throwable $e) {
  echo "[EX] ".$e->getMessage()."\n"; exit;
}
