
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/auth.php';
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/rbac.php';
require_once dirname(__DIR__,2).'/includes/coupler/QaLinkService.php';
header('Content-Type: application/json');
try{ require_login(); require_permission('qa.view'); $pdo=db();
  $svc=new \Coupler\QaLinkService($pdo);
  $lot = isset($_GET['lot_id']) ? intval($_GET['lot_id']) : null;
  $grn = isset($_GET['grn_line_id']) ? intval($_GET['grn_line_id']) : null;
  $att = isset($_GET['attachment_id']) ? intval($_GET['attachment_id']) : null;
  $rows=$svc->list($lot,$grn,$att);
  echo json_encode(['ok'=>true,'data'=>$rows]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
