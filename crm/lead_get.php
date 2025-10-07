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
  SELECT l.id, l.code, l.title, l.party_id, l.party_contact_id, l.owner_id,
         p.name AS party_name
  FROM crm_leads l
  LEFT JOIN parties p ON p.id = l.party_id
  WHERE l.id=:id
");
$st->execute([':id'=>$id]);
echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_UNICODE);
