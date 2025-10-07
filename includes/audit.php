<?php
/** PATH: /public_html/includes/audit.php */
declare(strict_types=1);

if (!function_exists('audit_log_add')) {
    function audit_log_add(
        PDO $pdo,
        int $actorId,
        string $entity,
        int $entityId,
        string $action,
        array|string|null $before = null,
        array|string|null $after  = null
    ): void {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (actor_id, entity_type, entity_id, action, before_json, after_json, ip_addr, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $bj = is_array($before) ? json_encode($before, JSON_UNESCAPED_UNICODE) : ($before ?? null);
        $aj = is_array($after)  ? json_encode($after,  JSON_UNESCAPED_UNICODE) : ($after  ?? null);
        $stmt->execute([$actorId, $entity, $entityId, $action, $bj, $aj, $ip]);
    }
}

if (!function_exists('audit_log')) {
    function audit_log(
        PDO $pdo,
        string $entity,
        string $action,
        ?int $row_id = null,
        array|string|null $payload = null
    ): void {
        $actorId = (int)($_SESSION['user_id'] ?? 0);
        try {
            audit_log_add($pdo, $actorId, $entity, (int)($row_id ?? 0), $action, null, $payload);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[AUDIT_SHIM_FAIL] user=%d entity=%s action=%s row_id=%s err=%s',
                $actorId, $entity, $action, var_export($row_id, true), $e->getMessage()
            ));
        }
    }
}
// intentionally no closing "?>"
