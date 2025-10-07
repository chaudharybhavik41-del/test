<?php
/** PATH: /public_html/includes/services/StockMoveWriter.php */
declare(strict_types=1);

/**
 * Transaction-free stock writer.
 * Assumes the caller (API endpoint) has already started/committed/rolled back the PDO transaction.
 * Requires tables: stock_moves, stock_onhand (with UNIQUE uq_onhand(item_id,warehouse_id,bin_id,batch_id)).
 */
final class StockMoveWriter
{
    /**
     * Post an IN movement (positive qty).
     * Expected keys in $m: txn_type, txn_no, txn_date (Y-m-d), project_id|null, item_id, uom_id,
     * warehouse_id, bin_id|null, batch_id|null, qty (>0), unit_cost (>=0), ref_entity, ref_id, created_by.
     */
    public static function postIn(PDO $pdo, array $m): void
    {
        self::assertRequired($m, ['txn_type','txn_no','txn_date','item_id','uom_id','warehouse_id','qty','ref_entity','ref_id']);
        $qty = (float)$m['qty'];
        if ($qty <= 0) throw new RuntimeException('postIn qty must be > 0');

        $unit_cost = isset($m['unit_cost']) ? (float)$m['unit_cost'] : 0.0;

        // 1) Insert move (positive qty)
        $insMove = $pdo->prepare("
            INSERT INTO stock_moves
            (txn_type, txn_no, txn_date, project_id, item_id, uom_id, warehouse_id, bin_id, batch_id, qty, unit_cost, ref_entity, ref_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insMove->execute([
            (string)$m['txn_type'], (string)$m['txn_no'], (string)$m['txn_date'],
            $m['project_id'] ?? null,
            (int)$m['item_id'], (int)$m['uom_id'], (int)$m['warehouse_id'],
            $m['bin_id'] ?? null, $m['batch_id'] ?? null,
            $qty, $unit_cost,
            (string)$m['ref_entity'], (int)$m['ref_id'],
            $m['created_by'] ?? null
        ]);

        // 2) Upsert on-hand
        $upsert = $pdo->prepare("
            INSERT INTO stock_onhand (item_id, warehouse_id, bin_id, batch_id, qty)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = CURRENT_TIMESTAMP
        ");
        $upsert->execute([(int)$m['item_id'], (int)$m['warehouse_id'], $m['bin_id'] ?? null, $m['batch_id'] ?? null, $qty]);
    }

    /**
     * Post an OUT movement (negative qty).
     * Same keys as postIn. Qty must be > 0; we write negative in ledger and subtract in onhand.
     */
    public static function postOut(PDO $pdo, array $m): void
    {
        self::assertRequired($m, ['txn_type','txn_no','txn_date','item_id','uom_id','warehouse_id','qty','ref_entity','ref_id']);
        $qty = (float)$m['qty'];
        if ($qty <= 0) throw new RuntimeException('postOut qty must be > 0');

        // 1) Insert move (negative qty)
        $insMove = $pdo->prepare("
            INSERT INTO stock_moves
            (txn_type, txn_no, txn_date, project_id, item_id, uom_id, warehouse_id, bin_id, batch_id, qty, unit_cost, ref_entity, ref_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
        ");
        $insMove->execute([
            (string)$m['txn_type'], (string)$m['txn_no'], (string)$m['txn_date'],
            $m['project_id'] ?? null,
            (int)$m['item_id'], (int)$m['uom_id'], (int)$m['warehouse_id'],
            $m['bin_id'] ?? null, $m['batch_id'] ?? null,
            -$qty,                                  // ledger as negative
            (string)$m['ref_entity'], (int)$m['ref_id'],
            $m['created_by'] ?? null
        ]);

        // 2) Upsert on-hand (subtract)
        $upsert = $pdo->prepare("
            INSERT INTO stock_onhand (item_id, warehouse_id, bin_id, batch_id, qty)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = CURRENT_TIMESTAMP
        ");
        $upsert->execute([(int)$m['item_id'], (int)$m['warehouse_id'], $m['bin_id'] ?? null, $m['batch_id'] ?? null, -$qty]);
    }

    private static function assertRequired(array $m, array $keys): void
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $m)) {
                throw new InvalidArgumentException("Missing key: {$k}");
            }
        }
    }
}
