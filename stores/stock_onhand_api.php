<?php
/** PATH: /public_html/stores/stock_onhand_api.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.ledger.view');
header('Content-Type: application/json');

try{
  $pdo = db();
  $item_id = (int)($_GET['item_id']??0);
  $warehouse_id = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;

  if ($item_id<=0) throw new RuntimeException('item_id required');

  if ($warehouse_id) {
    $st = $pdo->prepare("SELECT COALESCE(SUM(qty),0) qty FROM stock_onhand WHERE item_id=? AND warehouse_id=?");
    $st->execute([$item_id,$warehouse_id]);
    $qty = (float)$st->fetchColumn();
    echo json_encode(['ok'=>true,'item_id'=>$item_id,'warehouse_id'=>$warehouse_id,'onhand'=>$qty]);
  } else {
    $st = $pdo->prepare("SELECT warehouse_id, COALESCE(SUM(qty),0) qty FROM stock_onhand WHERE item_id=? GROUP BY warehouse_id");
    $st->execute([$item_id]);
    echo json_encode(['ok'=>true,'item_id'=>$item_id,'by_warehouse'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
