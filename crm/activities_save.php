<?php
/** PATH: /public_html/crm/activities_save.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/numbering.php';

require_login(); csrf_require_token();

$id = (int)($_POST['id'] ?? 0);
$isEdit = $id > 0;

/* quick action from list: mark completed */
if (isset($_POST['quick']) && $_POST['quick']==='complete') {
  require_permission('crm.activity.edit');
  $pdo = db();
  $pdo->prepare("UPDATE crm_activities SET status='Completed', updated_at=NOW() WHERE id=:id AND deleted_at IS NULL")
      ->execute([':id'=>$id]);
  flash('Activity marked completed','success');
  redirect('/crm/activities_list.php');
}

require_permission($isEdit ? 'crm.activity.edit' : 'crm.activity.create');

$type     = (string)($_POST['type'] ?? 'Task');
$subject  = trim((string)($_POST['subject'] ?? ''));
$due_at   = (string)($_POST['due_at'] ?? '');
$status   = (string)($_POST['status'] ?? 'Open');
$priority = (string)($_POST['priority'] ?? 'Normal');
$account_id = (int)($_POST['account_id'] ?? 0) ?: null;
$contact_id = (int)($_POST['contact_id'] ?? 0) ?: null;
$lead_id    = (int)($_POST['lead_id'] ?? 0) ?: null;
$owner_id   = (int)($_POST['owner_id'] ?? current_user_id());
$notes    = (string)($_POST['notes'] ?? '');
$next_fu  = (string)($_POST['next_followup_at'] ?? '');

$pdo = db();
$pdo->beginTransaction();
try {
  if ($isEdit) {
    $pdo->prepare("UPDATE crm_activities SET
        type=:type, subject=:subject, due_at=:due_at, status=:status, priority=:priority,
        account_id=:account_id, contact_id=:contact_id, lead_id=:lead_id, owner_id=:owner_id,
        notes=:notes, next_followup_at=:next_followup_at, updated_at=NOW()
      WHERE id=:id AND deleted_at IS NULL")
      ->execute([
        ':type'=>$type, ':subject'=>$subject, ':due_at'=>$due_at, ':status'=>$status, ':priority'=>$priority,
        ':account_id'=>$account_id, ':contact_id'=>$contact_id, ':lead_id'=>$lead_id, ':owner_id'=>$owner_id,
        ':notes'=>$notes, ':next_followup_at'=>($next_fu ?: null), ':id'=>$id
      ]);
  } else {
    $code = next_no('ACT'); // numbering series e.g. ACT-YYYY-####
    $pdo->prepare("INSERT INTO crm_activities
      (code,type,subject,due_at,status,priority,account_id,contact_id,lead_id,owner_id,notes,next_followup_at,created_at,updated_at)
      VALUES
      (:code,:type,:subject,:due_at,:status,:priority,:account_id,:contact_id,:lead_id,:owner_id,:notes,:next_followup_at,NOW(),NOW())")
      ->execute([
        ':code'=>$code, ':type'=>$type, ':subject'=>$subject, ':due_at'=>$due_at, ':status'=>$status, ':priority'=>$priority,
        ':account_id'=>$account_id, ':contact_id'=>$contact_id, ':lead_id'=>$lead_id, ':owner_id'=>$owner_id,
        ':notes'=>$notes, ':next_followup_at'=>($next_fu ?: null)
      ]);
    $id = (int)$pdo->lastInsertId();
  }
  $pdo->commit();
  flash('Activity saved','success');
  redirect('/crm/activities_edit.php?id='.$id);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash('Save failed: '.h($e->getMessage()), 'danger');
  redirect($isEdit ? '/crm/activities_edit.php?id='.$id : '/crm/activities_edit.php');
}
