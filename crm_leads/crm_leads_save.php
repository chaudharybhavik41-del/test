<?php
/** PATH: /public_html/crm_leads/crm_leads_save.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/numbering.php';

require_login();
verify_csrf_or_die();

$pdo = db();

$id = (int)($_POST['id'] ?? 0);
$isEdit = $id > 0;
require_permission($isEdit ? 'crm.lead.edit' : 'crm.lead.create');

// Collect
$data = [];
$codeIn = trim((string)($_POST['code'] ?? ''));
$data['code'] = ($codeIn === '') ? null : $codeIn;  // allow NULL (prevents duplicate '' problem)
$data['title'] = trim((string)($_POST['title'] ?? ''));
$data['status'] = trim((string)($_POST['status'] ?? 'New'));
$data['amount'] = ($_POST['amount'] === '' ? null : (string)$_POST['amount']);
$data['is_hot'] = isset($_POST['is_hot']) ? 1 : 0;
$data['follow_up_on'] = (($_POST['follow_up_on'] ?? '') === '' ? null : (string)$_POST['follow_up_on']);
$data['notes'] = trim((string)($_POST['notes'] ?? ''));
$data['party_id'] = (($_POST['party_id'] ?? '') === '' ? null : (int)$_POST['party_id']);
$data['party_contact_id'] = (($_POST['party_contact_id'] ?? '') === '' ? null : (int)$_POST['party_contact_id']);

try {
  if (!$isEdit) {
    // Ensure a unique code if not provided
    if ($data['code'] === null) {
      $data['code'] = next_no('LEAD'); // e.g., LEAD-2025-0001
    }
    $sql = "INSERT INTO crm_leads
      (code, title, status, amount, is_hot, follow_up_on, notes, party_id, party_contact_id, created_at)
      VALUES
      (:code, :title, :status, :amount, :is_hot, :follow_up_on, :notes, :party_id, :party_contact_id, NOW())";
    $st = $pdo->prepare($sql);
    $st->execute($data);
    $newId = (int)$pdo->lastInsertId();
    audit_log($pdo, 'crm_leads', 'create', $newId, $data);
    set_flash('success', 'Lead created.');
    header('Location: crm_leads_form.php?id='.$newId); exit;

  } else {
    // For updates: if code was cleared in UI, regenerate a new one
    if ($data['code'] === null) {
      // Load current code; if it was already present, keep it, else generate
      $cur = $pdo->prepare("SELECT code FROM crm_leads WHERE id=?");
      $cur->execute([$id]);
      $curCode = (string)($cur->fetchColumn() ?: '');
      if ($curCode === '') {
        $data['code'] = next_no('LEAD');
      } else {
        $data['code'] = $curCode; // keep existing non-empty code
      }
    }
    $data['id'] = $id;
    $sql = "UPDATE crm_leads SET
      code=:code, title=:title, status=:status, amount=:amount, is_hot=:is_hot,
      follow_up_on=:follow_up_on, notes=:notes, party_id=:party_id, party_contact_id=:party_contact_id
      WHERE id=:id";
    $st = $pdo->prepare($sql);
    $st->execute($data);
    audit_log($pdo, 'crm_leads', 'update', $id, $data);
    set_flash('success', 'Lead updated.');
    header('Location: crm_leads_form.php?id='.$id); exit;
  }
} catch (Throwable $e) {
  set_flash('danger', $e->getMessage());
  header('Location: crm_leads_form.php'.($isEdit?('?id='.$id):'')); exit;
}