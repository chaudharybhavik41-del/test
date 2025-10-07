<?php
/** PATH: /public_html/includes/auth.php */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rbac.php';

/** -----------------------------
 * Minimal session-based auth API
 * ----------------------------- */
function current_user(): ?array { return $_SESSION['user'] ?? null; }

function is_logged_in(): bool { return current_user() !== null; }

function require_login(): void {
  if (!is_logged_in()) {
    // Keep your existing app_url() usage as-is
    header('Location: ' . app_url('login.php'));
    exit;
  }
}

/**
 * Log the user in and harden the session.
 * - Preserves your behavior
 * - Also sets $_SESSION['user_id'] if available
 * - Clears any cached permissions (both styles)
 */
function login_user(array $user): void {
  // never store hashes in session
  if (array_key_exists('password', $user)) {
    unset($user['password']);
  }

  // set both shapes so all modules work
  if (isset($user['id'])) {
    $_SESSION['user_id'] = (int)$user['id'];
  }
  $_SESSION['user'] = $user;

  // clear any cached permission sets (both caches used across code)
  unset($_SESSION['permissions'], $_SESSION['permission_codes']);

  // rotate session id
  $_SESSION['regenerated_at'] = time();
  session_regenerate_id(true);
}

/** Current user ID helper (kept exactly as you had it, with both session shapes) */
function current_user_id(): ?int {
  if (isset($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
  if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    return (int)$_SESSION['user']['id'];
  }
  return null;
}

/**
 * Compatibility shim: user_has_permission()
 * - Delegates to your rbac.php -> has_permission() when available
 * - Else falls back to session-cached arrays (no DB calls; no load_permissions())
 * - Leaves RBAC logic untouched
 */
if (!function_exists('user_has_permission')) {
  function user_has_permission(string $code): bool {
    if (function_exists('has_permission')) {
      return has_permission($code); // use your RBAC's logic & cache (incl. super admin shortcut)
    }
    // Fallback to cached arrays if sidebar/layouts rely on them
    $perms = $_SESSION['permission_codes'] ?? $_SESSION['permissions'] ?? [];
    return is_array($perms) && in_array($code, $perms, true);
  }
}

/**
 * Logout: clear session + cached permissions (kept behavior, just also clears both caches)
 */
function logout_user(): void {
  // clear caches
  unset($_SESSION['permissions'], $_SESSION['permission_codes']);

  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
  }
  session_destroy();
}

/**
 * Optional compatibility: define require_permission only if RBAC didn't.
 * Delegates to user_has_permission() to avoid changing your rbac logic.
 */
if (!function_exists('require_permission')) {
  function require_permission(string $code): void {
    require_login();
    if (!user_has_permission($code)) {
      http_response_code(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo "403 Forbidden - missing permission: {$code}\n";
      exit;
    }
  }
}

/** Optional helper: refresh permission caches (use after role/permission changes) */
if (!function_exists('rbac_refresh')) {
  function rbac_refresh(): void {
    unset($_SESSION['permissions'], $_SESSION['permission_codes']);
  }
}