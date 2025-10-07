<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min((int)($_GET['limit'] ?? 20), 50));
if ($q === '') { echo json_encode([]); exit; }

$pdo = db();
$st = $pdo->prepare("
  SELECT l.id, l.code, l.title, l.party_id, l.party_contact_id, l.owner_id,
         p.name AS party_name
  FROM crm_leads l
  LEFT JOIN parties p ON p.id = l.party_id
  WHERE l.deleted_at IS NULL
    AND (l.code LIKE :kw OR l.title LIKE :kw OR p.name LIKE :kw)
  ORDER BY l.id DESC
  LIMIT {$limit}
");
$st->execute([':kw'=>"%{$q}%"]);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_UNICODE);
