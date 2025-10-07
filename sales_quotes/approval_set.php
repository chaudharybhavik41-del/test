<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/../includes/csrf.php';
require_login(); require_permission('sales.quote.approve'); csrf_require_token();

$id=(int)($_POST['id']??0);
$action=(string)($_POST['action']??'');
if($id<=0){flash('Invalid','danger'); redirect('/sales_quotes/sales_quotes_list.php');}
$pdo=db();

if ($action==='approve'){
  $pdo->prepare("UPDATE sales_quotes SET approval_status='Approved', approved_by=:u, approved_at=NOW(), updated_at=NOW() WHERE id=:id")->execute([':u'=>current_user_id(),':id'=>$id]);
  flash('Quote approved.','success');
} elseif ($action==='revert'){
  $pdo->prepare("UPDATE sales_quotes SET approval_status='Draft', approved_by=NULL, approved_at=NULL, updated_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
  flash('Quote reverted to Draft.','warning');
}
redirect('/sales_quotes/sales_quotes_view.php?id='.$id);
