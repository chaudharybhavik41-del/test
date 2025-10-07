<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login(); require_permission('sales.quote.delete'); csrf_require_token();
$id = (int)($_POST['id'] ?? 0);
if ($id>0){
  $pdo = db();
  $stm = $pdo->prepare("UPDATE sales_quotes SET deleted_at=NOW() WHERE id=:id AND status<>'Converted'");
  $stm->execute([':id'=>$id]);
  if ($stm->rowCount()===0) flash('Cannot delete (maybe already converted?)','warning');
  else flash('Quote deleted (soft).','success');
}
redirect('/sales_quotes/sales_quotes_list.php');
