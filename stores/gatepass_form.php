<?php
/** PATH: /public_html/stores/gatepass_form.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.gatepass.manage');

$pdo = db();
$warehouses = $pdo->query("SELECT id, code, name FROM warehouses WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$projects   = $pdo->query("SELECT id, code, name FROM projects ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$parties    = $pdo->query("SELECT id, code, name FROM parties ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$itemsRaw   = $pdo->query("SELECT id, material_code, name, uom_id FROM items WHERE status='active' ORDER BY name LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);

/* Machines: use your schema.
   PK: machines.id (int), Code: machines.machine_id (varchar), Name: machines.name */
$machines = $pdo->query("SELECT id, machine_id, name FROM machines WHERE status IN ('active','in_service') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$uoms = $pdo->query("SELECT id, code, name FROM uom ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Create Gate Pass";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <a href="gatepass_list.php" class="btn btn-sm btn-outline-secondary">Back</a>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <label class="form-label">Type</label>
      <select id="gp_type" class="form-select form-select-sm">
        <option value="site">Site</option>
        <option value="jobwork">Jobwork</option>
        <option value="maintenance">Maintenance</option>
        <option value="scrap">Scrap</option>
        <option value="correction">Correction</option>
      </select>
    </div>
    <div class="col-md-2 d-flex align-items-center">
      <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" id="returnable">
        <label class="form-check-label" for="returnable">Returnable</label>
      </div>
    </div>
    <div class="col-md-3">
      <label class="form-label">Source Warehouse</label>
      <select id="src_wh" class="form-select form-select-sm" required>
        <option value="">—</option>
        <?php foreach ($warehouses as $w): ?>
          <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['code'].' — '.$w['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 d-none" id="dst_wh_wrap">
      <label class="form-label">Destination Warehouse</label>
      <select id="dst_wh" class="form-select form-select-sm">
        <option value="">—</option>
        <?php foreach ($warehouses as $w): ?>
          <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['code'].' — '.$w['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row g-3 mb-3 d-none" id="party_wrap">
    <div class="col-md-6">
      <label class="form-label">Party</label>
      <select id="party_id" class="form-select form-select-sm">
        <option value="">—</option>
        <?php foreach ($parties as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['code'].' — '.$p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <label class="form-label">Vehicle No</label>
      <input type="text" id="vehicle_no" class="form-control form-control-sm" maxlength="40">
    </div>
    <div class="col-md-3">
      <label class="form-label">Contact Person</label>
      <input type="text" id="contact_person" class="form-control form-control-sm" maxlength="120">
    </div>
    <div class="col-md-3">
      <label class="form-label">Contact Phone</label>
      <input type="text" id="contact_phone" class="form-control form-control-sm" maxlength="32">
    </div>
    <div class="col-md-3">
      <label class="form-label">Expected Return</label>
      <input type="date" id="expected_return_date" class="form-control form-control-sm">
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <label class="form-label">Project</label>
      <select id="project_id" class="form-select form-select-sm">
        <option value="">—</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['code'].' — '.$p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Remarks</label>
      <input type="text" id="remarks" class="form-control form-control-sm" maxlength="255">
    </div>
  </div>

  <h5 class="mt-4">Lines</h5>
  <div class="table-responsive mb-3">
    <table class="table table-sm align-middle" id="itemsTable">
      <thead class="table-light">
        <tr>
          <th style="width:140px">Line Type</th>
          <th class="mat">Item</th>
          <th class="mat" style="width:100px">UOM</th>
          <th class="mat" style="width:120px" class="text-end">Qty</th>
          <th class="asset d-none">Machine</th>
          <th>Remarks</th>
          <th style="width:40px"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
  <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addRow">+ Add Line</button>

  <div class="text-end">
    <button id="saveBtn" class="btn btn-primary btn-sm">Save Gate Pass</button>
  </div>
</div>

<script>
const ITEMS = <?= json_encode($itemsRaw) ?>;
const UOMS  = <?= json_encode($uoms) ?>;
const MACH  = <?= json_encode($machines) ?>;

function itemOptions(){
  return '<option value="">—</option>' + ITEMS.map(i=>`<option value="${i.id}">${(i.material_code||'')} — ${i.name}</option>`).join('');
}
function uomOptions(){
  return '<option value="">—</option>' + UOMS.map(u=>`<option value="${u.id}">${u.code||u.name}</option>`).join('');
}
function machineOptions(){
  return '<option value="">—</option>' + MACH.map(m=>`<option value="${m.id}">${m.machine_id} — ${m.name}</option>`).join('');
}
function addRow(){
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <select class="form-select form-select-sm line_type">
        <option value="material" selected>Material</option>
        <option value="asset">Machine</option>
      </select>
    </td>
    <td class="mat"><select class="form-select form-select-sm item_id">${itemOptions()}</select></td>
    <td class="mat"><select class="form-select form-select-sm uom_id">${uomOptions()}</select></td>
    <td class="mat"><input type="number" min="0" step="0.001" class="form-control form-control-sm text-end qty"></td>
    <td class="asset d-none"><select class="form-select form-select-sm machine_id">${machineOptions()}</select></td>
    <td><input type="text" class="form-control form-control-sm line_remarks" maxlength="150"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
  `;
  document.querySelector('#itemsTable tbody').appendChild(tr);
  tr.querySelector('.line_type').addEventListener('change', ()=>{
    const isAsset = tr.querySelector('.line_type').value==='asset';
    tr.querySelectorAll('.mat').forEach(td=>td.classList.toggle('d-none', isAsset));
    tr.querySelectorAll('.asset').forEach(td=>td.classList.toggle('d-none', !isAsset));
  });
}
document.getElementById('addRow').addEventListener('click', addRow);
document.querySelector('#itemsTable tbody').addEventListener('click', e=>{
  if (e.target.classList.contains('delRow')) e.target.closest('tr').remove();
});
addRow();

function toggleFields(){
  const type = document.getElementById('gp_type').value;
  const ret = document.getElementById('returnable').checked;
  document.getElementById('dst_wh_wrap').classList.toggle('d-none', !(type==='site' && !ret));
  document.getElementById('party_wrap').classList.toggle('d-none', !(type==='jobwork'||type==='maintenance'));
}
document.getElementById('gp_type').addEventListener('change', toggleFields);
document.getElementById('returnable').addEventListener('change', toggleFields);
toggleFields();

document.getElementById('saveBtn').addEventListener('click', async ()=>{
  const btn = document.getElementById('saveBtn'); btn.disabled=true; btn.textContent='Saving…';
  try{
    const gp_type = document.getElementById('gp_type').value;
    const returnable = document.getElementById('returnable').checked ? 1 : 0;
    const src = parseInt(document.getElementById('src_wh').value||'0',10);
    const dst = parseInt(document.getElementById('dst_wh').value||'0',10);
    const party_id = parseInt(document.getElementById('party_id')?.value||'0',10)||null;
    const project_id = parseInt(document.getElementById('project_id')?.value||'0',10)||null;
    const payload = {
      gp_type, returnable,
      source_warehouse_id: src,
      dest_warehouse_id: (gp_type==='site' && !returnable) ? (dst||null) : null,
      party_id, project_id,
      remarks: document.getElementById('remarks').value.trim(),
      vehicle_no: document.getElementById('vehicle_no').value.trim(),
      contact_person: document.getElementById('contact_person').value.trim(),
      contact_phone: document.getElementById('contact_phone').value.trim(),
      expected_return_date: document.getElementById('expected_return_date').value || null,
      items: []
    };
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr=>{
      const type = tr.querySelector('.line_type').value;
      if (type==='asset') {
        const machine_id = parseInt(tr.querySelector('.machine_id')?.value||'0',10);
        const lrmk = tr.querySelector('.line_remarks').value.trim();
        if (machine_id>0) payload.items.push({is_asset:1, machine_id, remarks:lrmk});
      } else {
        const item_id = parseInt(tr.querySelector('.item_id')?.value||'0',10);
        const uom_id  = parseInt(tr.querySelector('.uom_id')?.value||'0',10);
        const qty     = parseFloat(tr.querySelector('.qty')?.value||'0');
        const lrmk    = tr.querySelector('.line_remarks').value.trim();
        if (item_id>0 && uom_id>0 && qty>0) payload.items.push({is_asset:0, item_id, uom_id, qty, remarks:lrmk});
      }
    });
    if(!src){ alert('Source warehouse required'); return; }
    if(payload.items.length===0){ alert('Add at least one valid line'); return; }

    const resp=await fetch('_ajax/gp_create_post.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const raw=await resp.text(); const s=raw.indexOf('{'),e=raw.lastIndexOf('}'); const json=(s!=-1&&e!=-1&&e>s)?raw.slice(s,e+1):raw;
    const data=JSON.parse(json);
    if(data.ok){ alert('Gate Pass created: '+data.gp_no); location.href='gatepass_list.php'; }
    else alert('Save failed: '+(data.error||'Unknown'));
  }catch(e){ alert('Error: '+(e?.message||e)); }
  finally{ btn.disabled=false; btn.textContent='Save Gate Pass'; }
});
</script>
<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
