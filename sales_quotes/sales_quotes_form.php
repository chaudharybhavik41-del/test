<?php
/** PATH: /public_html/sales_quotes/sales_quotes_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
require_permission($isEdit ? 'sales.quote.edit' : 'sales.quote.create');

$pdo = db();

/** ---------- helpers (table detection) ---------- */
function table_exists(PDO $pdo, string $name): bool {
  static $cache = [];
  if (array_key_exists($name, $cache)) return $cache[$name];
  $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $st->execute([$name]);
  return $cache[$name] = ((int)$st->fetchColumn() > 0);
}
function fetch_quote_items(PDO $pdo, int $quoteId): array {
  if ($quoteId <= 0) return [];
  // 1) sales_quote_items.quote_id
  if (table_exists($pdo, 'sales_quote_items')) {
    try {
      $st = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id=? ORDER BY sl_no, id");
      $st->execute([$quoteId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if ($rows) return $rows;
      // try sales_quote_id column (fallback)
      $st = $pdo->prepare("SELECT * FROM sales_quote_items WHERE sales_quote_id=? ORDER BY sl_no, id");
      $st->execute([$quoteId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if ($rows) return $rows;
    } catch (Throwable $e) {}
  }
  // 2) sales_quotes_items.quote_id (plural table)
  if (table_exists($pdo, 'sales_quotes_items')) {
    try {
      $st = $pdo->prepare("SELECT * FROM sales_quotes_items WHERE quote_id=? ORDER BY sl_no, id");
      $st->execute([$quoteId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if ($rows) return $rows;
      $st = $pdo->prepare("SELECT * FROM sales_quotes_items WHERE sales_quote_id=? ORDER BY sl_no, id");
      $st->execute([$quoteId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      if ($rows) return $rows;
    } catch (Throwable $e) {}
  }
  return [];
}

/** ---------- header defaults ---------- */
$row = [
  'code' => '',
  'quote_date' => date('Y-m-d'),
  'valid_till' => null,
  'status' => 'Draft',
  'party_id' => null,
  'party_contact_id' => null,
  'lead_id' => isset($_GET['lead_id']) ? (int)$_GET['lead_id'] : null,
  'currency' => 'INR',
  'notes' => '',
  'terms' => '',
  'subtotal' => '0.00',
  'discount_amount' => '0.00',
  'tax_amount' => '0.00',
  'round_off' => '0.00',
  'grand_total' => '0.00',
  // site address fields
  'site_name' => '',
  'site_address_line1' => '',
  'site_address_line2' => '',
  'site_city' => '',
  'site_state' => '',
  'site_pincode' => '',
  'site_gst_number' => '',
  // toggle
  'use_site_as_bill_to' => 0,
];

/** ---------- load existing header ---------- */
if ($isEdit) {
  $st = $pdo->prepare("SELECT * FROM sales_quotes WHERE id=? LIMIT 1");
  $st->execute([$id]);
  if ($dbrow = $st->fetch(PDO::FETCH_ASSOC)) $row = array_merge($row, $dbrow);
}

/** ---------- prefill from lead ---------- */
if (!$isEdit && !empty($row['lead_id'])) {
  $st = $pdo->prepare("SELECT party_id, party_contact_id, title FROM crm_leads WHERE id=?");
  $st->execute([(int)$row['lead_id']]);
  if ($lead = $st->fetch(PDO::FETCH_ASSOC)) {
    if (!empty($lead['party_id'])) $row['party_id'] = (int)$lead['party_id'];
    if (!empty($lead['party_contact_id'])) $row['party_contact_id'] = (int)$lead['party_contact_id'];
    if (empty($row['notes']) && !empty($lead['title'])) $row['notes'] = 'Ref: Lead - '.$lead['title'];
  }
}

/** ---------- clients & contacts ---------- */
$clients = $pdo->query("SELECT id, code, name FROM parties
  WHERE (status=1 OR status IS NULL) AND (type='client' OR type IS NULL)
  ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$contacts = [];
if (!empty($row['party_id'])) {
  $st = $pdo->prepare("SELECT id, name, phone FROM party_contacts WHERE party_id=? ORDER BY is_primary DESC, name");
  $st->execute([(int)$row['party_id']]);
  $contacts = $st->fetchAll(PDO::FETCH_ASSOC);
}

/** ---------- UOM (active) ---------- */
$uoms = $pdo->query("SELECT code, name FROM uom WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$uomOptionsHtml = '';
foreach ($uoms as $u) {
  $code = (string)$u['code'];
  $label = trim($u['code'].' - '.$u['name']);
  $uomOptionsHtml .= '<option value="'.h($code).'">'.h($label).'</option>';
}

/** ---------- Quote Items master (for dropdown) ---------- */
$qItems = $pdo->query("SELECT code, name, hsn_sac, uom, rate_default, tax_pct_default
                       FROM quote_items
                       WHERE deleted_at IS NULL AND is_active=1
                       ORDER BY name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$QI_OPTIONS_HTML = '<option value="">-- Pick Item --</option>';
$QI_MAP = [];
foreach ($qItems as $qi) {
  $code = (string)$qi['code'];
  $text = ($qi['code'] ? $qi['code'].' · ' : '').$qi['name'];
  $QI_OPTIONS_HTML .= '<option value="'.h($code).'">'.h($text).'</option>';
  $QI_MAP[$code] = [
    'name' => (string)$qi['name'],
    'hsn'  => (string)($qi['hsn_sac'] ?? ''),
    'uom'  => (string)($qi['uom'] ?? ''),
    'rate' => (string)number_format((float)($qi['rate_default'] ?? 0), 2, '.', ''),
    'tax'  => (string)number_format((float)($qi['tax_pct_default'] ?? 0), 2, '.', ''),
  ];
}

/** ---------- items for this quote (robust) ---------- */
$items = $isEdit ? fetch_quote_items($pdo, $id) : [];

include __DIR__ . '/../ui/layout_start.php';
render_flash();
?>
<style>
.item-suggest{position:absolute;z-index:1000;display:none;max-height:260px;overflow-y:auto;background:#fff;border:1px solid rgba(0,0,0,.15);border-radius:.5rem;box-shadow:0 .5rem 1rem rgba(0,0,0,.15)}
.item-cell{position:relative}
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0"><?= $isEdit ? 'Edit Quotation' : 'New Quotation' ?></h1>
  <div class="d-flex gap-2">
    <?php if ($isEdit): ?>
      <a class="btn btn-outline-secondary" target="_blank" href="<?= h('../prints/quote_print.php?id='.$id) ?>">Print</a>
      <?php if (has_permission('sales.order.create')): ?>
        <a class="btn btn-outline-primary" href="<?= h('convert_to_order.php?quote_id='.$id) ?>">Convert → Order</a>
      <?php endif; ?>
    <?php endif; ?>
    <a href="<?= h('sales_quotes_list.php') ?>" class="btn btn-outline-secondary">Back</a>
  </div>
</div>

<form method="post" action="<?= h('sales_quotes_save.php') ?>" id="quoteForm">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="row g-3">
    <div class="col-md-3">
      <label class="form-label">Quote No</label>
      <input class="form-control" name="code" value="<?= h((string)$row['code']) ?>" placeholder="Auto on save (QO)">
    </div>
    <div class="col-md-3">
      <label class="form-label">Quote Date</label>
      <input type="date" name="quote_date" class="form-control" value="<?= h((string)$row['quote_date']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Valid Till</label>
      <input type="date" name="valid_till" class="form-control" value="<?= h((string)$row['valid_till']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <?php foreach (['Draft','Sent','Accepted','Lost','Expired','Canceled'] as $s): ?>
          <option value="<?= h($s) ?>" <?= ($row['status']===$s?'selected':'') ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-6">
      <label class="form-label">Client (Party)</label>
      <select name="party_id" id="party_id" class="form-select">
        <option value="">-- Select Client --</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)$row['party_id']===(int)$c['id']?'selected':'') ?>>
            <?= h($c['name'].($c['code']?' ('.$c['code'].')':'')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Contact</label>
      <select name="party_contact_id" id="party_contact_id" class="form-select" <?= $row['party_id']?'':'disabled' ?>>
        <option value="">-- Select Contact --</option>
        <?php foreach ($contacts as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ((int)$row['party_contact_id']===(int)$c['id']?'selected':'') ?>>
            <?= h($c['name'].($c['phone']?' · '.$c['phone']:'')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-2">
      <label class="form-label">Currency</label>
      <input class="form-control" name="currency" value="<?= h((string)$row['currency']) ?>">
    </div>
    <div class="col-md-10">
      <label class="form-label">Notes (internal)</label>
      <input class="form-control" name="notes" value="<?= h((string)$row['notes']) ?>">
    </div>
    <div class="col-12">
      <label class="form-label">Terms (customer-visible)</label>
      <textarea class="form-control" name="terms" rows="3"><?= h((string)$row['terms']) ?></textarea>
    </div>
  </div>

  <hr class="my-3">
  <!-- Site Address Block (unchanged) -->
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Site Address</span>
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="copyClientAddr">Copy from Client</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearSiteAddr">Clear</button>
        <div class="form-check form-switch ms-2">
          <input class="form-check-input" type="checkbox" id="use_site_as_bill_to" name="use_site_as_bill_to" <?= ((int)$row['use_site_as_bill_to'] ? 'checked' : '') ?>>
          <label class="form-check-label" for="use_site_as_bill_to">Use Site as Bill To (for print & GST)</label>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Site / Attention</label><input class="form-control" name="site_name" id="site_name" value="<?= h((string)$row['site_name']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Site GSTIN (if any)</label><input class="form-control" name="site_gst_number" id="site_gst_number" value="<?= h((string)$row['site_gst_number']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Address Line 1</label><input class="form-control" name="site_address_line1" id="site_address_line1" value="<?= h((string)$row['site_address_line1']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Address Line 2</label><input class="form-control" name="site_address_line2" id="site_address_line2" value="<?= h((string)$row['site_address_line2']) ?>"></div>
        <div class="col-md-4"><label class="form-label">City</label><input class="form-control" name="site_city" id="site_city" value="<?= h((string)$row['site_city']) ?>"></div>
        <div class="col-md-4"><label class="form-label">State</label><input class="form-control" name="site_state" id="site_state" value="<?= h((string)$row['site_state']) ?>"></div>
        <div class="col-md-4"><label class="form-label">PIN Code</label><input class="form-control" name="site_pincode" id="site_pincode" value="<?= h((string)$row['site_pincode']) ?>"></div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Items</h2>
    <button class="btn btn-sm btn-outline-primary" type="button" id="addRow">+ Add Row</button>
  </div>

  <div class="table-responsive mt-2">
    <table class="table table-sm align-middle" id="itemsTable">
      <thead class="table-light">
        <tr>
          <th style="width:60px;">SL</th>
          <th style="min-width:320px">Item (dropdown) &amp; Description</th>
          <th style="width:120px;">HSN/SAC</th>
          <th style="width:120px;">Qty</th>
          <th style="width:140px;">UOM</th>
          <th style="width:140px;">Rate</th>
          <th style="width:120px;">Disc %</th>
          <th style="width:120px;">Tax %</th>
          <th style="width:140px;">Line Total</th>
          <th style="width:48px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($items): foreach ($items as $it):
          $uomSel = (string)($it['uom'] ?? 'Nos');
          $uomHtml = $uomOptionsHtml;
          if ($uomSel && !array_filter($uoms, fn($u)=>$u['code']===$uomSel)) {
            $uomHtml = '<option value="'.h($uomSel).'">'.h($uomSel).'</option>'.$uomHtml;
          }
        ?>
          <tr>
            <td><input type="hidden" name="item_id[]" value="<?= (int)$it['id'] ?>"><input type="number" class="form-control form-control-sm sl" name="sl_no[]" value="<?= (int)($it['sl_no'] ?? 1) ?>"></td>
            <td>
              <select class="form-select form-select-sm qi_select"><?= $QI_OPTIONS_HTML ?></select>
              <input type="hidden" name="item_code[]" class="qi_code" value="<?= h((string)($it['item_code'] ?? '')) ?>">
              <textarea class="form-control form-control-sm mt-1" name="item_name[]" rows="1" placeholder="Description"><?= h((string)($it['item_name'] ?? '')) ?></textarea>
            </td>
            <td><input class="form-control form-control-sm" name="hsn_sac[]" value="<?= h((string)($it['hsn_sac'] ?? '')) ?>"></td>
            <td><input class="form-control form-control-sm qty" name="qty[]" value="<?= h((string)($it['qty'] ?? '1.000')) ?>"></td>
            <td><select class="form-select form-select-sm" name="uom[]"><?= str_replace('value="'.h($uomSel).'"', 'value="'.h($uomSel).'" selected', $uomHtml) ?></select></td>
            <td><input class="form-control form-control-sm rate" name="rate[]" value="<?= h((string)($it['rate'] ?? '0.00')) ?>"></td>
            <td><input class="form-control form-control-sm disc" name="discount_pct[]" value="<?= h((string)($it['discount_pct'] ?? '0.00')) ?>"></td>
            <td><input class="form-control form-control-sm tax" name="tax_pct[]" value="<?= h((string)($it['tax_pct'] ?? '0.00')) ?>"></td>
            <td><input class="form-control form-control-sm line_total" name="line_total[]" value="<?= h((string)($it['line_total'] ?? '0.00')) ?>" readonly></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="row g-3 justify-content-end mt-2">
    <div class="col-md-3"><label class="form-label">Subtotal</label><input class="form-control" name="subtotal" id="subtotal" value="<?= h((string)$row['subtotal']) ?>" readonly></div>
    <div class="col-md-3"><label class="form-label">Discount (absolute)</label><input class="form-control" name="discount_amount" id="discount_amount" value="<?= h((string)$row['discount_amount']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Tax (total)</label><input class="form-control" name="tax_amount" id="tax_amount" value="<?= h((string)$row['tax_amount']) ?>" readonly></div>
    <div class="col-md-3"><label class="form-label">Round Off</label><input class="form-control" name="round_off" id="round_off" value="<?= h((string)$row['round_off']) ?>"></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Grand Total</label><input class="form-control" name="grand_total" id="grand_total" value="<?= h((string)$row['grand_total']) ?>" readonly></div>
  </div>

  <div class="d-flex gap-2 justify-content-end mt-3">
    <button class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?></button>
  </div>
</form>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>

<script>
const QI_MAP = <?= json_encode($QI_MAP, JSON_UNESCAPED_UNICODE) ?>;
const QI_OPTIONS_HTML = <?= json_encode($QI_OPTIONS_HTML) ?>;
const UOM_OPTIONS_HTML = <?= json_encode($uomOptionsHtml) ?>;

function recalcRow(tr){
  const qty = parseFloat(tr.querySelector('.qty')?.value || '0') || 0;
  const rate = parseFloat(tr.querySelector('.rate')?.value || '0') || 0;
  const disc = parseFloat(tr.querySelector('.disc')?.value || '0') || 0;
  const tax = parseFloat(tr.querySelector('.tax')?.value || '0') || 0;
  const base = qty * rate;
  const afterDisc = base * (1 - (disc/100));
  const lineTax = afterDisc * (tax/100);
  const lineTotal = afterDisc + lineTax;
  tr.querySelector('.line_total').value = lineTotal.toFixed(2);
  return {afterDisc,lineTax};
}
function recalcAll(){
  let sub=0,tx=0;
  document.querySelectorAll('#itemsTable tbody tr').forEach(tr=>{
    const r=recalcRow(tr); sub+=r.afterDisc; tx+=r.lineTax;
  });
  document.getElementById('subtotal').value = sub.toFixed(2);
  document.getElementById('tax_amount').value = tx.toFixed(2);
  const discAbs=parseFloat(document.getElementById('discount_amount').value||'0')||0;
  const roff=parseFloat(document.getElementById('round_off').value||'0')||0;
  document.getElementById('grand_total').value = (sub-discAbs+tx+roff).toFixed(2);
}
function attachRowHandlers(tr){
  const sel = tr.querySelector('.qi_select');
  if (sel && !sel.dataset.wired){
    sel.dataset.wired='1';
    // select correct option if hidden code already present
    const cur = tr.querySelector('.qi_code')?.value || '';
    if (cur){
      [...sel.options].forEach(o => { if (o.value===cur) o.selected=true; });
    }
    sel.addEventListener('change',()=>{
      const code = sel.value || '';
      const data = QI_MAP[code] || null;
      tr.querySelector('.qi_code')?.setAttribute('value', code);
      if (data){
        const desc = tr.querySelector('textarea[name="item_name[]"]');
        if (desc && (!desc.value || desc.value.trim()==='')) desc.value = data.name;
        const hsn = tr.querySelector('input[name="hsn_sac[]"]'); if (hsn) hsn.value = data.hsn || '';
        const uomSel = tr.querySelector('select[name="uom[]"]');
        if (uomSel){
          const wanted = data.uom || '';
          if (wanted){
            if (!uomSel.querySelector('option[value="'+wanted+'"]')){
              const opt=document.createElement('option'); opt.value=wanted; opt.textContent=wanted; uomSel.insertBefore(opt,uomSel.firstChild);
            }
            uomSel.value = wanted;
          }
        }
        const rate = tr.querySelector('.rate'); if (rate) rate.value = parseFloat(data.rate||'0').toFixed(2);
        const tax  = tr.querySelector('.tax');  if (tax)  tax.value  = parseFloat(data.tax ||'0').toFixed(2);
        recalcAll();
      }
    });
  }
  tr.addEventListener('input', e => { if (e.target.matches('.qty,.rate,.disc,.tax')) recalcAll(); });
}
document.getElementById('itemsTable').addEventListener('click', e=>{
  if (e.target.closest('.delRow')) { e.target.closest('tr').remove(); recalcAll(); }
});
document.getElementById('addRow')?.addEventListener('click', ()=>{
  const tb=document.querySelector('#itemsTable tbody');
  const tr=document.createElement('tr');
  const sl=tb.querySelectorAll('tr').length+1;
  tr.innerHTML=`
    <td><input type="hidden" name="item_id[]" value="0"><input type="number" class="form-control form-control-sm sl" name="sl_no[]" value="${sl}"></td>
    <td>
      <select class="form-select form-select-sm qi_select">${QI_OPTIONS_HTML}</select>
      <input type="hidden" name="item_code[]" class="qi_code" value="">
      <textarea class="form-control form-control-sm mt-1" name="item_name[]" rows="1" placeholder="Description"></textarea>
    </td>
    <td><input class="form-control form-control-sm" name="hsn_sac[]"></td>
    <td><input class="form-control form-control-sm qty" name="qty[]" value="1.000"></td>
    <td><select class="form-select form-select-sm" name="uom[]"><?= $uomOptionsHtml ?></select></td>
    <td><input class="form-control form-control-sm rate" name="rate[]" value="0.00"></td>
    <td><input class="form-control form-control-sm disc" name="discount_pct[]" value="0.00"></td>
    <td><input class="form-control form-control-sm tax" name="tax_pct[]" value="0.00"></td>
    <td><input class="form-control form-control-sm line_total" name="line_total[]" value="0.00" readonly></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>`;
  tb.appendChild(tr); attachRowHandlers(tr);
});
document.querySelectorAll('#itemsTable tbody tr').forEach(attachRowHandlers);
document.getElementById('discount_amount')?.addEventListener('input', recalcAll);
document.getElementById('round_off')?.addEventListener('input', recalcAll);
recalcAll();

// Party → Contacts
document.getElementById('party_id')?.addEventListener('change', async function(){
  const pid=this.value, sel=document.getElementById('party_contact_id');
  sel.innerHTML='<option value="">-- Select Contact --</option>';
  if (!pid){ sel.disabled=true; return; }
  sel.disabled=false;
  try{
    const res=await fetch('../common/party_contacts.php?party_id='+encodeURIComponent(pid));
    const js=await res.json();
    if(js.ok){ js.items.forEach(it=>{ const o=document.createElement('option'); o.value=it.id; o.textContent=it.name+(it.phone?' · '+it.phone:''); sel.appendChild(o); }); }
  }catch(e){ console.error(e); }
});
// Copy client address → site address
document.getElementById('copyClientAddr')?.addEventListener('click', async ()=>{
  const pid=document.getElementById('party_id').value; if(!pid) return;
  try{
    const res=await fetch('../common/party_get.php?id='+encodeURIComponent(pid));
    const js=await res.json();
    if(js.ok && js.party){
      site_name.value=js.party.legal_name || js.party.name || '';
      site_gst_number.value=js.party.gst_number || '';
      site_address_line1.value=js.party.address_line1 || '';
      site_address_line2.value=js.party.address_line2 || '';
      site_city.value=js.party.city || '';
      site_state.value=js.party.state || '';
      site_pincode.value=js.party.pincode || '';
    }
  }catch(e){ console.error(e); }
});
document.getElementById('clearSiteAddr')?.addEventListener('click', ()=>{
  ['site_name','site_gst_number','site_address_line1','site_address_line2','site_city','site_state','site_pincode']
    .forEach(id=>{const el=document.getElementById(id); if(el) el.value='';});
});
</script>
