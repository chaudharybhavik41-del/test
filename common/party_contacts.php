<?php
/** PATH: /public_html/common/party_contacts.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  require_permission('parties.view');

  $partyId = (int)($_GET['party_id'] ?? 0);
  if ($partyId <= 0) { echo json_encode(['ok'=>false,'message'=>'party_id required']); exit; }

  $pdo = db();
  $st = $pdo->prepare("SELECT id, name, email, phone, role_title, is_primary
                       FROM party_contacts WHERE party_id=? ORDER BY is_primary DESC, name ASC LIMIT 50");
  $st->execute([$partyId]);
  $contacts = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true,'items'=>$contacts]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}