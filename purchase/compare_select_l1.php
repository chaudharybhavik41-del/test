<?php
/** PATH: /public_html/purchase/compare_select_l1.php
 * Select L1 quotation for an inquiry and lock its prices.
 * GET params: inquiry_id, quote_id
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_permission('purchase.inquiry.compare'); // adjust if you use a different permission
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

  $inquiry_id = (int)($_GET['inquiry_id'] ?? 0);
  $quote_id   = (int)($_GET['quote_id']   ?? 0);
  if ($inquiry_id<=0 || $quote_id<=0) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'bad_request']); exit; }

  // Ensure the quote belongs to the inquiry
  $q = $pdo->prepare("SELECT id FROM inquiry_quotes WHERE id=? AND inquiry_id=?");
  $q->execute([$quote_id, $inquiry_id]);
  if (!$q->fetchColumn()) { http_response_code(404); echo json_encode(['ok'=>false,'msg'=>'quote_not_found']); exit; }

  // --- detect line table + foreign key name (fallbacks) ---
  $lineTable = 'inquiry_quote_items';
  $fkCol     = 'inquiry_quote_id';
  $hasLineT  = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$lineTable}'")->fetchColumn();
  if (!$hasLineT) { // try a common alt name
    $lineTable = 'quote_items';
  }
  $colChk = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $colChk->execute([$lineTable]);
  $cols = $colChk->fetchAll(PDO::FETCH_COLUMN);
  if ($cols && !in_array('inquiry_quote_id',$cols,true) && in_array('quote_id',$cols,true)) $fkCol='quote_id';
  if (!$cols) { http_response_code(500); echo json_encode(['ok'=>false,'msg'=>'line_table_not_found']); exit; }

  $pdo->beginTransaction();

  // Snapshot & lock line prices on the selected quote
  // l1_total = sum(unit_price * qty)
  $sum = $pdo->prepare("SELECT COALESCE(SUM(unit_price * qty),0) FROM {$lineTable} WHERE {$fkCol}=?");
  $sum->execute([$quote_id]);
  $l1_total = (float)$sum->fetchColumn();

  // Set line locks + snapshot price
  $pdo->prepare("UPDATE {$lineTable} SET l1_locked=1, l1_unit_price=unit_price WHERE {$fkCol}=?")
      ->execute([$quote_id]);

  // Mark this quote as selected/locked; clear others
  $pdo->prepare("UPDATE inquiry_quotes SET is_l1=0 WHERE inquiry_id=?")->execute([$inquiry_id]);
  $pdo->prepare("UPDATE inquiry_quotes SET is_l1=1, status='locked', l1_total=? WHERE id=?")
      ->execute([$l1_total, $quote_id]);

  $pdo->commit();
  echo json_encode(['ok'=>true,'l1_total'=>$l1_total]);
} catch (Throwable $e) {
  if ($pdo?->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'msg'=>'server_error']);
}
