<?php
// /api/process_qty_preview.php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/process_qty.php';

header('Content-Type: application/json');
$opId = (int)($_GET['routing_op_id'] ?? 0);
if (!$opId) { http_response_code(400); echo json_encode(['error'=>'routing_op_id required']); exit; }

try {
  $res = pq_compute_for_op($pdo, $opId);
  if (!$res) { echo json_encode(['hasRule'=>false]); exit; }
  [$qty, $uom_id, $inputs] = $res;
  $u = $pdo->prepare("SELECT code FROM uom WHERE id=?"); $u->execute([$uom_id]);
  $uom_code = $u->fetchColumn() ?: '';
  echo json_encode(['hasRule'=>true, 'qty'=>$qty, 'uom'=>$uom_code, 'inputs'=>$inputs]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'calc-failed', 'msg'=>$e->getMessage()]);
}
