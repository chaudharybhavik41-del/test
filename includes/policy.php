<?php
// includes/policy.php
require_once __DIR__ . '/db.php';

class Policy {
    // Global fallback: set to true to block everywhere unless overridden
    public static function blockNegativeStockGlobal(): bool {
        return false; // change to true when you want hard block globally
    }

    public static function blockNegativeStockForWarehouse(PDO $pdo, int $warehouseId): bool {
        $q = $pdo->prepare("SELECT block_negative_stock FROM warehouse_policy WHERE warehouse_id = :w");
        $q->execute([':w' => $warehouseId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ? (bool)$row['block_negative_stock'] : self::blockNegativeStockGlobal();
    }
}
