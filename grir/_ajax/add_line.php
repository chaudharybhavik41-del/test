
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/GrirCloser.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('grir.close.edit'); $pdo=db();
  $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
  $svc=new \Coupler\GrirCloser($pdo);
  $id=$svc->addLine((int)($in['closure_id']??0),(int)($in['grn_line_id']??0),(float)($in['open_value']??0),(float)($in['close_value']??0),(string)($in['reason']??'writeoff'),(string)($in['notes']??null));
  echo json_encode(['ok'=>true,'data'=>['closure_line_id'=>$id]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
