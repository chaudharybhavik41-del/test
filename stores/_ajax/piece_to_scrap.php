<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/includes/auth.php';
require_once dirname(__DIR__,2) . '/includes/db.php';
require_once dirname(__DIR__,2) . '/includes/rbac.php';
require_once dirname(__DIR__,2) . '/includes/coupler/LotService.php';
header('Content-Type: application/json');
try {
  require_login(); require_permission('stores.remnant.scrap');
  $pdo = db();
  $input = json_decode(file_get_contents('php://input'), true) ?: [];
  $pieceId = (int)($input['piece_id'] ?? 0);
  $qtyKg = (float)($input['qty_kg'] ?? 0);
  $svc = new \Coupler\LotService($pdo);
  $res = $svc->markScrap($pieceId, $qtyKg);
  // TODO: call StockMoveWriter for scrap posting/GL if you hold scrap inventory
  echo json_encode(['ok'=>true,'data'=>$res]);
} catch (Throwable $e) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
