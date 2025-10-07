<?php
/**
 * QC Release POST
 * - Creates qc_releases header+lines
 * - Creates stock_transfers header (TRN) and posts OUT from qc_bin -> IN to to_bin (same warehouse)
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/NumberingService.php';
require_once __DIR__ . '/../includes/StockMoveWriter.php';
require_once __DIR__ . '/../includes/ValuationService.php';
require_once __DIR__ . '/../includes/StockLedgerAdapter.php';

header('Content-Type: application/json');

try {
  require_permission('stores.qc.manage');

  $raw = file_get_contents('php://input');
  $input = json_decode($raw, true);
  if (!$input) $input = $_POST;
  if (!empty($_POST)) csrf_require_token($_POST['csrf_token'] ?? '');

  $pdo = db();
  $userId = (int)($_SESSION['user_id'] ?? 0);
  $now = date('Y-m-d H:i:s');

  $warehouseId = (int)($input['warehouse_id'] ?? 0);
  $qcBinId     = isset($input['qc_bin_id']) ? (int)$input['qc_bin_id'] : null;
  $toBinId     = isset($input['to_bin_id']) ? (int)$input['to_bin_id'] : null;
  $remarks     = trim($input['remarks'] ?? '');

  $lines = [];
  if (isset($input['lines']['item_id'])) {
      $cnt = count($input['lines']['item_id']);
      for($i=0;$i<$cnt;$i++){
          $lines[] = [
              'item_id'  => (int)$input['lines']['item_id'][$i],
              'uom_id'   => isset($input['lines']['uom_id'][$i]) ? (int)$input['lines']['uom_id'][$i] : null,
              'qty'      => (float)$input['lines']['qty'][$i],
              'batch_id' => isset($input['lines']['batch_id'][$i]) ? (int)$input['lines']['batch_id'][$i] : null,
              'remarks'  => trim($input['lines']['remarks'][$i] ?? '')
          ];
      }
  } else {
      $lines = $input['lines'] ?? [];
  }

  if ($warehouseId<=0 || !$qcBinId) throw new RuntimeException('Warehouse and QC bin required');
  if (empty($lines)) throw new RuntimeException('At least one line is required');

  $pdo->beginTransaction();

  $qcNo = NumberingService::next($pdo, 'QCR');

  // qc_releases header
  $pdo->prepare("INSERT INTO qc_releases (qc_no, warehouse_id, qc_bin_id, to_bin_id, remarks, status, created_by, created_at)
                 VALUES (:no,:w,:qb,:tb,:rm,'POSTED',:uid,NOW(6))")
      ->execute([':no'=>$qcNo, ':w'=>$warehouseId, ':qb'=>$qcBinId, ':tb'=>$toBinId, ':rm'=>$remarks, ':uid'=>$userId]);
  $qcId = (int)$pdo->lastInsertId();

  $insLine = $pdo->prepare("INSERT INTO qc_release_items (qc_id, line_no, item_id, uom_id, qty, qc_bin_id, to_bin_id, batch_id, remarks)
                            VALUES (:id,:ln,:i,:u,:q,:qb,:tb,:b,:r)");

  // Underlying stock_transfer document for TRN
  $pdo->prepare("INSERT INTO stock_transfers (trn_no, from_warehouse_id, to_warehouse_id, from_bin_id, to_bin_id, remarks, status, created_by, created_at)
                 VALUES (:no,:w,:w,:qb,:tb,:rm,'POSTED',:uid,NOW(6))")
      ->execute([':no'=>$qcNo, ':w'=>$warehouseId, ':qb'=>$qcBinId, ':tb'=>$toBinId, ':rm'=>$remarks, ':uid'=>$userId]);
  $trnId = (int)$pdo->lastInsertId();

  $waQ = $pdo->prepare("SELECT avg_cost FROM stock_avg WHERE item_id=:i AND warehouse_id=:w");

  $lineNo=0;
  foreach ($lines as $ln) {
    $lineNo++;
    $itemId=(int)$ln['item_id']; $uomId=isset($ln['uom_id'])?(int)$ln['uom_id']:null; $qty=(float)$ln['qty'];
    $batchId=isset($ln['batch_id'])?(int)$ln['batch_id']:null; $r=trim($ln['remarks']??'');
    if ($itemId<=0 || $qty<=0) throw new RuntimeException("Invalid line #$lineNo");

    $insLine->execute([':id'=>$qcId, ':ln'=>$lineNo, ':i'=>$itemId, ':u'=>$uomId, ':q'=>$qty, ':qb'=>$qcBinId, ':tb'=>$toBinId, ':b'=>$batchId, ':r'=>$r]);

    $waQ->execute([':i'=>$itemId, ':w'=>$warehouseId]); $rate=(float)$waQ->fetchColumn(); if ($rate<0) $rate=0.0;

    // OUT from QC bin
    $outPayload=['txn_type'=>'TRN','txn_no'=>$qcNo,'txn_date'=>$now,'project_id'=>null,'item_id'=>$itemId,'uom_id'=>$uomId,
      'warehouse_id'=>$warehouseId,'bin_id'=>$qcBinId,'batch_id'=>$batchId,'qty'=>$qty,'unit_cost'=>$rate,
      'ref_entity'=>'stock_transfers','ref_id'=>$trnId,'created_by'=>$userId];
    StockMoveWriter::postOut($pdo,$outPayload);
    ValuationService::onIssue($pdo,$itemId,$warehouseId,$qty);
    StockLedgerAdapter::mirror($pdo,$outPayload);

    // IN to target bin
    $inPayload=['txn_type'=>'TRN','txn_no'=>$qcNo,'txn_date'=>$now,'project_id'=>null,'item_id'=>$itemId,'uom_id'=>$uomId,
      'warehouse_id'=>$warehouseId,'bin_id'=>$toBinId,'batch_id'=>$batchId,'qty'=>$qty,'unit_cost'=>$rate,
      'ref_entity'=>'stock_transfers','ref_id'=>$trnId,'created_by'=>$userId];
    StockMoveWriter::postIn($pdo,$inPayload);
    ValuationService::onReceipt($pdo,$itemId,$warehouseId,$qty,$rate);
    StockLedgerAdapter::mirror($pdo,$inPayload);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true,'release_id'=>$qcId,'qc_no'=>$qcNo]);

} catch (Throwable $e) {
  if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
