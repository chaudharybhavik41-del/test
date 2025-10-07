<?php
/**
 * EMS Infra ERP — Store Module Patch Packager
 * Writes replacement endpoints, the StockLedger adapter include, and a migration SQL,
 * then creates store_module_patch.zip for easy download.
 *
 * Usage:
 * 1) Upload this file to your server (e.g., /var/www/html or your PHP tools dir)
 * 2) Open it in a browser OR run: php store_patch_packager.php
 * 3) It will create ./store_module_patch/ and ./store_module_patch.zip in the same folder
 * 4) Download the zip, then delete this script for safety
 */

date_default_timezone_set('Asia/Kolkata');

$baseDir = __DIR__ . '/store_module_patch';
$includesDir = $baseDir . '/includes';
$migrationsDir = $baseDir . '/migrations';

@mkdir($baseDir, 0775, true);
@mkdir($includesDir, 0775, true);
@mkdir($migrationsDir, 0775, true);

$TS = date('Y-m-d H:i:s');

function writeFileStrict($path, $content) {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $ok = file_put_contents($path, $content);
    if ($ok === false) {
        throw new RuntimeException("Failed writing file: $path");
    }
}

/* ------------------ File contents ------------------ */

$REQ_POST_ISSUE = <<<'PHP'
<?php
/**
 * Material Requisition → Issue POST
 * - Creates material_issues header + lines
 * - Posts OUT movements via StockMoveWriter
 * - NEW: Calls ValuationService::onIssue and mirrors to stock_ledger
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
PHP;

$STOCK_ADJUST_POST = <<<'PHP'
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
PHP;

$GP_CREATE_POST = <<<'PHP'
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
PHP;

$GP_RETURN_POST = <<<'PHP'
<?php
/**
 * Gate Pass RETURN
 * - Validates returnable GP and outstanding qty
 * - Posts IN movements
 * - NEW: Calls ValuationService::onReceipt and mirrors to stock_ledger
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
PHP;

$STOCK_LEDGER_ADAPTER = <<<'PHP'
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
        $refTable    = $payload['ref_table'] ?? null;
        $refId       = isset($payload['ref_id']) ? (int)$payload['ref_id'] : null;
        $createdBy   = isset($payload['created_by']) ? (int)$payload['created_by'] : null;

        if ($qty == 0 || $itemId <= 0 || $warehouseId <= 0) {
            return;
        }

        // Determine WA for OUT from stock_avg (optional but recommended)
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
PHP;

$MIGRATION = <<<'SQL'
-- migrations/001_add_stock_ledger.sql
CREATE TABLE IF NOT EXISTS stock_ledger (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  txn_date          DATETIME(6)     NOT NULL,
  txn_type          VARCHAR(16)     NOT NULL,
  txn_no            VARCHAR(50)     NOT NULL,
  item_id           BIGINT          NOT NULL,
  warehouse_id      BIGINT          NOT NULL,
  project_id        BIGINT          NULL,
  bin_id            BIGINT          NULL,
  batch_id          BIGINT          NULL,
  qty               DECIMAL(18,6)   NOT NULL,
  rate              DECIMAL(18,6)   NOT NULL,
  amount            DECIMAL(18,2)   AS (qty * rate) STORED,
  uom_id            BIGINT          NULL,
  ref_table         VARCHAR(64)     NULL,
  ref_id            BIGINT          NULL,
  created_by        BIGINT          NULL,
  created_at        DATETIME(6)     NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_ledger_date (txn_date),
  KEY idx_ledger_item_wh (item_id, warehouse_id),
  KEY idx_ledger_txn (txn_type, txn_no)
);
SQL;

$README = <<<TXT
EMS Infra ERP — Store Module Patch
Generated: {$TS}

Contents
--------
- req_post_issue.php            (full replacement)
- stock_adjust_post.php         (full replacement)
- gp_create_post.php            (full replacement)
- gp_return_post.php            (full replacement)
- includes/StockLedgerAdapter.php (new include)
- migrations/001_add_stock_ledger.sql (new table)

Instructions
------------
1) Run the migration:
   - Execute migrations/001_add_stock_ledger.sql on your MySQL DB.

2) Copy the new include:
   - Place includes/StockLedgerAdapter.php into your project's includes/ folder.

3) Replace endpoints:
   - Backup your existing files.
   - Replace the following with the provided versions:
       req_post_issue.php
       stock_adjust_post.php
       gp_create_post.php
       gp_return_post.php

4) Clear opcode cache if enabled (php-fpm/apcu/opcache).

5) Test:
   - Post a small Issue (OUT): verify stock_ledger receives rows and stock_avg basis reduces.
   - Create a non-returnable GP: verify OUT mirror in stock_ledger.
   - Return on a returnable GP: verify IN mirror and valuation on receipt.
   - Adjustment IN/OUT: verify both valuation and ledger entries.

Notes
-----
- No UI changes. Payload shapes are preserved.
- Rates are pre-tax (basic). Taxes remain in AP.
- The adapter reads current WA for OUT from stock_avg; ensure your ValuationService keeps stock_avg updated.
TXT;

/* ------------- Write files ------------- */
writeFileStrict($baseDir . '/req_post_issue.php', $REQ_POST_ISSUE);
writeFileStrict($baseDir . '/stock_adjust_post.php', $STOCK_ADJUST_POST);
writeFileStrict($baseDir . '/gp_create_post.php', $GP_CREATE_POST);
writeFileStrict($baseDir . '/gp_return_post.php', $GP_RETURN_POST);

writeFileStrict($includesDir . '/StockLedgerAdapter.php', $STOCK_LEDGER_ADAPTER);
writeFileStrict($migrationsDir . '/001_add_stock_ledger.sql', $MIGRATION);
writeFileStrict($baseDir . '/README.txt', $README);

/* ------------- Zip it ------------- */
$zipPath = __DIR__ . '/store_module_patch.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException("Unable to create zip at $zipPath");
}
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
    $filePath = $file->getPathname();
    $local = substr($filePath, strlen($baseDir) + 1);
    $zip->addFile($filePath, $local);
}
$zip->close();

/* ------------- Output ------------- */
$isCli = (php_sapi_name() === 'cli');
$msg = "OK: Created folder 'store_module_patch' and 'store_module_patch.zip' in " . __DIR__ . "\n";
if ($isCli) {
    echo $msg;
} else {
    echo nl2br(htmlentities($msg));
    echo "<br><a href='store_module_patch.zip' download>Download store_module_patch.zip</a>";
}
PHP;

/* ------------------ Write everything and finish ------------------ */
try {
    writeFileStrict($baseDir . '/README.txt', $README); // ensure base exists
    // Re-write all files (already done above in content-section)
    // but here we just ensure the script writes itself ZIP after all files.
    // done in code.

    // Actually, files were already written above.
    // No action here.

} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . htmlspecialchars($e->getMessage());
    exit;
}

echo "Packager file generated successfully at " . __FILE__ . "\n";
