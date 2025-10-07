<?php
// includes/StockMoveWriter.php (updated to subtract reservations for availability)
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Availability.php';

class StockMoveWriter
{
    private static function normalize(array $payload): array
    {
        $txnDateInput = $payload['txn_date'] ?? date('Y-m-d');
        $txnDate = date('Y-m-d', strtotime($txnDateInput));
        return [
            'txn_type'     => (string)($payload['txn_type'] ?? ''),
            'txn_no'       => (string)($payload['txn_no'] ?? ''),
            'txn_date'     => $txnDate,
            'project_id'   => isset($payload['project_id']) ? (int)$payload['project_id'] : null,
            'item_id'      => (int)($payload['item_id'] ?? 0),
            'uom_id'       => isset($payload['uom_id']) ? (int)$payload['uom_id'] : null,
            'warehouse_id' => (int)($payload['warehouse_id'] ?? 0),
            'bin_id'       => isset($payload['bin_id']) ? (int)$payload['bin_id'] : null,
            'batch_id'     => isset($payload['batch_id']) ? (int)$payload['batch_id'] : null,
            'qty'          => (float)($payload['qty'] ?? 0),
            'unit_cost'    => (float)($payload['unit_cost'] ?? 0.0),
            'ref_entity'   => (string)($payload['ref_entity'] ?? ($payload['ref_table'] ?? '')),
            'ref_id'       => isset($payload['ref_id']) ? (int)$payload['ref_id'] : null,
            'created_by'   => isset($payload['created_by']) ? (int)$payload['created_by'] : null,
        ];
    }

    private static function insert(PDO $pdo, array $row): void
    {
        if ($row['item_id'] <= 0 || $row['warehouse_id'] <= 0 || $row['qty'] <= 0) {
            throw new RuntimeException('Invalid stock move payload (item_id/warehouse_id/qty).');
        }
        if ($row['txn_type'] === '' || $row['txn_no'] === '') {
            throw new RuntimeException('Invalid stock move payload (txn_type/txn_no).');
        }

        $sql = "INSERT INTO stock_moves
                (txn_type, txn_no, txn_date, project_id, item_id, uom_id, warehouse_id, bin_id, batch_id,
                 qty, unit_cost, ref_entity, ref_id, created_by)
                VALUES
                (:txn_type, :txn_no, :txn_date, :project_id, :item_id, :uom_id, :warehouse_id, :bin_id, :batch_id,
                 :qty, :unit_cost, :ref_entity, :ref_id, :created_by)";
        $st = $pdo->prepare($sql);
        $st->execute([
            ':txn_type'=>$row['txn_type'], ':txn_no'=>$row['txn_no'], ':txn_date'=>$row['txn_date'],
            ':project_id'=>$row['project_id'], ':item_id'=>$row['item_id'], ':uom_id'=>$row['uom_id'],
            ':warehouse_id'=>$row['warehouse_id'], ':bin_id'=>$row['bin_id'], ':batch_id'=>$row['batch_id'],
            ':qty'=>$row['qty'], ':unit_cost'=>$row['unit_cost'], ':ref_entity'=>$row['ref_entity'] ?: null,
            ':ref_id'=>$row['ref_id'], ':created_by'=>$row['created_by'],
        ]);
    }

    public static function postIn(PDO $pdo, array $payload): void
    {
        $row = self::normalize($payload);
        if ($row['qty'] <= 0) throw new RuntimeException('IN movement requires qty > 0.');
        self::insert($pdo, $row);
    }

    public static function postOut(PDO $pdo, array $payload): void
    {
        $row = self::normalize($payload);
        if ($row['qty'] <= 0) throw new RuntimeException('OUT movement requires qty > 0.');

        if (class_exists('Policy') && Policy::blockNegativeStockForWarehouse($pdo, (int)$row['warehouse_id'])) {
            $available = Availability::available($pdo, (int)$row['item_id'], (int)$row['warehouse_id']);
            if ($available + 1e-9 < (float)$row['qty']) {
                throw new RuntimeException('NEG_STOCK_BLOCKED: Not enough available stock at this warehouse.');
            }
        }
        self::insert($pdo, $row);
    }
}
