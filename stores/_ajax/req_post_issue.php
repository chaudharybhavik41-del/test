<?php
/**
 * Material Requisition → Issue POST
 * - Creates material_issues header + lines
 * - Posts OUT movements via StockMoveWriter
 * - NEW: Calls ValuationService::onIssue and mirrors to stock_ledger
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/NumberingService.php';
require_once __DIR__ . '/../../includes/StockMoveWriter.php';
require_once __DIR__ . '/../../includes/ValuationService.php';
require_once __DIR__ . '/../../includes/StockLedgerAdapter.php';

header('Content-Type: application/json');

try {
    require_permission('stores.issue.manage');

    // Accept either JSON or form
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) $input = $_POST;

    // Optional CSRF (only if you post from a form)
    if (!empty($_POST)) {
        csrf_require_token($_POST['csrf_token'] ?? '');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    $reqId        = (int)($input['req_id'] ?? 0);
    $warehouseId  = (int)($input['warehouse_id'] ?? 0);
    $projectId    = isset($input['project_id']) ? (int)$input['project_id'] : null;
    $remarks      = trim($input['remarks'] ?? '');
    $lines        = $input['lines'] ?? [];

    if ($warehouseId <= 0 || empty($lines)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'warehouse_id and lines are required']);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();

    // Generate Issue number
    $issueNo = NumberingService::next($pdo, 'ISS'); // e.g., ISS-2025-000123

    // Create header
    $stmt = $pdo->prepare("INSERT INTO material_issues
        (issue_no, req_id, warehouse_id, project_id, remarks, status, created_by, created_at)
        VALUES (:issue_no, :req_id, :warehouse_id, :project_id, :remarks, 'POSTED', :created_by, :created_at)");
    $stmt->execute([
        ':issue_no'     => $issueNo,
        ':req_id'       => $reqId ?: null,
        ':warehouse_id' => $warehouseId,
        ':project_id'   => $projectId,
        ':remarks'      => $remarks,
        ':created_by'   => $userId,
        ':created_at'   => $now,
    ]);
    $issueId = (int)$pdo->lastInsertId();

    // Prepare line insert
    $insLine = $pdo->prepare("INSERT INTO material_issue_items
        (issue_id, line_no, item_id, uom_id, qty, bin_id, batch_id, remarks)
        VALUES (:issue_id, :line_no, :item_id, :uom_id, :qty, :bin_id, :batch_id, :remarks)");

    $lineNo = 0;
    foreach ($lines as $ln) {
        $lineNo++;
        $itemId     = (int)($ln['item_id'] ?? 0);
        $uomId      = isset($ln['uom_id']) ? (int)$ln['uom_id'] : null;
        $qty        = (float)($ln['qty'] ?? 0);
        $binId      = isset($ln['bin_id']) ? (int)$ln['bin_id'] : null;
        $batchId    = isset($ln['batch_id']) ? (int)$ln['batch_id'] : null;
        $lnRemarks  = trim($ln['remarks'] ?? '');

        if ($itemId <= 0 || $qty <= 0) {
            throw new RuntimeException("Invalid line at #{$lineNo}");
        }

        // Insert issue line
        $insLine->execute([
            ':issue_id' => $issueId,
            ':line_no'  => $lineNo,
            ':item_id'  => $itemId,
            ':uom_id'   => $uomId,
            ':qty'      => $qty,
            ':bin_id'   => $binId,
            ':batch_id' => $batchId,
            ':remarks'  => $lnRemarks,
        ]);

        // Build writer payload (mirror your existing shape)
        $payload = [
            'txn_type'     => 'ISS',
            'txn_no'       => $issueNo,
            'txn_date'     => $now,
            'item_id'      => $itemId,
            'uom_id'       => $uomId,
            'warehouse_id' => $warehouseId,
            'bin_id'       => $binId,
            'batch_id'     => $batchId,
            'project_id'   => $projectId,
            'qty'          => $qty,            // positive here; writer will store negative in stock_moves
            'unit_cost'    => (float)($ln['unit_cost'] ?? 0), // optional; not used for WA on issue
            'ref_table'    => 'material_issues',
            'ref_id'       => $issueId,
            'created_by'   => $userId,
        ];

        // Post OUT to stock
        StockMoveWriter::postOut($pdo, $payload);

        // NEW: Valuation basis reduce + ledger mirror
        ValuationService::onIssue($pdo, $itemId, $warehouseId, $qty);
        StockLedgerAdapter::mirror($pdo, $payload);
    }

    // Optional: close requisition if fully issued (business rule as before)
    if ($reqId > 0) {
        $pdo->prepare("UPDATE material_requisitions SET status = 'CLOSED', updated_at = NOW()
                       WHERE id = :id AND status <> 'CLOSED'")
            ->execute([':id' => $reqId]);
    }

    audit_log('material_issues', $issueId, 'POST', null, ['issue_no' => $issueNo, 'lines' => count($lines)]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'issue_id' => $issueId, 'issue_no' => $issueNo]);

} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}