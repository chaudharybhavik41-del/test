<?php
/** PATH: /public_html/api/employee_docs.php */
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
$pdo = db();

$action = $_GET['action'] ?? 'list';
$employeeId = (int)($_REQUEST['employee_id'] ?? 0);
if ($employeeId <= 0) { http_response_code(400); echo json_encode(['error'=>'employee_id required']); exit; }

// AuthZ: viewers can list, managers can upload/delete
$canView = has_permission('hr.employee.view');
$canManage = has_permission('hr.employee.manage');
if (!$canView) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

if ($action === 'list') {
  $st = $pdo->prepare("SELECT id, doc_type, file_path, original_name, mime_type, created_at FROM employee_documents WHERE employee_id=? ORDER BY created_at DESC");
  $st->execute([$employeeId]);
  echo json_encode(['items'=>$st->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

if ($action === 'upload') {
  if (!$canManage) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
  if (empty($_FILES['file']['name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400); echo json_encode(['error'=>'file missing']); exit;
  }
  $docType = $_POST['doc_type'] ?? 'other';
  $allowedTypes = ['aadhaar','pan','passbook','offer_letter','joining_form','other'];
  if (!in_array($docType, $allowedTypes, true)) $docType = 'other';

  $mime = mime_content_type($_FILES['file']['tmp_name']);
  $size = (int)$_FILES['file']['size'];
  if ($size > 5*1024*1024) { http_response_code(400); echo json_encode(['error'=>'max 5MB']); exit; }

  // Accept common image/PDF/zip
  $ext = 'bin';
  $map = [
    'application/pdf'=>'pdf', 'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp',
    'application/zip'=>'zip', 'application/x-zip-compressed'=>'zip'
  ];
  if (isset($map[$mime])) $ext = $map[$mime];

  $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
  $dir = $root . '/uploads/employees/' . $employeeId . '/docs';
  if (!is_dir($dir)) mkdir($dir, 0775, true);

  $safeBase = preg_replace('/[^a-zA-Z0-9_\-\.]/','_', $_FILES['file']['name']);
  $fname = date('Ymd_His') . '_' . $docType . '_' . $safeBase;
  $path = $dir . '/' . $fname;
  if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
    http_response_code(500); echo json_encode(['error'=>'save failed']); exit;
  }
  $rel = '/uploads/employees/' . $employeeId . '/docs/' . $fname;

  $st = $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_type, file_path, original_name, mime_type, created_at)
                       VALUES (?, ?, ?, ?, ?, NOW())");
  $st->execute([$employeeId, $docType, $rel, $_FILES['file']['name'], $mime]);

  echo json_encode(['ok'=>true, 'file'=>$rel]); exit;
}

if ($action === 'delete') {
  if (!$canManage) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
  $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
  if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }

  $st = $pdo->prepare("SELECT file_path FROM employee_documents WHERE id=? AND employee_id=?");
  $st->execute([$id, $employeeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }

  $pdo->prepare("DELETE FROM employee_documents WHERE id=?")->execute([$id]);
  $file = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $row['file_path'];
  if (is_file($file)) @unlink($file);
  echo json_encode(['ok'=>true]); exit;
}

http_response_code(400);
echo json_encode(['error'=>'unknown action']);
