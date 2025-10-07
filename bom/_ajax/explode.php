
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/BomService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('bom.view'); $pdo=db();
  $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
  $svc=new \Coupler\BomService($pdo);
  $flat=$svc->explode((int)($in['parent_item_id']??0),(float)($in['qty']??1.0),$in['as_of']??null);
  $tree=$svc->tree((int)($in['parent_item_id']??0),(float)($in['qty']??1.0),$in['as_of']??null);
  echo json_encode(['ok'=>true,'data'=>['flat'=>$flat,'tree'=>$tree]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
