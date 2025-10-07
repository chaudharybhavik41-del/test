
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/RoutingService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('routing.manage'); $pdo=db(); $in=json_decode(file_get_contents('php://input'),true)?:$_POST; $svc=new \Coupler\RoutingService($pdo);
  $id=$svc->create((int)$in['parent_item_id'], (string)$in['routing_code'], isset($in['bom_version_id'])? (int)$in['bom_version_id']:null, (bool)($in['is_primary']??true), $in['effective_from']??null, $in['effective_to']??null, $in['notes']??null);
  echo json_encode(['ok'=>true,'data'=>['routing_id'=>$id]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
