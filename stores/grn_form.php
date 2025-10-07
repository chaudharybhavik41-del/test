<?php
/** PATH: /public_html/stores/grn_form.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.grn.manage');

$pdo = db();

// suppliers (adjust table/cols if yours differ)
try { $sup = $pdo->query("SELECT id, name FROM suppliers WHERE 1 ORDER BY name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC); }
catch(Throwable $e){ $sup = []; }
// warehouses
$wh = $pdo->query("SELECT id, code, name FROM warehouses WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
// items (active only to keep short)
try { $items = $pdo->query("SELECT id, material_code, name FROM items WHERE status='active' ORDER BY name LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC); }
catch(Throwable $e){ $items = $pdo->query("SELECT id, material_code, name FROM items ORDER BY name LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC); }
// uom
try { $uom = $pdo->query("SELECT id, COALESCE(code,name) label FROM uom ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); }
catch(Throwable $e){ $uom = []; }

$page_title = "New GRN";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <a href="requisitions_list.php" class="btn btn-outline-secondary btn-sm">Back</a>
  </div>

  <form id="grnForm" class="card card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Supplier</label>
        <select id="supplier_id" class="form-select form-select-sm">
          <option value="">— select —</option>
          <?php foreach ($sup as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Warehouse</label>
        <select id="warehouse_id" class="form-select form-select-sm">
          <?php foreach ($wh as $w): ?>
            <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['code'].' — '.$w['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Challan / Ref</label>
        <input type="text" id="challan_no" class="form-control form-control-sm" maxlength="80" placeholder="supplier ref">
      </div>
    </div>

    <hr>

    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="mb-0">Items</h6>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addRow"><i class="bi bi-plus-lg"></i> Add row</button>
    </div>

    <div class="table-responsive">
      <table class="table table-sm align-middle" id="itemsTable">
        <thead class="table-light">
          <tr>
            <th style="width:35%">Item</th>
            <th style="width:10%">UOM</th>
            <th style="width:13%" class="text-end">Qty Recv</th>
            <th style="width:13%" class="text-end">Qty Accept</th>
            <th style="width:14%" class="text-end">Unit Price</th>
            <th>Remarks</th>
            <th style="width:40px"></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="text-end">
      <button type="button" id="postBtn" class="btn btn-primary btn-sm">Post GRN</button>
    </div>
  
<!-- Insert in GRN form header: Owner selector -->
<div class="row g-2">
  <div class="col-md-3">
    <label class="form-label">Owner</label>
    <select name="owner_mode" id="owner_mode" class="form-select">
      <option value="company" selected>Our Material</option>
      <option value="customer">Customer / Party Material</option>
      <option value="vendor_foc">Vendor FOC</option>
    </select>
  </div>
  <div class="col-md-4 owner-customer d-none">
    <label class="form-label">Customer</label>
    <select name="customer_id" id="customer_id" class="form-select"><!-- populate with customers --></select>
  </div>
  <div class="col-md-3 owner-foc d-none">
    <label class="form-label">FOC Policy</label>
    <select name="foc_policy" id="foc_policy" class="form-select">
      <option value="zero" selected>Zero Cost</option>
      <option value="fair_value">Fair Value (Other Income)</option>
      <option value="standard">Standard Cost</option>
    </select>
  </div>
</div>
<script>
document.getElementById('owner_mode').addEventListener('change', function(){
  const mode = this.value;
  document.querySelector('.owner-customer').classList.toggle('d-none', mode!=='customer');
  document.querySelector('.owner-foc').classList.toggle('d-none', mode!=='vendor_foc');
});
</script>
  </form>
</div>

<script>
const ITEMS = <?= json_encode($items) ?>;
const UOM   = <?= json_encode($uom) ?>;

function itemOpts(){ return '<option value="">— select —</option>' + ITEMS.map(i=>{
  const lbl = (i.material_code?i.material_code+' — ':'')+(i.name||'');
  return `<option value="${i.id}">${escapeHtml(lbl)}</option>`;
}).join(''); }
function uomOpts(){ return '<option value="">—</option>' + UOM.map(u=>`<option value="${u.id}">${escapeHtml(u.label||'UOM')}</option>`).join(''); }
function escapeHtml(s){return (s??'').replace(/[&<>"']/g,m=>({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;" }[m]));}
function rowTpl(){
  return `<tr>
    <td><select class="form-select form-select-sm item_id">${itemOpts()}</select></td>
    <td><select class="form-select form-select-sm uom_id">${uomOpts()}</select></td>
    <td><input class="form-control form-control-sm qty_recv text-end" type="number" step="0.001" min="0"></td>
    <td><input class="form-control form-control-sm qty_acc  text-end" type="number" step="0.001" min="0"></td>
    <td><input class="form-control form-control-sm unit_price text-end" type="number" step="0.0001" min="0"></td>
    <td><input class="form-control form-control-sm line_remarks" type="text" maxlength="150"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
  </tr>`;
}
document.getElementById('addRow').addEventListener('click', ()=>{ document.querySelector('#itemsTable tbody').insertAdjacentHTML('beforeend', rowTpl()); });
document.querySelector('#itemsTable tbody').addEventListener('click', e=>{ if(e.target.classList.contains('delRow')) e.target.closest('tr').remove(); });
document.getElementById('addRow').click();

document.getElementById('postBtn').addEventListener('click', async ()=>{
  const btn = document.getElementById('postBtn'); btn.disabled=true; btn.textContent='Posting…';
  try{
    const supplier_id = parseInt(document.getElementById('supplier_id').value||'0',10);
    const warehouse_id= parseInt(document.getElementById('warehouse_id').value||'0',10);
    const challan_no  = (document.getElementById('challan_no').value||'').trim();

    const lines=[];
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr=>{
      const item_id = parseInt(tr.querySelector('.item_id').value||'0',10);
      const uom_id  = parseInt(tr.querySelector('.uom_id').value||'0',10);
      const qty_r   = parseFloat(tr.querySelector('.qty_recv').value||'0');
      const qty_a   = parseFloat(tr.querySelector('.qty_acc').value||'0');
      const price   = parseFloat(tr.querySelector('.unit_price').value||'0');
      const rm      = (tr.querySelector('.line_remarks').value||'').trim();
      if (item_id>0 && uom_id>0 && qty_a>0) {
        lines.push({item_id,uom_id,qty_received:qty_r,qty_accepted:qty_a,unit_price:price,remarks:rm});
      }
    });

    if(!supplier_id){ alert('Select supplier'); return; }
    if(!warehouse_id){ alert('Select warehouse'); return; }
    if(lines.length===0){ alert('Add at least one accepted line'); return; }

    const r = await fetch('_ajax/grn_post.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({supplier_id, warehouse_id, challan_no, items: lines})
    });
    const raw = await r.text();
    const s = raw.indexOf('{'); const e = raw.lastIndexOf('}');
    const json = (s!==-1 && e!==-1) ? raw.slice(s,e+1) : raw;
    let data; try{ data = JSON.parse(json);}catch(_){ alert('Server error: '+raw.slice(0,400)); return; }

    if(data.ok){ alert('GRN posted: '+data.grn_no); location.href='requisitions_list.php'; }
    else { alert('Post failed: '+(data.error||'Unknown')); }
  }finally{ btn.disabled=false; btn.textContent='Post GRN'; }
});
</script>
<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
