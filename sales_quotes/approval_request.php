<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/../includes/csrf.php';
require_login(); require_permission('sales.quote.edit'); csrf_require_token();

$id=(int)($_POST['id']??0); if($id<=0){flash('Invalid','danger'); redirect('/sales_quotes/sales_quotes_list.php');}
$pdo=db();
$pdo->prepare("UPDATE sales_quotes SET approval_status='Pending', updated_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
flash('Quote sent for approval.','success');
redirect('/sales_quotes/sales_quotes_view.php?id='.$id);
