
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/RoutingService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('routing.manage'); $pdo=db(); $in=json_decode(file_get_contents('php://input'),true)?:$_POST; $svc=new \Coupler\RoutingService($pdo);
  $id=$svc->addOp((int)$in['routing_id'], (int)$in['op_seq'], (string)$in['op_code'], (int)$in['wc_id'], (float)($in['std_setup_min']??0), (float)($in['std_run_min_per_unit']??0), (float)($in['overlap_pct']??0), $in['notes']??null);
  echo json_encode(['ok'=>true,'data'=>['operation_id'=>$id]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
