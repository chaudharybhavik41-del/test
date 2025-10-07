
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
  $id=$svc->addComponent((int)($in['version_id']??0),(int)($in['component_item_id']??0),(float)($in['qty_per_parent']??0),(float)($in['scrap_pct']??0),(bool)($in['is_phantom']??false),(bool)($in['is_remnant_return']??false),(int)($in['line_no']??10),$in['remarks']??null);
  echo json_encode(['ok'=>true,'data'=>['component_id'=>$id]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
