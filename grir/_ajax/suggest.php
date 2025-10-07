
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/GrirCloser.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('grir.close.view'); $pdo=db();
  $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
  $svc=new \Coupler\GrirCloser($pdo);
  $rows=$svc->suggest((string)($in['older_than']??date('Y-m-d',strtotime('-30 days'))));
  echo json_encode(['ok'=>true,'data'=>$rows]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
