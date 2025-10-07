<?php
/** PATH: /public_html/stores/_ajax/purchase_advice_approve.php */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/audit.php';
require_permission('purchase.advice.manage');
header('Content-Type: application/json; charset=utf-8');

try{
  $pdo = db();
  $raw = file_get_contents('php://input') ?: '';
  $in = $raw ? json_decode($raw,true) : null;
  $id = (int)($in['id'] ?? 0);
  if ($id<=0) throw new RuntimeException('id required');

  $st = $pdo->prepare("UPDATE purchase_advice SET status='approved' WHERE id=? AND status='draft'");
  $st->execute([$id]);
  if ($st->rowCount()===0) throw new RuntimeException('Already approved or not found');

  audit_log($pdo, 'purchase_advice', 'approve', $id, null);
  echo json_encode(['ok'=>true]);
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
