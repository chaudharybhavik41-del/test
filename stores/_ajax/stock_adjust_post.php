<?php
/**
 * PATH: /public_html/stores/_ajax/stock_adjust_post.php
 * Stock Adjustment POST — schema-aligned + form-matrix normalization
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_once __DIR__ . '/../../includes/NumberingService.php';
require_once __DIR__ . '/../../includes/StockMoveWriter.php';
require_once __DIR__ . '/../../includes/services/ValuationService.php';
require_once __DIR__ . '/../../includes/StockLedgerAdapter.php';

header('Content-Type: application/json');

function try_int($v) { return isset($v) && $v !== '' ? (int)$v : null; }

try {
    require_permission('stocks.adjust.manage');

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) $input = $_POST;

    // CSRF only for standard form posts
    if (!empty($_POST)) {
        csrf_require_token($_POST['csrf_token'] ?? '');
    }

    $pdo = db();

    $userId      = (int)$_SESSION['user_id'];
    $nowDate     = date('Y-m-d');      // stock_moves.txn_date is DATE
    $nowStamp    = date('Y-m-d H:i:s');

    // Required
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $mode        = strtoupper(trim($input['mode'] ?? '')); // IN or OUT
    $lines       = $input['lines'] ?? [];

    // Normalize matrix-style form arrays
    if (isset($lines['item_id']) && is_array($lines['item_id'])) {
        $rows = [];
        $n = count($lines['item_id']);
        for ($i = 0; $i < $n; $i++) {
            $row = [
                'item_id'   => $lines['item_id'][$i]   ?? null,
                'uom_id'    => $lines['uom_id'][$i]    ?? null,
                'qty'       => $lines['qty'][$i]       ?? null,
                'remarks'   => $lines['remarks'][$i]   ?? null,
                'bin_id'    => $lines['bin_id'][$i]    ?? null,
                'batch_id'  => $lines['batch_id'][$i]  ?? null,
                'unit_cost' => $lines['unit_cost'][$i] ?? null,
            ];
            if (($row['item_id'] !== null && $row['item_id'] !== '') ||
                ($row['qty'] !== null && $row['qty'] !== '')) {
                $rows[] = $row;
            }
        }
        $lines = $rows;
    }

    // Optional
    $remarks     = trim($input['remarks'] ?? '');
    $reasonId    = try_int($input['reason_id'] ?? null);
    $reasonCode  = isset($input['reason_code']) ? trim((string)$input['reason_code']) : '';

    if ($warehouseId <= 0 || !in_array($mode, ['IN','OUT'], true) || empty($lines)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'warehouse_id, mode (IN/OUT) and lines are required']);
        return;
    }

    if (!$reasonId && $reasonCode !== '') {
        $q = $pdo->prepare("SELECT id FROM stock_adj_reasons WHERE code = ?");
        $q->execute([$reasonCode]);
        $rid = $q->fetchColumn();
        if ($rid) $reasonId = (int)$rid;
    }

    $pdo->beginTransaction();

    $adjNo = NumberingService::next($pdo, 'ADJ'); // e.g., ADJ-2025-0001

    $pdo->prepare("
        INSERT INTO stock_adjustments
            (adj_no, adj_date, mode, warehouse_id, reason_id, remarks, status, created_by, created_at)
        VALUES
            (:adj_no, :adj_date, :mode, :warehouse_id, :reason_id, :remarks, 'posted', :created_by, :created_at)
    ")->execute([
        ':adj_no'       => $adjNo,
        ':adj_date'     => $nowDate,
        ':mode'         => $mode,
        ':warehouse_id' => $warehouseId,
        ':reason_id'    => $reasonId,
        ':remarks'      => $remarks,
        ':created_by'   => $userId,
        ':created_at'   => $nowStamp,
    ]);
    $adjId = (int)$pdo->lastInsertId();

    $insLine = $pdo->prepare("
        INSERT INTO stock_adjustment_items
            (adj_id, item_id, uom_id, qty, remarks)
        VALUES
            (:adj_id, :item_id, :uom_id, :qty, :remarks)
    ");

    $avgQ = $pdo->prepare("SELECT avg_cost FROM stock_avg WHERE item_id = :i AND warehouse_id = :w");

    $lineNo = 0;
    foreach ($lines as $ln) {
        $lineNo++;
        $itemId = (int)($ln['item_id'] ?? 0);
        $uomId  = (int)($ln['uom_id'] ?? 0);
        $qty    = (float)($ln['qty'] ?? 0);
        $lrem   = trim((string)($ln['remarks'] ?? ''));

        if ($itemId <= 0 || $uomId <= 0 || $qty <= 0) {
            throw new RuntimeException("Invalid line #{$lineNo}: item, uom, qty required.");
        }

        $insLine->execute([
            ':adj_id' => $adjId,
            ':item_id'=> $itemId,
            ':uom_id' => $uomId,
            ':qty'    => $qty,
            ':remarks'=> $lrem,
        ]);

        $payload = [
            'txn_type'     => 'ADJ',
            'txn_no'       => $adjNo,
            'txn_date'     => $nowDate,      // DATE
            'item_id'      => $itemId,
            'uom_id'       => $uomId,
            'warehouse_id' => $warehouseId,
            'bin_id'       => null,
            'batch_id'     => null,
            'project_id'   => null,
            'qty'          => $qty,
            'unit_cost'    => 0.0,           // no unit cost in your adjust items
            'ref_entity'   => 'stock_adjustments',
            'ref_table'    => 'stock_adjustments',
            'ref_id'       => $adjId,
            'created_by'   => $userId,
        ];

        if ($mode === 'IN') {
            \StockMoveWriter::postIn($pdo, $payload);
            if (method_exists('ValuationService','onPositiveReceipt')) {
                \ValuationService::onPositiveReceipt($pdo, $itemId, $warehouseId, $qty, 0.0);
            } elseif (method_exists('ValuationService','onReceipt')) {
                \ValuationService::onReceipt($pdo, $itemId, $warehouseId, $qty, 0.0);
            }
            \StockLedgerAdapter::mirror($pdo, $payload);
        } else {
            $avgQ->execute([':i'=>$itemId, ':w'=>$warehouseId]);
            $row = $avgQ->fetch(\PDO::FETCH_ASSOC);
            if ($row && isset($row['avg_cost']) && (float)$row['avg_cost'] > 0) {
                $payload['unit_cost'] = (float)$row['avg_cost'];
            }
            \StockMoveWriter::postOut($pdo, $payload);
            \ValuationService::onIssue($pdo, $itemId, $warehouseId, $qty);
            \StockLedgerAdapter::mirror($pdo, $payload);
        }
    }

    try {
        audit_log($pdo, 'stock_adjustments', $adjId, 'POST', null, [
            'adj_no' => $adjNo,
            'mode'   => $mode,
            'lines'  => count($lines),
        ]);
    } catch (\Throwable $ae) {
        error_log('audit_log failed: '.$ae->getMessage().' @'.$ae->getFile().':'.$ae->getLine());
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'adjustment_id' => $adjId, 'adj_no' => $adjNo]);

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
    $msg = $e->getMessage().' @'.$e->getFile().':'.$e->getLine();
    error_log($msg);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
