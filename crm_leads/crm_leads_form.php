<?php
/** PATH: /public_html/crm_leads/crm_leads_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
require_permission($isEdit ? 'crm.lead.edit' : 'crm.lead.create');

$pdo = db();

// Defaults
$row = [
  'code' => '',
  'title' => '',
  'status' => 'New',
  'amount' => null,
  'is_hot' => 0,
  'follow_up_on' => null,
  'notes' => '',
  'party_id' => null,
  'party_contact_id' => null,
];

// Load existing
if ($isEdit) {
  $st = $pdo->prepare("SELECT * FROM crm_leads WHERE id = ? LIMIT 1");
  $st->execute([$id]);
  if ($dbrow = $st->fetch(PDO::FETCH_ASSOC)) {
    $row = array_merge($row, $dbrow);
  }
}

// Parties (clients)
$sqlClients = "SELECT id, code, name FROM parties WHERE 1=1";
$sqlClients .= " AND (status = 1 OR status IS NULL)";
$sqlClients .= " AND (type = 'client' OR type IS NULL)";
$sqlClients .= " ORDER BY name ASC";
$clients = $pdo->query($sqlClients)->fetchAll(PDO::FETCH_ASSOC);

// Contacts for selected party
$contacts = [];
if (!empty($row['party_id'])) {
  $st = $pdo->prepare("SELECT id, name, phone FROM party_contacts WHERE party_id=? ORDER BY is_primary DESC, name ASC");
  $st->execute([(int)$row['party_id']]);
  $contacts = $st->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../ui/layout_start.php';
render_flash();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0"><?= $isEdit ? 'Edit Lead' : 'New Lead' ?></h1>
  <div class="d-flex gap-2">
    <?php if ($isEdit): ?>
      <!-- One-click handoff to Quotation (prefills party/contact via lead_id) -->
      <a class="btn btn-outline-primary" href="<?= h('../sales_quotes/sales_quotes_form.php?lead_id='.$id) ?>">Create Quote</a>
    <?php endif; ?>
    <a href="<?= h('crm_leads_list.php') ?>" class="btn btn-outline-secondary">Back</a>
  </div>
</div>

<form method="post" action="<?= h('crm_leads_save.php') ?>" class="row g-3">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="col-md-3">
    <label class="form-label">Code</label>
    <input class="form-control" name="code" value="<?= h((string)$row['code']) ?>" placeholder="Auto if left blank">
  </div>
  <div class="col-md-6">
    <label class="form-label">Title</label>
    <input class="form-control" name="title" value="<?= h((string)$row['title']) ?>" required>
  </div>
  <div class="col-md-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <?php foreach (['New','Qualified','Quoted','Won','Lost'] as $s): ?>
        <option value="<?= h($s) ?>" <?= ((string)$row['status'] === $s ? 'selected' : '') ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-3">
    <label class="form-label">Amount</label>
    <input type="number" step="0.01" class="form-control" name="amount" value="<?= h((string)$row['amount']) ?>">
  </div>
  <div class="col-md-3 form-check mt-4">
    <input class="form-check-input" type="checkbox" id="is_hot" name="is_hot" <?= ((int)$row['is_hot'] ? 'checked' : '') ?>>
    <label class="form-check-label" for="is_hot">Hot</label>
  </div>
  <div class="col-md-3">
    <label class="form-label">Follow Up On</label>
    <input type="date" class="form-control" name="follow_up_on" value="<?= h((string)$row['follow_up_on']) ?>">
  </div>

  <div class="col-12">
    <label class="form-label">Notes</label>
    <textarea class="form-control" name="notes" rows="3"><?= h((string)$row['notes']) ?></textarea>
  </div>

  <!-- Client (Party) dropdown -->
  <div class="col-md-6">
    <label class="form-label">Client (Party)</label>
    <select name="party_id" id="party_id" class="form-select">
      <option value="">-- Select Client --</option>
      <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ((int)$row['party_id'] === (int)$c['id'] ? 'selected' : '') ?>>
          <?= h($c['name'] . ($c['code'] ? ' ('.$c['code'].')' : '')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Contact dropdown (auto-fills after client selection) -->
  <div class="col-md-6">
    <label class="form-label">Contact</label>
    <select name="party_contact_id" id="party_contact_id" class="form-select" <?= $row['party_id'] ? '' : 'disabled' ?>>
      <option value="">-- Select Contact --</option>
      <?php foreach ($contacts as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ((int)$row['party_contact_id'] === (int)$c['id'] ? 'selected' : '') ?>>
          <?= h($c['name'] . ($c['phone'] ? ' · '.$c['phone'] : '')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-12 d-flex gap-2 justify-content-end">
    <button class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?></button>
    <a class="btn btn-outline-secondary" href="<?= h('crm_leads_list.php') ?>">Cancel</a>
  </div>
</form>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>

<script>
document.getElementById('party_id')?.addEventListener('change', async function(){
  const pid = this.value;
  const sel = document.getElementById('party_contact_id');
  sel.innerHTML = '<option value="">-- Select Contact --</option>';
  if (!pid) { sel.disabled = true; return; }
  sel.disabled = false;
  try {
    const res = await fetch('../common/party_contacts.php?party_id='+encodeURIComponent(pid));
    const js = await res.json();
    if (js.ok) {
      sel.innerHTML = '<option value="">-- Select Contact --</option>';
      js.items.forEach(it => {
        const opt = document.createElement('option');
        opt.value = it.id;
        opt.textContent = it.name + (it.phone ? ' · ' + it.phone : '');
        sel.appendChild(opt);
      });
    }
  } catch(e){ console.error(e); }
});
</script>