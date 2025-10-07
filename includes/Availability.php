<?php
// includes/Availability.php
// Computes on-hand and available (on-hand minus reservations), using your existing tables.
// - Signs GRN/GPR as IN, ISS/GP as OUT.
// - Signs TRN by looking at stock_transfers header (from_warehouse_id/to_warehouse_id).
// - ADJ is signed via stock_adjustments.mode when present.

require_once __DIR__ . '/db.php';

class Availability
{
    public static function onhand(PDO $pdo, int $itemId, int $warehouseId): float
    {
        $sql = "
            SELECT COALESCE(SUM(
                CASE
                    WHEN m.txn_type IN ('GRN','GPR') THEN  m.qty
                    WHEN m.txn_type IN ('ISS','GP')  THEN -m.qty
                    WHEN m.txn_type =  'TRN' THEN
                         CASE
                           WHEN st.id IS NOT NULL AND st.from_warehouse_id = m.warehouse_id THEN -m.qty
                           WHEN st.id IS NOT NULL AND st.to_warehouse_id   = m.warehouse_id THEN  m.qty
                           ELSE 0
                         END
                    WHEN m.txn_type =  'ADJ' THEN
                         CASE sa.mode WHEN 'IN' THEN m.qty WHEN 'OUT' THEN -m.qty ELSE 0 END
                    ELSE 0
                END
            ), 0) AS onhand
            FROM stock_moves m
            LEFT JOIN stock_transfers st
              ON (m.ref_entity='stock_transfers' AND st.id=m.ref_id)
            LEFT JOIN stock_adjustments sa
              ON (m.ref_entity='stock_adjustments' AND sa.id=m.ref_id)
            WHERE m.item_id = :i AND m.warehouse_id = :w
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':i'=>$itemId, ':w'=>$warehouseId]);
        return (float)$st->fetchColumn();
    }

    public static function reserved(PDO $pdo, int $itemId, int $warehouseId): float
    {
        $sql = "SELECT COALESCE(SUM(qty),0) FROM item_reservations WHERE item_id=:i AND warehouse_id=:w";
        $st = $pdo->prepare($sql);
        $st->execute([':i'=>$itemId, ':w'=>$warehouseId]);
        return (float)$st->fetchColumn();
    }

    public static function available(PDO $pdo, int $itemId, int $warehouseId): float
    {
        return self::onhand($pdo, $itemId, $warehouseId) - self::reserved($pdo, $itemId, $warehouseId);
    }
}
