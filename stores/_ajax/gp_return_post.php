<?php
/**
 * Gate Pass RETURN
 * - Validates returnable GP and outstanding qty
 * - Posts IN movements
 * - NEW: Calls ValuationService::onReceipt and mirrors to stock_ledger
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
    require_permission('stores.gatepass.manage');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) $input = $_POST;

    if (!empty($_POST)) {
        csrf_require_token($_POST['csrf_token'] ?? '');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $now = date('Y-m-d H:i:s');

    $gpId   = (int)($input['gp_id'] ?? 0);
    $lines  = $input['lines'] ?? []; // each: gp_line_id, qty, unit_cost?, bin_id?, batch_id?
    if ($gpId <= 0 || empty($lines)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'gp_id and lines are required']);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();

    // Load GP header, ensure returnable
    $h = $pdo->prepare("SELECT gp_no, warehouse_id, returnable, status FROM gatepasses WHERE id = :id FOR UPDATE");
    $h->execute([':id' => $gpId]);
    $hdr = $h->fetch(PDO::FETCH_ASSOC);
    if (!$hdr) throw new RuntimeException('Gate Pass not found');
    if ((int)$hdr['returnable'] !== 1) throw new RuntimeException('Gate Pass is not returnable');

    $gpNo       = $hdr['gp_no'];
    $warehouseId= (int)$hdr['warehouse_id'];

    // Load lines outstanding
    $lnQ = $pdo->prepare("SELECT id, line_no, item_id, uom_id, qty, returned_qty, bin_id, batch_id FROM gatepass_items WHERE gp_id = :id");
    $lnQ->execute([':id' => $gpId]);
    $existing = [];
    while ($r = $lnQ->fetch(PDO::FETCH_ASSOC)) {
        $existing[(int)$r['id']] = $r;
    }

    // Generate return number
    $gprNo = NumberingService::next($pdo, 'GPR');

    foreach ($lines as $ln) {
        $gpLineId = (int)($ln['gp_line_id'] ?? 0);
        $retQty   = (float)($ln['qty'] ?? 0);
        $unitCost = (float)($ln['unit_cost'] ?? 0);
        $binId    = isset($ln['bin_id']) ? (int)$ln['bin_id'] : ($existing[$gpLineId]['bin_id'] ?? null);
        $batchId  = isset($ln['batch_id']) ? (int)$ln['batch_id'] : ($existing[$gpLineId]['batch_id'] ?? null);

        if ($gpLineId <= 0 || $retQty <= 0) {
            throw new RuntimeException("Invalid return line");
        }
        if (!isset($existing[$gpLineId])) {
            throw new RuntimeException("Gate Pass line not found: {$gpLineId}");
        }
        $ex = $existing[$gpLineId];
        $outstanding = (float)$ex['qty'] - (float)$ex['returned_qty'];
        if ($retQty > $outstanding + 1e-9) {
            throw new RuntimeException("Return qty exceeds outstanding on line {$ex['line_no']}");
        }

        // Update returned qty
        $pdo->prepare("UPDATE gatepass_items SET returned_qty = returned_qty + :r WHERE id = :id")
            ->execute([':r' => $retQty, ':id' => $gpLineId]);

        // Build payload
        $payload = [
            'txn_type'     => 'GPR',
            'txn_no'       => $gprNo,       // return doc number
            'txn_date'     => $now,
            'item_id'      => (int)$ex['item_id'],
            'uom_id'       => (int)$ex['uom_id'],
            'warehouse_id' => $warehouseId,
            'bin_id'       => $binId,
            'batch_id'     => $batchId,
            'project_id'   => null,
            'qty'          => $retQty,      // positive IN
            'unit_cost'    => $unitCost,    // pre-tax
            'ref_table'    => 'gatepasses',
            'ref_id'       => $gpId,
            'created_by'   => $userId,
        ];

        StockMoveWriter::postIn($pdo, $payload);
        ValuationService::onReceipt($pdo, (int)$ex['item_id'], $warehouseId, $retQty, $unitCost);
        StockLedgerAdapter::mirror($pdo, $payload);
    }

    // Close GP if fully returned
    $c = $pdo->prepare("SELECT SUM(qty) as t_qty, SUM(returned_qty) as t_ret FROM gatepass_items WHERE gp_id = :id");
    $c->execute([':id' => $gpId]);
    $row = $c->fetch(PDO::FETCH_ASSOC);
    if ($row && (float)$row['t_qty'] <= (float)$row['t_ret'] + 1e-9) {
        $pdo->prepare("UPDATE gatepasses SET status = 'CLOSED', updated_at = NOW() WHERE id = :id")
            ->execute([':id' => $gpId]);
    }

    audit_log('gatepasses', $gpId, 'RETURN', null, ['gpr_no' => $gprNo, 'lines' => count($lines)]);
    $pdo->commit();

    echo json_encode(['ok' => true, 'gp_id' => $gpId, 'gpr_no' => $gprNo]);

} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log($e);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}