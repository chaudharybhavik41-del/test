<?php
/**
 * Stock Adjustment POST
 * - Creates adjustment header + lines
 * - Posts IN or OUT via StockMoveWriter
 * - NEW: Calls ValuationService and mirrors to stock_ledger
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/NumberingService.php';
require_once __DIR__ . '/includes/StockMoveWriter.php';
require_once __DIR__ . '/includes/ValuationService.php';
require_once __DIR__ . '/includes/StockLedgerAdapter.php';

header('Content-Type: application/json');

try {
    require_permission('stocks.adjust.manage');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) $input = $_POST;

    if (!empty($_POST)) {
        csrf_require_token($_POST['csrf_token'] ?? '');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $mode        = strtoupper(trim($input['mode'] ?? '')); // IN or OUT
    $reasonCode  = trim($input['reason_code'] ?? '');
    $remarks     = trim($input['remarks'] ?? '');
    $lines       = $input['lines'] ?? [];

    if ($warehouseId <= 0 || !in_array($mode, ['IN', 'OUT'], true) || empty($lines)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'warehouse_id, mode (IN/OUT) and lines are required']);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();

    $adjNo = NumberingService::next($pdo, 'ADJ'); // e.g., ADJ-2025-001234

    // Header
    $pdo->prepare("INSERT INTO stock_adjustments
            (adj_no, warehouse_id, mode, reason_code, remarks, status, created_by, created_at)
            VALUES (:adj_no, :warehouse_id, :mode, :reason_code, :remarks, 'POSTED', :created_by, :created_at)")
        ->execute([
            ':adj_no'       => $adjNo,
            ':warehouse_id' => $warehouseId,
            ':mode'         => $mode,
            ':reason_code'  => $reasonCode,
            ':remarks'      => $remarks,
            ':created_by'   => $userId,
            ':created_at'   => $now,
        ]);
    $adjId = (int)$pdo->lastInsertId();

    $insLine = $pdo->prepare("INSERT INTO stock_adjustment_items
        (adjustment_id, line_no, item_id, uom_id, qty, unit_cost, bin_id, batch_id, remarks)
        VALUES (:adjustment_id, :line_no, :item_id, :uom_id, :qty, :unit_cost, :bin_id, :batch_id, :remarks)");

    $lineNo = 0;
    foreach ($lines as $ln) {
        $lineNo++;
        $itemId    = (int)($ln['item_id'] ?? 0);
        $uomId     = isset($ln['uom_id']) ? (int)$ln['uom_id'] : null;
        $qty       = (float)($ln['qty'] ?? 0);
        $binId     = isset($ln['bin_id']) ? (int)$ln['bin_id'] : null;
        $batchId   = isset($ln['batch_id']) ? (int)$ln['batch_id'] : null;
        $lnRemarks = trim($ln['remarks'] ?? '');
        $unitCost  = (float)($ln['unit_cost'] ?? 0); // required for IN valuation

        if ($itemId <= 0 || $qty <= 0) {
            throw new RuntimeException("Invalid line #{$lineNo}");
        }

        $insLine->execute([
            ':adjustment_id' => $adjId,
            ':line_no'       => $lineNo,
            ':item_id'       => $itemId,
            ':uom_id'        => $uomId,
            ':qty'           => $qty,
            ':unit_cost'     => $unitCost,
            ':bin_id'        => $binId,
            ':batch_id'      => $batchId,
            ':remarks'       => $lnRemarks,
        ]);

        $payload = [
            'txn_type'     => 'ADJ',
            'txn_no'       => $adjNo,
            'txn_date'     => $now,
            'item_id'      => $itemId,
            'uom_id'       => $uomId,
            'warehouse_id' => $warehouseId,
            'bin_id'       => $binId,
            'batch_id'     => $batchId,
            'project_id'   => null,
            'qty'          => $qty,         // positive
            'unit_cost'    => $unitCost,    // pre-tax basic
            'ref_table'    => 'stock_adjustments',
            'ref_id'       => $adjId,
            'created_by'   => $userId,
        ];

        if ($mode === 'IN') {
            StockMoveWriter::postIn($pdo, $payload);
            ValuationService::onReceipt($pdo, $itemId, $warehouseId, $qty, $unitCost);
            StockLedgerAdapter::mirror($pdo, $payload);
        } else { // OUT
            StockMoveWriter::postOut($pdo, $payload);
            ValuationService::onIssue($pdo, $itemId, $warehouseId, $qty);
            StockLedgerAdapter::mirror($pdo, $payload);
        }
    }

    audit_log('stock_adjustments', $adjId, 'POST', null, ['adj_no' => $adjNo, 'mode' => $mode, 'lines' => count($lines)]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'adjustment_id' => $adjId, 'adj_no' => $adjNo]);

} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}