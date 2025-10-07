
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/QaLinkService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('qa.link'); $pdo=db();
  $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
  $svc=new \Coupler\QaLinkService($pdo);
  $id=$svc->link((int)$in['attachment_id'], (string)($in['doc_type']??'heat_cert'), $in['lot_id']?intval($in['lot_id']):null, $in['grn_line_id']?intval($in['grn_line_id']):null, $in['item_id']?intval($in['item_id']):null, $in['notes']??null);
  echo json_encode(['ok'=>true,'data'=>['qa_link_id'=>$id]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
