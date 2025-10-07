<?php
/** PATH: /public_html/crm/activities_edit.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
require_permission($isEdit ? 'crm.activity.edit' : 'crm.activity.create');

$pdo = db();

/* Load activity (if editing) */
$act = [
  'id'=>0,'code'=>'','type'=>'Task','subject'=>'','due_at'=>date('Y-m-d 10:00:00'),
  'status'=>'Open','priority'=>'Normal',
  'lead_id'=>null,'account_id'=>null,'contact_id'=>null,
  'owner_id'=>current_user_id(),'notes'=>'','next_followup_at'=>null
];
if ($isEdit) {
  $st=$pdo->prepare("SELECT * FROM crm_activities WHERE id=:id AND deleted_at IS NULL");
  $st->execute([':id'=>$id]);
  $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row){ flash('Activity not found','danger'); redirect('/crm/activities_list.php'); }
  $act = $row;
}

/* Dropdown data (no AJAX) */
$leads = [];
$ls = $pdo->query("
  SELECT l.id, l.code, l.title, l.party_id, l.party_contact_id,
         COALESCE(p.name,'') AS party_name
  FROM crm_leads l
  LEFT JOIN parties p ON p.id = l.party_id
  WHERE l.deleted_at IS NULL
  ORDER BY l.id DESC
  LIMIT 500
");
if ($ls) $leads = $ls->fetchAll(PDO::FETCH_ASSOC);

$parties = [];
$ps = $pdo->query("
  SELECT id, name, code
  FROM parties
  WHERE deleted_at IS NULL
  ORDER BY name ASC
  LIMIT 500
");
if ($ps) $parties = $ps->fetchAll(PDO::FETCH_ASSOC);

$users = [];
$us=$pdo->query("SELECT id, name FROM users WHERE status='active' ORDER BY name ASC");
if ($us) $users = $us->fetchAll(PDO::FETCH_ASSOC);

/* Helper: current labels */
function lead_label(array $l): string {
  $label = trim(($l['code'] ?? '').' '.($l['title'] ?? ''));
  if (!empty($l['party_name'])) $label .= ' — '.$l['party_name'];
  return $label;
}

$UI_PATH = dirname(__DIR__).'/ui';
$PAGE_TITLE = $isEdit ? ('Edit Activity '.$act['code']) : 'New Activity';
$ACTIVE_MENU='crm.activities';
require_once $UI_PATH.'/init.php';
require_once $UI_PATH.'/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0"><?=$PAGE_TITLE?></h3>
  <div class="btn-group">
    <a class="btn btn-sm btn-outline-secondary" href="/crm/activities_list.php">Back to list</a>
    <?php if ($isEdit): ?>
      <form action="/crm/activities_save.php" method="post" class="d-inline">
        <?=csrf_field()?><input type="hidden" name="id" value="<?=$act['id']?>"><input type="hidden" name="quick" value="complete">
        <button class="btn btn-sm btn-success">Mark Completed</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<form action="/crm/activities_save.php" method="post" autocomplete="off">
  <?=csrf_field()?>
  <input type="hidden" name="id" value="<?=$act['id']?>">

  <div class="row g-3">
    <div class="col-md-2">
      <label class="form-label">Code</label>
      <input class="form-control" value="<?=h($act['code'])?>" disabled>
    </div>
    <div class="col-md-2">
      <label class="form-label">Type</label>
      <select name="type" class="form-select" required>
        <?php foreach (['Task','Call','Meeting'] as $t): ?>
          <option value="<?=$t?>" <?=$t===$act['type']?'selected':''?>><?=$t?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Subject</label>
      <input name="subject" class="form-control" value="<?=h($act['subject'])?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Due At</label>
      <input name="due_at" type="datetime-local" class="form-control" value="<?=h(str_replace(' ','T',(string)$act['due_at']))?>" required>
    </div>

    <div class="col-md-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select" required>
        <?php foreach (['Open','In Progress','Completed','Canceled'] as $s): ?>
          <option value="<?=$s?>" <?=$s===$act['status']?'selected':''?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Priority</label>
      <select name="priority" class="form-select" required>
        <?php foreach (['Low','Normal','High','Urgent'] as $p): ?>
          <option value="<?=$p?>" <?=$p===$act['priority']?'selected':''?>><?=$p?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Lead dropdown -->
    <div class="col-md-6">
      <label class="form-label">Lead</label>
      <select name="lead_id" id="lead_id" class="form-select">
        <option value="">— Select Lead —</option>
        <?php foreach ($leads as $l): ?>
          <option
            value="<?=$l['id']?>"
            data-party-id="<?=$l['party_id']?>"
            data-party-name="<?=h($l['party_name'])?>"
            data-contact-id="<?=$l['party_contact_id']?>"
            <?=$act['lead_id']==$l['id']?'selected':''?>
          ><?=h(lead_label($l))?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Picking a lead will auto-fill Client and Contact.</div>
    </div>

    <!-- Client (Party) dropdown -->
    <div class="col-md-6">
      <label class="form-label">Client (Party)</label>
      <select name="account_id" id="party_id" class="form-select">
        <option value="">— Select Client —</option>
        <?php foreach ($parties as $p): ?>
          <option value="<?=$p['id']?>" <?=$act['account_id']==$p['id']?'selected':''?>>
            <?=h(($p['code']?($p['code'].' — '):'').$p['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Contact ID -->
    <div class="col-md-3">
      <label class="form-label">Contact ID</label>
      <input name="contact_id" id="contact_id" type="number" class="form-control" value="<?=h((string)$act['contact_id'])?>">
    </div>

    <!-- Owner dropdown -->
    <div class="col-md-3">
      <label class="form-label">Owner</label>
      <select name="owner_id" class="form-select" required>
        <?php foreach ($users as $u): ?>
          <option value="<?=$u['id']?>" <?=$act['owner_id']==$u['id']?'selected':''?>><?=h($u['name']).' (#'.$u['id'].')'?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-12">
      <label class="form-label">Notes</label>
      <textarea name="notes" class="form-control" rows="3"><?=h((string)$act['notes'])?></textarea>
    </div>
    <div class="col-md-4">
      <label class="form-label">Next Follow-up At</label>
      <input name="next_followup_at" type="datetime-local" class="form-control" value="<?=h($act['next_followup_at'] ? str_replace(' ','T',(string)$act['next_followup_at']) : '')?>">
    </div>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary"><?=$isEdit?'Update Activity':'Create Activity'?></button>
    <?php if ($isEdit): ?>
      <form action="/crm/activities_delete.php" method="post" onsubmit="return confirm('Delete activity?');">
        <?=csrf_field()?><input type="hidden" name="id" value="<?=$act['id']?>">
        <button class="btn btn-outline-danger">Delete</button>
      </form>
    <?php endif; ?>
  </div>
</form>

<script>
/* When Lead changes, copy party/contact from the selected option into the fields */
document.getElementById('lead_id').addEventListener('change', function(){
  const opt = this.options[this.selectedIndex];
  const partyId   = opt.getAttribute('data-party-id') || '';
  const contactId = opt.getAttribute('data-contact-id') || '';

  if (partyId) {
    const partySel = document.getElementById('party_id');
    [...partySel.options].forEach(o => { if (o.value === partyId) o.selected = true; });
  }
  if (contactId) {
    document.getElementById('contact_id').value = contactId;
  }
});
</script>
<?php require_once $UI_PATH.'/layout_end.php';
