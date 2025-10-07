<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/includes/auth.php';
require_once dirname(__DIR__,2) . '/includes/db.php';
require_once dirname(__DIR__,2) . '/includes/rbac.php';
require_once dirname(__DIR__,2) . '/includes/coupler/LotService.php';
header('Content-Type: application/json');
try {
  require_login(); require_permission('stores.remnant.split');
  $pdo = db();
  $input = json_decode(file_get_contents('php://input'), true) ?: [];
  $pieceId = (int)($input['piece_id'] ?? 0);
  $consumedKg = (float)($input['consumed_kg'] ?? 0);
  $remnants = $input['remnants'] ?? [];
  $svc = new \Coupler\LotService($pdo);
  $out = $svc->splitPiece($pieceId, $remnants, $consumedKg);
  echo json_encode(['ok'=>true,'data'=>$out]);
} catch (Throwable $e) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
