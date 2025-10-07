<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
require_permission('purchase.quote.manage');

$pdo=db();
$id=(int)($_GET['id']??0);
if($id>0){
  $pdo->prepare("DELETE FROM inquiry_quote_items WHERE quote_id=?")->execute([$id]);
  $pdo->prepare("DELETE FROM inquiry_quotes WHERE id=? AND status='draft'")->execute([$id]);
}
header('Location: /purchase/inquiry_quotes_list.php');
