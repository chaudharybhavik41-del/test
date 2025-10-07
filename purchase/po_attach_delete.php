<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
require_permission('purchase.po.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

// Figure path col for unlink (optional)
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
$pathCol  = first_existing($pdo,'attachments', ['path','file_path','filepath','url']);

$select = "SELECT ".($pathCol ? "a.`$pathCol` AS path" : "'' AS path")."
           FROM attachment_links al
           JOIN attachments a ON a.id=al.attachment_id
           WHERE al.entity_type='purchase_order' AND al.entity_id=? AND al.attachment_id=?";
$st = $pdo->prepare($select);
$st->execute([$po_id, $att_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo "Attachment not found"; exit; }

$pdo->beginTransaction();
try {
  $pdo->prepare("DELETE FROM attachment_links WHERE attachment_id=? AND entity_type='purchase_order' AND entity_id=?")
      ->execute([$att_id,$po_id]);

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM attachment_links WHERE attachment_id=?");
  $cnt->execute([$att_id]);
  if ((int)$cnt->fetchColumn() === 0) {
    $pdo->prepare("DELETE FROM attachments WHERE id=?")->execute([$att_id]);
    if (!empty($row['path'])) {
      $abs = __DIR__.'/..'.($row['path']);
      if (is_file($abs)) @unlink($abs);
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500); echo "Delete failed: ".$e->getMessage(); exit;
}

$pdo->beginTransaction();
try {
  $pdo->prepare("DELETE FROM attachment_links WHERE attachment_id=? AND entity_type='purchase_order' AND entity_id=?")->execute([$att_id,$po_id]);

  $cnt = $pdo->prepare("SELECT COUNT(*) FROM attachment_links WHERE attachment_id=?");
  $cnt->execute([$att_id]);
  if ((int)$cnt->fetchColumn() === 0) {
    $pdo->prepare("DELETE FROM attachments WHERE id=?")->execute([$att_id]);
    if (!empty($row['path'])) {
      $abs = __DIR__.'/..'.($row['path']);
      if (is_file($abs)) @unlink($abs);
    }
  }
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500); echo "Delete failed: ".$e->getMessage(); exit;
}

header('Location: /purchase/po_form.php?id='.$po_id);
