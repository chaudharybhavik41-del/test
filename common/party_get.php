<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { echo json_encode([]); exit; }

$pdo = db();
$st = $pdo->prepare("
  SELECT id, name, code, gst_number, address_line1, address_line2, city, state, pincode
  FROM parties
  WHERE id=:id AND deleted_at IS NULL
");
$st->execute([':id'=>$id]);
echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_UNICODE);
