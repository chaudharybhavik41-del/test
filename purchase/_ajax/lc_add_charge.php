
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/includes/auth.php';
require_once dirname(__DIR__,2) . '/includes/db.php';
require_once dirname(__DIR__,2) . '/includes/rbac.php';
require_once dirname(__DIR__,2) . '/includes/coupler/LandedCostService.php';
header('Content-Type: application/json');
try{
  require_login(); require_permission('purchase.lc.edit');
  $pdo = db();
  $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $svc = new \Coupler\LandedCostService($pdo);
  $id = $svc->addCharge((int)($in['lc_id'] ?? 0), (string)($in['charge_code'] ?? 'freight'), (float)($in['amount'] ?? 0), $in['vendor_id'] ?? null, (string)($in['currency'] ?? 'INR'), (float)($in['fx_rate'] ?? 1.0), $in['description'] ?? null);
  echo json_encode(['ok'=>true,'data'=>['lc_charge_id'=>$id]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
