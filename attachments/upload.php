<?php
/** PATH: /public_html/attachments/upload.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
$pdo = db();

$entity   = trim((string)($_POST['entity'] ?? ''));
$entityId = (int)($_POST['entity_id'] ?? 0);
if ($entity === '' || $entityId <= 0 || empty($_FILES['file'])) { http_response_code(400); exit('Bad request'); }

$u = $_FILES['file'];
if ($u['error'] !== UPLOAD_ERR_OK) { http_response_code(400); exit('Upload error'); }

$ym  = date('Y/m');
$dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . $ym;
if (!is_dir($dir)) mkdir($dir, 0775, true);

$ext = pathinfo($u['name'], PATHINFO_EXTENSION);
$rand = bin2hex(random_bytes(8));
$fname = $rand . ($ext ? ('.'.$ext) : '');
$destAbs = $dir . '/' . $fname;
$destRel = '/uploads/' . $ym . '/' . $fname;

if (!move_uploaded_file($u['tmp_name'], $destAbs)) { http_response_code(500); exit('Failed to move file'); }
chmod($destAbs, 0644);

$pdo->beginTransaction();
try {
  $stA = $pdo->prepare("INSERT INTO attachments (original_name, mime_type, storage_path, byte_size, uploaded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())");
  $stA->execute([
    $u['name'],
    $u['type'] ?: null,
    $destRel,
    (int)$u['size'],
    current_user_id()
  ]);
  $attId = (int)$pdo->lastInsertId();

  $stL = $pdo->prepare("INSERT INTO attachment_links (entity_type, entity_id, attachment_id, created_by, created_at)
                        VALUES (?, ?, ?, ?, NOW())");
  $stL->execute([$entity, $entityId, $attId, current_user_id()]);

  audit_log_add($pdo, current_user_id(), $entity, $entityId, 'attachment_added', null, ['attachment_id'=>$attId, 'name'=>$u['name']]);

  $pdo->commit();
  header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
} catch (Throwable $e) {
  $pdo->rollBack();
  @unlink($destAbs);
  http_response_code(500);
  echo "Failed: ".$e->getMessage();
}
