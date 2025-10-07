<?php
/** PATH: /public_html/includes/csrf.php */
declare(strict_types=1);

/* ---------- Session helper ---------- */
function _csrf_ensure_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/* ---------- Token generator (single source of truth) ---------- */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        _csrf_ensure_session();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/* ---------- Hidden input helpers (both names supported) ---------- */
if (!function_exists('csrf_field')) {
    // Standard name: csrf_token
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_hidden_input')) {
    // Legacy/alt helper; emits the same token but with legacy name "_csrf"
    function csrf_hidden_input(): string {
        return "<input type='hidden' name='_csrf' value='" . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . "'>";
    }
}

/* ---------- Validator that accepts either field name ---------- */
if (!function_exists('csrf_require_token')) {
    function csrf_require_token(): void {
        _csrf_ensure_session();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return; // Only enforce on POST
        }

        // Accept either "csrf_token" (preferred) or "_csrf" (legacy)
        $sent = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? '');

        $ok = $sent !== '' && hash_equals(csrf_token(), $sent);
        if (!$ok) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Invalid CSRF token");
        }
    }
}

/* ---------- Optional: explicit verifier with 419 status (if used elsewhere) ---------- */
if (!function_exists('verify_csrf_or_die')) {
    function verify_csrf_or_die(): void {
        _csrf_ensure_session();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
        $sent = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? '');
        $ok = $sent !== '' && hash_equals(csrf_token(), $sent);
        if (!$ok) {
            http_response_code(419);
            header('Content-Type: text/plain; charset=utf-8');
            exit("CSRF token mismatch. Please reload the page and try again.");
        }
    }
}
