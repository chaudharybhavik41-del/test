<?php
/** PATH: /public_html/includes/rbac.php
 * PURPOSE: Permission checks using permissions.code
 */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('has_permission')) {
  function has_permission(string $permCode): bool {
    $u = current_user();
    if (!$u) return false;

    // Super Admin shortcut: role_id = 1
    if (!empty($u['role_ids']) && in_array(1, $u['role_ids'], true)) return true;

    if (!isset($_SESSION['permission_codes']) || !is_array($_SESSION['permission_codes'])) {
      $pdo = db();
      $stmt = $pdo->prepare("
        SELECT DISTINCT p.code
        FROM user_roles ur
        JOIN role_permissions rp ON rp.role_id = ur.role_id
        JOIN permissions      p  ON p.id = rp.permission_id
        WHERE ur.user_id = ?
      ");
      $stmt->execute([$u['id']]);
      $_SESSION['permission_codes'] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'code');
    }
    return in_array($permCode, $_SESSION['permission_codes'], true);
  }
}

if (!function_exists('require_permission')) {
  function require_permission(string $permCode): void {
    if (!has_permission($permCode)) {
      http_response_code(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo "403 Forbidden - missing permission: {$permCode}\n";
      exit;
    }
  }
}

if (!function_exists('rbac_refresh')) {
  function rbac_refresh(): void { unset($_SESSION['permission_codes']); }
}

// --- RBAC compatibility shim ---
// Some modules call rbac_can($perm), others use has_permission()/user_can().
// Define rbac_can() only if it doesn't already exist.
if (!function_exists('rbac_can')) {
  function rbac_can(string $perm): bool {
    if (function_exists('has_permission')) {
      return has_permission($perm);
    }
    if (function_exists('user_can')) {
      return user_can($perm);
    }
    // Fallback: if no checker exists, allow (or set to false if you prefer fail-closed)
    return true;
  }
}
