
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/RoutingService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('routing.manage'); $pdo=db(); $id=(int)($_GET['routing_id']??0); $svc=new \Coupler\RoutingService($pdo);
  $full=$svc->getFull($id); echo json_encode(['ok'=>true,'data'=>$full]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
