<?php
/** PATH: /public_html/hr/employee_docs_post.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
$pdo = db();

$employeeId = (int)($_POST['employee_id'] ?? 0);
if ($employeeId <= 0) { http_response_code(400); exit('employee_id required'); }

if (isset($_POST['delete_id'])) {
  require_permission('hr.employee.manage');
  $id = (int)$_POST['delete_id'];
  $st = $pdo->prepare("SELECT file_path FROM employee_documents WHERE id=? AND employee_id=?");
  $st->execute([$id, $employeeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $pdo->prepare("DELETE FROM employee_documents WHERE id=?")->execute([$id]);
    $file = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $row['file_path'];
    if (is_file($file)) @unlink($file);
  }
  header('Location: /hr/employees_form.php?id='.$employeeId.'&docs=deleted'); exit;
}

if (!empty($_FILES['file']['name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
  require_permission('hr.employee.manage');

  $docType = $_POST['doc_type'] ?? 'other';
  $allowedTypes = ['aadhaar','pan','passbook','offer_letter','joining_form','other'];
  if (!in_array($docType, $allowedTypes, true)) $docType = 'other';

  $mime = mime_content_type($_FILES['file']['tmp_name']);
  $size = (int)$_FILES['file']['size'];
  if ($size > 5*1024*1024) { header('Location: /hr/employees_form.php?id='.$employeeId.'&docs=toolarge'); exit; }

  $ext = 'bin';
  $map = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/zip'=>'zip','application/x-zip-compressed'=>'zip'];
  if (isset($map[$mime])) $ext = $map[$mime];

  $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
  $dir  = $root . '/uploads/employees/' . $employeeId . '/docs';
  if (!is_dir($dir)) mkdir($dir, 0775, true);

  $safeBase = preg_replace('/[^a-zA-Z0-9_\-\.]/','_', $_FILES['file']['name']);
  $fname = date('Ymd_His') . '_' . $docType . '_' . $safeBase;
  $path = $dir . '/' . $fname;
  if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
    header('Location: /hr/employees_form.php?id='.$employeeId.'&docs=fail'); exit;
  }
  $rel = '/uploads/employees/' . $employeeId . '/docs/' . $fname;

  $st = $pdo->prepare("INSERT INTO employee_documents (employee_id, doc_type, file_path, original_name, mime_type, created_at)
                       VALUES (?, ?, ?, ?, ?, NOW())");
  $st->execute([$employeeId, $docType, $rel, $_FILES['file']['name'], $mime]);

  header('Location: /hr/employees_form.php?id='.$employeeId.'&docs=ok'); exit;
}

header('Location: /hr/employees_form.php?id='.$employeeId.'&docs=bad'); exit;
