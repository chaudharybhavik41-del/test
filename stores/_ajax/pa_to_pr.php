<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/rbac.php';
require_once __DIR__.'/../../includes/audit.php';
require_once __DIR__.'/../../includes/services/NumberingService.php';
require_permission('purchase.advice.manage');
header('Content-Type: application/json; charset=utf-8');

try{
  $pdo=db();
  $in=json_decode(file_get_contents('php://input')?:'[]',true);
  $advice_id=(int)($in['advice_id']??0);
  if($advice_id<=0) throw new RuntimeException('advice_id required');

  $hdr=$pdo->prepare("SELECT * FROM purchase_advice WHERE id=? AND status IN ('draft','approved')");
  $hdr->execute([$advice_id]); $pa=$hdr->fetch(PDO::FETCH_ASSOC);
  if(!$pa) throw new RuntimeException('Advice not found');

  $lines=$pdo->prepare("SELECT item_id,uom_id,suggested_qty qty FROM purchase_advice_items WHERE advice_id=? AND suggested_qty>0");
  $lines->execute([$advice_id]); $rows=$lines->fetchAll(PDO::FETCH_ASSOC);
  if(!$rows) throw new RuntimeException('No lines to requisition');

  $user=(int)($_SESSION['user_id']??0);
  $pdo->beginTransaction();
  $pr_no=NumberingService::next($pdo,'PR');
  $pdo->prepare("INSERT INTO purchase_requisitions (pr_no, pr_date, warehouse_id, source_advice_id, status, remarks, created_by)
                 VALUES (?, CURRENT_DATE, ?, ?, 'draft', NULL, ?)")
      ->execute([$pr_no,(int)$pa['warehouse_id'], $advice_id, $user]);
  $pr_id=(int)$pdo->lastInsertId();

  $ins=$pdo->prepare("INSERT INTO purchase_requisition_items (pr_id,item_id,uom_id,qty,remarks) VALUES (?,?,?,?,?)");
  foreach($rows as $r){
    $ins->execute([$pr_id,(int)$r['item_id'], $r['uom_id']?(int)$r['uom_id']:null, (float)$r['qty'], null]);
  }

  audit_log($pdo,'purchase_requisitions','create_from_advice',$pr_id,json_encode(['advice_id'=>$advice_id]));
  $pdo->commit();
  echo json_encode(['ok'=>true,'pr_id'=>$pr_id,'pr_no'=>$pr_no]);
}catch(Throwable $e){
  if($pdo?->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
