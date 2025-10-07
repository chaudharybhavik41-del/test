<?php
/** PATH: /public_html/attachments/delete.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
$pdo = db();
if (!has_permission('attachment.manage')) { http_response_code(403); exit('Forbidden'); }

$linkId = (int)($_GET['link_id'] ?? 0);
if ($linkId <= 0) { http_response_code(400); exit('Bad request'); }

$st = $pdo->prepare("SELECT entity_type, entity_id, attachment_id FROM attachment_links WHERE id = ?");
$st->execute([$linkId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Not found'); }

$pdo->beginTransaction();
try {
  $pdo->prepare("DELETE FROM attachment_links WHERE id=?")->execute([$linkId]);

  audit_log_add($pdo, current_user_id(), (string)$row['entity_type'], (int)$row['entity_id'],
                'attachment_removed', ['attachment_id'=>(int)$row['attachment_id']], null);

  $pdo->commit();
  $ret = (string)($_GET['return'] ?? '/');
  header('Location: ' . $ret);
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo "Failed: ".$e->getMessage();
}
