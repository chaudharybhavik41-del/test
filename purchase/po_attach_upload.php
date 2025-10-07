<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
require_permission('purchase.po.manage');

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

$po_id = (int)($_POST['po_id'] ?? 0);
if ($po_id<=0) { http_response_code(400); echo "Missing po_id"; exit; }

$st=$pdo->prepare("SELECT id FROM purchase_orders WHERE id=?");
$st->execute([$po_id]);
if(!$st->fetch()){ http_response_code(404); echo "PO not found"; exit; }

if (!isset($_FILES['file']) || $_FILES['file']['error']!==UPLOAD_ERR_OK) {
  http_response_code(400); echo "Upload error"; exit;
}
$notes = trim((string)($_POST['notes'] ?? ''));

$dir = __DIR__.'/../uploads/po';
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$orig = $_FILES['file']['name'];
$tmp  = $_FILES['file']['tmp_name'];
$mime = mime_content_type($tmp) ?: ($_FILES['file']['type'] ?? 'application/octet-stream');
$size = (int)($_FILES['file']['size'] ?? 0);
$ext  = pathinfo($orig, PATHINFO_EXTENSION);
$fname = 'po-'.$po_id.'-'.bin2hex(random_bytes(6)).($ext?'.'.$ext:'');
$destRel = '/uploads/po/'.$fname;
$destAbs = __DIR__.'/..'.$destRel;
if (!move_uploaded_file($tmp, $destAbs)) { http_response_code(500); echo "Failed to save file"; exit; }

// Detect columns present in attachments
$attTable = 'attachments';
$nameCol  = first_existing($pdo,$attTable, ['original_name','filename','name']); // required
$pathCol  = first_existing($pdo,$attTable, ['path','file_path','filepath','url']);
$mimeCol  = first_existing($pdo,$attTable, ['mime','mime_type','content_type']);
$sizeCol  = first_existing($pdo,$attTable, ['size','bytes','file_size']);
$uplByCol = first_existing($pdo,$attTable, ['uploaded_by','created_by','user_id']);
$timeCol  = first_existing($pdo,$attTable, ['uploaded_at','created_at']);

if (!$nameCol) {
  @unlink($destAbs);
  http_response_code(500); echo "attachments table must have one of: original_name/filename/name"; exit;
}

$cols=[]; $vals=[]; $phs=[];
$cols[]=$nameCol;  $vals[]=$orig;         $phs[]='?';
if ($pathCol) { $cols[]=$pathCol; $vals[]=$destRel;             $phs[]='?'; }
if ($mimeCol) { $cols[]=$mimeCol; $vals[]=$mime;                $phs[]='?'; }
if ($sizeCol) { $cols[]=$sizeCol; $vals[]=$size;                $phs[]='?'; }
if ($uplByCol){ $cols[]=$uplByCol;$vals[] = current_user_id();  $phs[]='?'; }
if ($timeCol) { $cols[]=$timeCol; $vals[] = date('Y-m-d H:i:s');$phs[]='?'; }

$pdo->beginTransaction();
try {
  $sql = "INSERT INTO attachments (".implode(',',$cols).") VALUES (".implode(',',$phs).")";
  $ins = $pdo->prepare($sql);
  $ins->execute($vals);
  $aid = (int)$pdo->lastInsertId();

  // attachment_links may or may not have 'notes'
  $hasNotes = col_exists($pdo, 'attachment_links', 'notes');
  if ($hasNotes) {
    $insL = $pdo->prepare("INSERT INTO attachment_links (attachment_id, entity_type, entity_id, notes) VALUES (?,?,?,?)");
    $insL->execute([$aid, 'purchase_order', $po_id, $notes ?: null]);
  } else {
    $insL = $pdo->prepare("INSERT INTO attachment_links (attachment_id, entity_type, entity_id) VALUES (?,?,?)");
    $insL->execute([$aid, 'purchase_order', $po_id]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  @unlink($destAbs);
  http_response_code(500); echo "Save failed: ".$e->getMessage(); exit;
}

header('Location: /purchase/po_form.php?id='.$po_id);
