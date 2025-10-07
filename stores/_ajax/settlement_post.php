<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/includes/auth.php';
require_once dirname(__DIR__,2) . '/includes/db.php';
require_once dirname(__DIR__,2) . '/includes/rbac.php';
require_once dirname(__DIR__,2) . '/includes/coupler/SettlementService.php';
header('Content-Type: application/json');

try {
  require_login(); require_permission('stores.settlement.post');
  $pdo = db();
  $in  = json_decode(file_get_contents('php://input'), true) ?: $_POST;

  $svc = new \Coupler\SettlementService($pdo);
  $res = $svc->post((int)($in['settlement_id'] ?? 0));

  echo json_encode(['ok'=>true,'data'=>$res]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}