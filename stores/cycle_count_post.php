<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/StockMoveWriter.php';
require_once __DIR__ . '/../includes/ValuationService.php';
require_once __DIR__ . '/../includes/StockLedgerAdapter.php';
header('Content-Type: application/json');
try {
  require_permission('stores.cycle.manage');
  csrf_require_token($_POST['csrf_token'] ?? '');
  $pdo = db();
  $cycle_id = (int)($_POST['cycle_id'] ?? 0);
  if ($cycle_id <= 0) throw new RuntimeException('Invalid cycle_id');
  $hdr = $pdo->prepare("SELECT * FROM cycle_counts WHERE id=:id FOR UPDATE"); $hdr->execute([':id'=>$cycle_id]); $hdr = $hdr->fetch(PDO::FETCH_ASSOC);
  if (!$hdr) throw new RuntimeException('Cycle not found'); if ($hdr['status'] === 'POSTED') throw new RuntimeException('Already posted');
  $stmtLines = $pdo->prepare("SELECT * FROM cycle_count_items WHERE cycle_id=:id ORDER BY line_no"); $stmtLines->execute([':id'=>$cycle_id]); $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
  if (!$lines) throw new RuntimeException('No lines to post');
  $counted = $_POST['counted_qty'] ?? []; $remarks = $_POST['remarks'] ?? [];
  $pdo->beginTransaction();
  $upd = $pdo->prepare("UPDATE cycle_count_items SET counted_qty=:c, variance_qty=:v, remarks=:r WHERE id=:id");
  $waQ = $pdo->prepare("SELECT avg_cost FROM stock_avg WHERE item_id = :i AND warehouse_id = :w");
  $now = date('Y-m-d H:i:s'); $userId = (int)($_SESSION['user_id'] ?? 0);
  foreach ($lines as $ln) {
    $id = (int)$ln['id']; $c = isset($counted[$id]) ? (float)$counted[$id] : 0.0; $v = $c - (float)$ln['expected_qty']; $r = isset($remarks[$id]) ? trim($remarks[$id]) : '';
    $upd->execute([':c'=>$c, ':v'=>$v, ':r'=>$r, ':id'=>$id]);
    if (abs($v) < 0.0005) continue;
    $waQ->execute([':i'=>$ln['item_id'], ':w'=>$hdr['warehouse_id']]); $wa = (float)$waQ->fetchColumn(); if ($wa < 0) $wa = 0.0;
    if ($v > 0) {
      $payload = ['txn_type'=>'GRN','txn_no'=>$hdr['cc_no'],'txn_date'=>$now,'project_id'=>$hdr['project_id'],'item_id'=>(int)$ln['item_id'],'uom_id'=>$ln['uom_id']?:null,'warehouse_id'=>(int)$hdr['warehouse_id'],'bin_id'=>$hdr['bin_id']?:null,'qty'=>(float)$v,'unit_cost'=>$wa,'ref_entity'=>'cycle_counts','ref_id'=>(int)$hdr['id'],'created_by'=>$userId];
      StockMoveWriter::postIn($pdo, $payload); ValuationService::onReceipt($pdo, (int)$ln['item_id'], (int)$hdr['warehouse_id'], (float)$v, $wa); StockLedgerAdapter::mirror($pdo, $payload);
    } else {
      $qty = abs($v);
      $payload = ['txn_type'=>'ISS','txn_no'=>$hdr['cc_no'],'txn_date'=>$now,'project_id'=>$hdr['project_id'],'item_id'=>(int)$ln['item_id'],'uom_id'=>$ln['uom_id']?:null,'warehouse_id'=>(int)$hdr['warehouse_id'],'bin_id'=>$hdr['bin_id']?:null,'qty'=>$qty,'unit_cost'=>$wa,'ref_entity'=>'cycle_counts','ref_id'=>(int)$hdr['id'],'created_by'=>$userId];
      StockMoveWriter::postOut($pdo, $payload); ValuationService::onIssue($pdo, (int)$ln['item_id'], (int)$hdr['warehouse_id'], $qty); StockLedgerAdapter::mirror($pdo, $payload);
    }
  }
  $pdo->prepare("UPDATE cycle_counts SET status='POSTED', posted_at=NOW(6) WHERE id=:id")->execute([':id'=>$hdr['id']]);
  $pdo->commit(); echo json_encode(['ok'=>true, 'cycle_id'=>$hdr['id'], 'cc_no'=>$hdr['cc_no']]);
} catch (Throwable $e) { if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack(); http_response_code(400); echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); }
