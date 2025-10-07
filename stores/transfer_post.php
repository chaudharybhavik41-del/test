<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/services/NumberingService.php';
require_once __DIR__ . '/../includes/services/StockMoveWriter.php';
require_once __DIR__ . '/../includes/services/ValuationService.php';
require_once __DIR__ . '/../includes/StockLedgerAdapter.php';

header('Content-Type: application/json');

try {
    require_permission('stores.transfer.manage');

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) { $input = $_POST; }
    if (!empty($_POST)) { csrf_require_token($_POST['csrf_token'] ?? ''); }

    $pdo = db();

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $now    = date('Y-m-d H:i:s');

    $fromWhId  = (int)($input['from_warehouse_id'] ?? 0);
    $toWhId    = (int)($input['to_warehouse_id'] ?? 0);
    $fromBinId = isset($input['from_bin_id']) ? (int)$input['from_bin_id'] : null;
    $toBinId   = isset($input['to_bin_id'])   ? (int)$input['to_bin_id']   : null;
    $projectId = isset($input['project_id'])  ? (int)$input['project_id']  : null;
    $remarks   = trim($input['remarks'] ?? '');

    // normalize lines (supports form-encoded or JSON payloads)
    $lines = [];
    if (isset($input['lines']['item_id'])) {
        $cnt = count($input['lines']['item_id']);
        for ($i=0; $i<$cnt; $i++) {
            $lines[] = [
                'item_id'  => (int)($input['lines']['item_id'][$i] ?? 0),
                'uom_id'   => isset($input['lines']['uom_id'][$i]) ? (int)$input['lines']['uom_id'][$i] : null,
                'qty'      => (float)($input['lines']['qty'][$i] ?? 0),
                'batch_id' => isset($input['lines']['batch_id'][$i]) ? (int)$input['lines']['batch_id'][$i] : null,
                'remarks'  => trim($input['lines']['remarks'][$i] ?? '')
            ];
        }
    } else {
        $lines = $input['lines'] ?? [];
    }

    // Validation
    if ($fromWhId <= 0 || $toWhId <= 0) {
        throw new RuntimeException('Source and destination warehouses are required.');
    }
    if (empty($lines)) {
        throw new RuntimeException('At least one line is required.');
    }
    // Allow same-warehouse transfer only if bins are used (and not the same bin)
    if ($fromWhId === $toWhId) {
        if (!$fromBinId && !$toBinId) {
            throw new RuntimeException('Same-warehouse transfer requires selecting From Bin and/or To Bin.');
        }
        if ($fromBinId && $toBinId && $fromBinId === $toBinId) {
            throw new RuntimeException('From Bin and To Bin cannot be the same for same-warehouse transfer.');
        }
    }

    // Begin
    $pdo->beginTransaction();

    // Use TRF (mapped to transfer_sequences) as per your NumberingService MAP
    $trnNo = NumberingService::next($pdo, 'TRF');

    // Header
    $pdo->prepare("
        INSERT INTO stock_transfers
            (trn_no, from_warehouse_id, to_warehouse_id, from_bin_id, to_bin_id, project_id, remarks, status, created_by, created_at)
        VALUES
            (:trn_no, :from_wh, :to_wh, :from_bin, :to_bin, :project_id, :remarks, 'POSTED', :created_by, :created_at)
    ")->execute([
        ':trn_no'     => $trnNo,
        ':from_wh'    => $fromWhId,
        ':to_wh'      => $toWhId,
        ':from_bin'   => $fromBinId,
        ':to_bin'     => $toBinId,
        ':project_id' => $projectId,
        ':remarks'    => $remarks,
        ':created_by' => $userId,
        ':created_at' => $now,
    ]);
    $trnId = (int)$pdo->lastInsertId();

    // Optional line mirror
    $insLine = $pdo->prepare("
        INSERT INTO stock_transfer_items
            (transfer_id, line_no, item_id, uom_id, qty, from_bin_id, to_bin_id, batch_id, remarks)
        VALUES
            (:transfer_id, :line_no, :item_id, :uom_id, :qty, :from_bin_id, :to_bin_id, :batch_id, :remarks)
    ");

    // Source WA rate (for ledger valuation)
    $waQ = $pdo->prepare("SELECT avg_cost FROM stock_avg WHERE item_id = :i AND warehouse_id = :w");

    $lineNo = 0;
    foreach ($lines as $ln) {
        $lineNo++;
        $itemId  = (int)($ln['item_id'] ?? 0);
        $uomId   = isset($ln['uom_id']) ? (int)$ln['uom_id'] : null;
        $qty     = (float)($ln['qty'] ?? 0);
        $batchId = isset($ln['batch_id']) ? (int)$ln['batch_id'] : null;
        $lnRem   = trim($ln['remarks'] ?? '');

        if ($itemId <= 0 || $qty <= 0) {
            throw new RuntimeException("Invalid line {$lineNo}: item and qty required.");
        }

        $insLine->execute([
            ':transfer_id' => $trnId,
            ':line_no'     => $lineNo,
            ':item_id'     => $itemId,
            ':uom_id'      => $uomId,
            ':qty'         => $qty,
            ':from_bin_id' => $fromBinId,
            ':to_bin_id'   => $toBinId,
            ':batch_id'    => $batchId,
            ':remarks'     => $lnRem,
        ]);

        $waQ->execute([':i' => $itemId, ':w' => $fromWhId]);
        $waRow = $waQ->fetch(PDO::FETCH_ASSOC);
        $rate  = ($waRow && (float)$waRow['avg_cost'] > 0) ? (float)$waRow['avg_cost'] : 0.0;

        // OUT from source
        $outPayload = [
            'txn_type'     => 'TRF',
            'txn_no'       => $trnNo,
            'txn_date'     => $now,
            'item_id'      => $itemId,
            'uom_id'       => $uomId,
            'warehouse_id' => $fromWhId,
            'bin_id'       => $fromBinId,
            'batch_id'     => $batchId,
            'project_id'   => $projectId,
            'qty'          => $qty,
            'unit_cost'    => $rate,
            'ref_entity'   => 'stock_transfers',
            'ref_table'    => 'stock_transfers',
            'ref_id'       => $trnId,
            'created_by'   => $userId,
        ];
        StockMoveWriter::postOut($pdo, $outPayload);
        ValuationService::onIssue($pdo, $itemId, $fromWhId, $qty);
        StockLedgerAdapter::mirror($pdo, $outPayload);

        // IN to destination
        $inPayload = [
            'txn_type'     => 'TRF',
            'txn_no'       => $trnNo,
            'txn_date'     => $now,
            'item_id'      => $itemId,
            'uom_id'       => $uomId,
            'warehouse_id' => $toWhId,
            'bin_id'       => $toBinId,
            'batch_id'     => $batchId,
            'project_id'   => $projectId,
            'qty'          => $qty,
            'unit_cost'    => $rate,
            'ref_entity'   => 'stock_transfers',
            'ref_table'    => 'stock_transfers',
            'ref_id'       => $trnId,
            'created_by'   => $userId,
        ];
        StockMoveWriter::postIn($pdo, $inPayload);
        // Your ValuationService implements onPositiveReceipt (not onReceipt)
        ValuationService::onPositiveReceipt($pdo, $itemId, $toWhId, $qty, $rate);
        StockLedgerAdapter::mirror($pdo, $inPayload);
    }

    // ✅ FIX: pass $pdo as the 1st argument to audit_log
    try {
        audit_log($pdo, 'stock_transfers', $trnId, 'POST', null, ['trn_no' => $trnNo, 'lines' => count($lines)]);
    } catch (Throwable $ae) {
        // Don't fail the whole transaction if auditing fails
        error_log('audit_log failed: ' . $ae->getMessage());
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'transfer_id' => $trnId, 'trn_no' => $trnNo]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
