<?php
/** PATH: /public_html/admin/org_profile_save.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
require_permission('admin.org.edit');
verify_csrf_or_die();

$pdo = db();

$data = [
  'legal_name' => trim((string)($_POST['legal_name'] ?? '')),
  'gstin' => trim((string)($_POST['gstin'] ?? '')),
  'address_line1' => trim((string)($_POST['address_line1'] ?? '')),
  'address_line2' => trim((string)($_POST['address_line2'] ?? '')),
  'city' => trim((string)($_POST['city'] ?? '')),
  'state' => trim((string)($_POST['state'] ?? '')),
  'state_code' => trim((string)($_POST['state_code'] ?? '')),
  'pincode' => trim((string)($_POST['pincode'] ?? '')),
  'phone' => trim((string)($_POST['phone'] ?? '')),
  'email' => trim((string)($_POST['email'] ?? '')),
  'bank_name' => trim((string)($_POST['bank_name'] ?? '')),
  'bank_branch' => trim((string)($_POST['bank_branch'] ?? '')),
  'bank_ifsc' => trim((string)($_POST['bank_ifsc'] ?? '')),
  'bank_account_no' => trim((string)($_POST['bank_account_no'] ?? '')),
];

try {
  $pdo->beginTransaction();
  // ensure row exists
  $pdo->exec("INSERT IGNORE INTO org_profile (id, legal_name) VALUES (1, 'Your Company Pvt Ltd')");
  // update row
  $sql = "UPDATE org_profile SET
    legal_name=:legal_name, gstin=:gstin, address_line1=:address_line1, address_line2=:address_line2,
    city=:city, state=:state, state_code=:state_code, pincode=:pincode,
    phone=:phone, email=:email,
    bank_name=:bank_name, bank_branch=:bank_branch, bank_ifsc=:bank_ifsc, bank_account_no=:bank_account_no
    WHERE id=1";
  $st = $pdo->prepare($sql);
  $st->execute($data);

  audit_log($pdo, 'org_profile', 'update', 1, $data);
  $pdo->commit();

  set_flash('success', 'Company profile saved.');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  set_flash('danger', $e->getMessage());
}

header('Location: org_profile_form.php');
exit;