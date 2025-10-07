
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/includes/auth.php';
require_once dirname(__DIR__,2) . '/includes/db.php';
require_once dirname(__DIR__,2) . '/includes/rbac.php';
header('Content-Type: application/json');
try{
  require_login(); require_permission('finance.ap.edit');
  $pdo = db();
  $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $qty=(float)($in['qty'] ?? 0); $rate=(float)($in['unit_price'] ?? 0); $amt=round($qty*$rate,2);
  $st=$pdo->prepare("INSERT INTO ap_invoice_lines (invoice_id,po_id,po_line_id,grn_id,grn_line_id,item_id,qty,unit_price,amount) VALUES (?,?,?,?,?,?,?,?,?)");
  $st->execute([(int)($in['invoice_id'] ?? 0), $in['po_id'] ?? null, $in['po_line_id'] ?? null, $in['grn_id'] ?? null, $in['grn_line_id'] ?? null, $in['item_id'] ?? null, $qty, $rate, $amt]);
  $pdo->prepare("UPDATE ap_invoices SET total_amount=COALESCE(total_amount,0)+? WHERE id=?")->execute([$amt, (int)($in['invoice_id'] ?? 0)]);
  echo json_encode(['ok'=>true,'data'=>['invoice_line_id'=>(int)$pdo->lastInsertId(),'amount'=>$amt]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
