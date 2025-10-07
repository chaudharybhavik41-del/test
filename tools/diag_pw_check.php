<?php
// PATH: /public_html/tools/_diag_pw_check.php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/../includes/db.php';
$u=$_GET['u']??''; $pw=$_GET['pw']??'';
if(!$u||!$pw){ echo "Need ?u=admin&pw=Admin@123\n"; exit; }
$pdo=db();
$st=$pdo->prepare("SELECT username,password,status FROM users WHERE username=? OR email=? LIMIT 1");
$st->execute([$u,$u]);
$row=$st->fetch(PDO::FETCH_ASSOC);
if(!$row){ echo "User not found\n"; exit; }
echo "status={$row['status']}\n";
echo password_verify($pw,$row['password']) ? "PASS\n" : "FAIL\n";
