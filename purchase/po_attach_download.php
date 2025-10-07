<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';

require_login();

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

function col_exists(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $pdo->prepare($sql); $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}
function first_existing(PDO $pdo, string $table, array $candidates): string {
  foreach ($candidates as $c) if ($c && col_exists($pdo,$table,$c)) return $c;
  return '';
}

$po_id = (int)($_GET['po_id'] ?? 0);
$att_id = (int)($_GET['attachment_id'] ?? 0);
if ($po_id<=0 || $att_id<=0) { http_response_code(400); echo "Missing ids"; exit; }

// attachment_links check
$pathCol  = first_existing($pdo,'attachments', ['path','file_path','filepath','url']);
$nameCol  = first_existing($pdo,'attachments', ['original_name','filename','name']);

$select = "SELECT a.id".
          ($pathCol ? ", a.`$pathCol` AS path" : "").
          ($nameCol ? ", a.`$nameCol` AS original_name" : "").
          " FROM attachment_links al
            JOIN attachments a ON a.id=al.attachment_id
           WHERE al.entity_type='purchase_order' AND al.entity_id=? AND al.attachment_id=?";

$st = $pdo->prepare($select);
$st->execute([$po_id, $att_id]);
$a = $st->fetch(PDO::FETCH_ASSOC);
if (!$a) { http_response_code(404); echo "Attachment not found"; exit; }

$rel = (string)($a['path'] ?? '');
$downloadName = (string)($a['original_name'] ?? ('attachment-'.$att_id));
$abs = $rel ? (__DIR__.'/..'.$rel) : '';

if (!$rel || !is_file($abs)) {
  http_response_code(404); echo "File missing on disk"; exit;
}

$mime = mime_content_type($abs) ?: 'application/octet-stream';
header('Content-Description: File Transfer');
header('Content-Type: '.$mime);
header('Content-Disposition: attachment; filename="'.basename($downloadName).'"');
header('Content-Length: '.filesize($abs));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($abs);
