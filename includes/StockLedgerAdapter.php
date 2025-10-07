<?php
// includes/StockLedgerAdapter.php
require_once __DIR__ . '/db.php';

class StockLedgerAdapter
{
    public static function mirror(PDO $pdo, array $payload): void
    {
        if (!$payload) return;

        $txnDate     = $payload['txn_date'] ?? date('Y-m-d H:i:s');
        $txnType     = $payload['txn_type'] ?? 'NA';
        $txnNo       = $payload['txn_no'] ?? '';
        $itemId      = (int)($payload['item_id'] ?? 0);
        $warehouseId = (int)($payload['warehouse_id'] ?? 0);
        $projectId   = isset($payload['project_id']) ? (int)$payload['project_id'] : null;
        $binId       = isset($payload['bin_id']) ? (int)$payload['bin_id'] : null;
        $batchId     = isset($payload['batch_id']) ? (int)$payload['batch_id'] : null;
        $qty         = (float)($payload['qty'] ?? 0);
        $unitCost    = isset($payload['unit_cost']) ? (float)$payload['unit_cost'] : 0.0;
        $uomId       = isset($payload['uom_id']) ? (int)$payload['uom_id'] : null;

        // accept either ref_table or ref_entity for compatibility
        $refTable    = $payload['ref_table'] ?? ($payload['ref_entity'] ?? null);
        $refId       = isset($payload['ref_id']) ? (int)$payload['ref_id'] : null;
        $createdBy   = isset($payload['created_by']) ? (int)$payload['created_by'] : null;

        if ($qty == 0 || $itemId <= 0 || $warehouseId <= 0) {
            return;
        }

        // For OUT types, prefer WA rate from stock_avg if available
        $rate = $unitCost;
        if (in_array(($payload['txn_type'] ?? ''), ['ADJ','GP','ISS'], true)) {
            $q = $pdo->prepare('SELECT avg_cost FROM stock_avg WHERE item_id = :i AND warehouse_id = :w');
            $q->execute([':i' => $itemId, ':w' => $warehouseId]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r && isset($r['avg_cost']) && (float)$r['avg_cost'] > 0) {
                $rate = (float)$r['avg_cost'];
            }
        }

        $stmt = $pdo->prepare('INSERT INTO stock_ledger
            (txn_date, txn_type, txn_no, item_id, warehouse_id, project_id, bin_id, batch_id,
             qty, rate, uom_id, ref_table, ref_id, created_by, created_at)
            VALUES
            (:txn_date, :txn_type, :txn_no, :item_id, :warehouse_id, :project_id, :bin_id, :batch_id,
             :qty, :rate, :uom_id, :ref_table, :ref_id, :created_by, NOW(6))');

        $stmt->execute([
            ':txn_date'     => $txnDate,
            ':txn_type'     => $txnType,
            ':txn_no'       => $txnNo,
            ':item_id'      => $itemId,
            ':warehouse_id' => $warehouseId,
            ':project_id'   => $projectId,
            ':bin_id'       => $binId,
            ':batch_id'     => $batchId,
            ':qty'          => $qty,
            ':rate'         => $rate,
            ':uom_id'       => $uomId,
            ':ref_table'    => $refTable,
            ':ref_id'       => $refId,
            ':created_by'   => $createdBy,
        ]);
    }
}
