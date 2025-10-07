<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('purchase.inquiry.close');

$pdo=db(); $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$id=(int)($_GET['id']??0);
$pdo->prepare("UPDATE inquiries SET status='closed', closed_at=NOW(), closed_by=? WHERE id=? AND status='issued'")
    ->execute([current_user_id(), $id]);
header('Location: /purchase/inquiries_list.php'); exit;