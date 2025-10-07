
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/ScrapRemnantService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('stores.scrap'); $pdo=db();
  $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
  $svc=new \Coupler\ScrapRemnantService($pdo);
  $res=$svc->convertToScrap((int)($in['piece_id']??0),(float)($in['qty_base']??0),(int)($in['scrap_item_id']??0),(int)($in['warehouse_id']??0),(string)($in['reason']??''));
  echo json_encode(['ok'=>true,'data'=>$res]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
