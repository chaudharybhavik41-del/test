<?php
// api/release_stock.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');
try {
    require_permission('stores.reservation.manage');

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!$input) $input = $_POST;

    $resId = isset($input['id']) ? (int)$input['id'] : 0;
    $refEntity = trim($input['ref_entity'] ?? '');
    $refId = isset($input['ref_id']) ? (int)$input['ref_id'] : 0;

    $pdo = db();
    if ($resId > 0) {
        $st = $pdo->prepare("DELETE FROM item_reservations WHERE id=:id");
        $st->execute([':id'=>$resId]);
    } elseif ($refEntity !== '' && $refId > 0) {
        $st = $pdo->prepare("DELETE FROM item_reservations WHERE ref_entity=:re AND ref_id=:rid");
        $st->execute([':re'=>$refEntity, ':rid'=>$refId]);
    } else {
        throw new RuntimeException('Provide id OR (ref_entity, ref_id)');
    }

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
