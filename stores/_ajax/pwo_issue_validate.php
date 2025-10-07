
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/IssuePwoBridge.php';
header('Content-Type: application/json');
try{
  require_login(); require_permission('pwo.issue.validate');
  $pdo=db();
  $in=json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $bridge=new \Coupler\IssuePwoBridge($pdo);
  $allow = (bool)($in['allow_client_owner'] ?? false);
  $res=$bridge->validate((int)$in['pwo_id'], (array)$in['lines'], $allow);
  echo json_encode(['ok'=>true,'data'=>$res]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
