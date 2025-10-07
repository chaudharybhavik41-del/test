
<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/includes/auth.php';
require_once dirname(__DIR__,2) . '/includes/db.php';
require_once dirname(__DIR__,2) . '/includes/rbac.php';
header('Content-Type: application/json');
try{
  require_login(); require_permission('finance.ap.create');
  $pdo = db();
  $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $st=$pdo->prepare("INSERT INTO ap_invoices (vendor_id,currency,fx_rate,invoice_no,invoice_date,category,notes) VALUES (?,?,?,?,?,?,?)");
  $st->execute([(int)($in['vendor_id'] ?? 0), (string)($in['currency'] ?? 'INR'), (float)($in['fx_rate'] ?? 1.0), (string)($in['invoice_no'] ?? ''), (string)($in['invoice_date'] ?? date('Y-m-d')), (string)($in['category'] ?? 'goods'), $in['notes'] ?? null]);
  echo json_encode(['ok'=>true,'data'=>['invoice_id'=>(int)$pdo->lastInsertId()]]);
}catch(Throwable $e){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
