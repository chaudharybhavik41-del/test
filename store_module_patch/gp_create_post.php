<?php
/**
 * Gate Pass CREATE
 * - Creates GP header + lines
 * - For non-returnable lines that are dispatched now: posts OUT
 * - NEW: Valuation on OUT + mirror to stock_ledger
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
    require_permission('stores.gatepass.manage');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) $input = $_POST;

    if (!empty($_POST)) {
        csrf_require_token($_POST['csrf_token'] ?? '');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    $sourceWhId   = (int)($input['warehouse_id'] ?? 0);
    $destWhId     = isset($input['to_warehouse_id']) ? (int)$input['to_warehouse_id'] : null; // optional
    $partyId      = isset($input['party_id']) ? (int)$input['party_id'] : null;               // optional (jobwork/site)
    $projectId    = isset($input['project_id']) ? (int)$input['project_id'] : null;
    $returnable   = (int)($input['returnable'] ?? 0); // 1/0
    $expectedRet  = !empty($input['expected_return_date']) ? $input['expected_return_date'] : null;
    $vehicleNo    = trim($input['vehicle_no'] ?? '');
    $contactName  = trim($input['contact_name'] ?? '');
    $remarks      = trim($input['remarks'] ?? '');
    $lines        = $input['lines'] ?? [];

    if ($sourceWhId <= 0 || empty($lines)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'warehouse_id and lines are required']);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();

    $gpNo = NumberingService::next($pdo, 'GP');

    // Header
    $stmt = $pdo->prepare("INSERT INTO gatepasses
        (gp_no, warehouse_id, to_warehouse_id, party_id, project_id, returnable, expected_return_date,
         vehicle_no, contact_name, remarks, status, created_by, created_at)
        VALUES
        (:gp_no, :warehouse_id, :to_warehouse_id, :party_id, :project_id, :returnable, :expected_return_date,
         :vehicle_no, :contact_name, :remarks, :status, :created_by, :created_at)");
    $stmt->execute([
        ':gp_no'                => $gpNo,
        ':warehouse_id'         => $sourceWhId,
        ':to_warehouse_id'      => $destWhId,
        ':party_id'             => $partyId,
        ':project_id'           => $projectId,
        ':returnable'           => $returnable,
        ':expected_return_date' => $expectedRet,
        ':vehicle_no'           => $vehicleNo,
        ':contact_name'         => $contactName,
        ':remarks'              => $remarks,
        ':status'               => ($returnable ? 'OPEN' : 'CLOSED'),
        ':created_by'           => $userId,
        ':created_at'           => $now,
    ]);
    $gpId = (int)$pdo->lastInsertId();

    $insLine = $pdo->prepare("INSERT INTO gatepass_items
        (gp_id, line_no, item_id, uom_id, qty, bin_id, batch_id, remarks, returned_qty)
        VALUES (:gp_id, :line_no, :item_id, :uom_id, :qty, :bin_id, :batch_id, :remarks, 0)");

    $lineNo = 0;
    foreach ($lines as $ln) {
        $lineNo++;
        $itemId    = (int)($ln['item_id'] ?? 0);
        $uomId     = isset($ln['uom_id']) ? (int)$ln['uom_id'] : null;
        $qty       = (float)($ln['qty'] ?? 0);
        $binId     = isset($ln['bin_id']) ? (int)$ln['bin_id'] : null;
        $batchId   = isset($ln['batch_id']) ? (int)$ln['batch_id'] : null;
        $lnRemarks = trim($ln['remarks'] ?? '');
        $unitCost  = (float)($ln['unit_cost'] ?? 0);

        if ($itemId <= 0 || $qty <= 0) {
            throw new RuntimeException("Invalid line #{$lineNo}");
        }

        $insLine->execute([
            ':gp_id'   => $gpId,
            ':line_no' => $lineNo,
            ':item_id' => $itemId,
            ':uom_id'  => $uomId,
            ':qty'     => $qty,
            ':bin_id'  => $binId,
            ':batch_id'=> $batchId,
            ':remarks' => $lnRemarks,
        ]);

        // If NON-RETURNABLE, we post OUT right now.
        if (!$returnable) {
            $payload = [
                'txn_type'     => 'GP',
                'txn_no'       => $gpNo,
                'txn_date'     => $now,
                'item_id'      => $itemId,
                'uom_id'       => $uomId,
                'warehouse_id' => $sourceWhId,
                'bin_id'       => $binId,
                'batch_id'     => $batchId,
                'project_id'   => $projectId,
                'qty'          => $qty,
                'unit_cost'    => $unitCost,
                'ref_table'    => 'gatepasses',
                'ref_id'       => $gpId,
                'created_by'   => $userId,
            ];

            StockMoveWriter::postOut($pdo, $payload);
            ValuationService::onIssue($pdo, $itemId, $sourceWhId, $qty);
            StockLedgerAdapter::mirror($pdo, $payload);
        }
    }

    audit_log('gatepasses', $gpId, 'CREATE', null, ['gp_no' => $gpNo, 'returnable' => $returnable, 'lines' => count($lines)]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'gp_id' => $gpId, 'gp_no' => $gpNo]);

} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}