<?php
declare(strict_types=1);
if (!function_exists('h')) {
  function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('set_flash')) {
  function set_flash(string $type, string $msg): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
  }
}
if (!function_exists('render_flash')) {
  function render_flash(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['flash'])) return;
    foreach ($_SESSION['flash'] as $f) {
      $type = $f['type'] ?? 'info'; $msg = $f['msg'] ?? '';
      echo '<div class="alert alert-' . h($type) . ' alert-dismissible fade show" role="alert">'
         . h($msg)
         . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
         . '</div>';
    }
    unset($_SESSION['flash']);
  }
}
// --- Flash message helper (shim) ---
if (!function_exists('flash')) {
    /**
     * Set or get flash messages.
     * flash($msg, $type)   → store message
     * flash()              → fetch & clear all messages
     */
    function flash(string $msg=null, string $type='info') {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if ($msg !== null) {
            $_SESSION['flash'][] = ['msg'=>$msg,'type'=>$type];
            return;
        }
        $out = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $out;
    }
}
/** PATH: /public_html/includes/helpers.php */


/**
 * Escape for HTML output (XSS safe).
 */
if (!function_exists('h')) {
    function h(?string $str): string {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Flash messages (store in session).
 */
if (!function_exists('flash')) {
    function flash(string $msg=null, string $type='info') {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if ($msg !== null) {
            $_SESSION['flash'][] = ['msg'=>$msg,'type'=>$type];
            return;
        }
        $out = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $out;
    }
}

/**
 * Redirect to another URL and stop execution.
 */
if (!function_exists('redirect')) {
    function redirect(string $url): void {
        if (!headers_sent()) {
            header("Location: ".$url, true, 302);
        }
        exit;
    }
}

/**
 * Convenience for current user id (if you already store in session).
 */
if (!function_exists('current_user_id')) {
    function current_user_id(): ?int {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}
