
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/BomService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('bom.edit'); $pdo=db();
  $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
  $svc=new \Coupler\BomService($pdo);
  $id=$svc->createVersion((int)($in['parent_item_id']??0),(string)($in['version_code']??'v1'),$in['effective_from']??null,$in['effective_to']??null,$in['notes']??null);
  echo json_encode(['ok'=>true,'data'=>['version_id'=>$id]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
