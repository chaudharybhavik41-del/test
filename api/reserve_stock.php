<?php
// api/reserve_stock.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/Availability.php';

header('Content-Type: application/json');
try {
    require_permission('stores.reservation.manage');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) $input = $_POST;

    $itemId = (int)($input['item_id'] ?? 0);
    $warehouseId = (int)($input['warehouse_id'] ?? 0);
    $qty = (float)($input['qty'] ?? 0);
    $refEntity = trim($input['ref_entity'] ?? '');
    $refId = isset($input['ref_id']) ? (int)$input['ref_id'] : null;

    if ($itemId<=0 || $warehouseId<=0 || $qty<=0) throw new RuntimeException('item_id, warehouse_id, qty required');
    if ($refEntity==='') throw new RuntimeException('ref_entity required');

    $pdo = db();
    $pdo->beginTransaction();

    $available = Availability::available($pdo, $itemId, $warehouseId);
    if ($available + 1e-9 < $qty) throw new RuntimeException('INSUFFICIENT_AVAILABLE: not enough stock to reserve');

    $st = $pdo->prepare("INSERT INTO item_reservations (item_id, warehouse_id, qty, ref_entity, ref_id, created_at)
                         VALUES (:i,:w,:q,:re,:rid, NOW(6))");
    $st->execute([':i'=>$itemId, ':w'=>$warehouseId, ':q'=>$qty, ':re'=>$refEntity, ':rid'=>$refId]);

    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    echo json_encode(['ok'=>true, 'reservation_id'=>$id]);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
