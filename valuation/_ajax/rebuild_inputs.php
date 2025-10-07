
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/ValuationService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('valuation.rebuild'); $pdo=db();
  $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
  $svc=new \Coupler\ValuationService($pdo);
  $n=$svc->rebuildInputs((string)($in['from']??date('Y-m-01')), (string)($in['to']??date('Y-m-d')));
  echo json_encode(['ok'=>true,'data'=>['inputs_built'=>$n]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
