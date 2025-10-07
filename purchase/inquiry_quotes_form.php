<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
$pdo=db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id>0;
if ($is_edit) require_permission('purchase.quote.view'); else require_permission('purchase.quote.manage');

// Lookups
$projects  = $pdo->query("SELECT id,code,name FROM projects ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$uoms      = $pdo->query("SELECT id,code,name FROM uom ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$items     = $pdo->query("SELECT id,material_code,name FROM items WHERE status='active' ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$inquiries = $pdo->query("SELECT id,inquiry_no, project_id FROM inquiries WHERE status IN ('draft','issued') ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, code, name, contact_name, email, phone FROM parties WHERE status=1 AND type='supplier' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Defaults
$hdr = [
  'inquiry_id'=>'','supplier_id'=>'','quote_no'=>'','quote_date'=>date('Y-m-d'),
  'currency'=>'INR','tax_inclusive'=>1,'remarks'=>'','status'=>'draft',
  'total_before_tax'=>0,'total_tax'=>0,'total_after_tax'=>0
];
$lines=[];

if ($is_edit) {
  // header
  $st = $pdo->prepare("SELECT * FROM inquiry_quotes WHERE id=?");
  $st->execute([$id]);
  if ($r=$st->fetch(PDO::FETCH_ASSOC)) $hdr=array_merge($hdr,$r);

  // lines
  $st = $pdo->prepare("SELECT iq.*, it.material_code, it.name AS item_name, u.code AS uom_code
                       FROM inquiry_quote_items iq
                       JOIN items it ON it.id=iq.item_id
                       JOIN uom u ON u.id=iq.uom_id
                       WHERE iq.quote_id=? ORDER BY iq.id");
  $st->execute([$id]);
  $lines = $st->fetchAll(PDO::FETCH_ASSOC);
}

/** SAVE / SUBMIT / LOCK */
$action = $_POST['_action'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['save','submit','lock'],true)) {
  if ($action!=='save') require_permission('purchase.quote.lock'); else require_permission('purchase.quote.manage');

  // header fields
  $inquiry_id = (int)($_POST['inquiry_id'] ?? 0);
  $supplier_id= (int)($_POST['supplier_id'] ?? 0);
  $quote_no   = trim($_POST['quote_no'] ?? '');
  $quote_date = $_POST['quote_date'] ?: date('Y-m-d');
  $currency   = trim($_POST['currency'] ?? 'INR');
  $tax_incl   = isset($_POST['tax_inclusive']) ? 1 : 0;
  $remarks    = trim($_POST['remarks'] ?? '');

  if (!$is_edit) {
    $pdo->prepare("INSERT INTO inquiry_quotes (inquiry_id,supplier_id,quote_no,quote_date,currency,tax_inclusive,remarks,status,created_by)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$inquiry_id,$supplier_id,$quote_no,$quote_date,$currency,$tax_incl,$remarks,'draft',current_user_id()]);
    $id = (int)$pdo->lastInsertId(); $is_edit=true;
  } else {
    // editable only in draft
    $st = $pdo->prepare("SELECT status FROM inquiry_quotes WHERE id=?");
    $st->execute([$id]); $cur = $st->fetchColumn();
    if ($cur==='draft') {
      $pdo->prepare("UPDATE inquiry_quotes SET inquiry_id=?,supplier_id=?,quote_no=?,quote_date=?,currency=?,tax_inclusive=?,remarks=?
                     WHERE id=?")->execute([$inquiry_id,$supplier_id,$quote_no,$quote_date,$currency,$tax_incl,$remarks,$id]);
    }
  }

  // lines JSON
  $lines_json = $_POST['lines_json'] ?? '[]';
  $postLines = json_decode($lines_json, true) ?: [];

  // compute totals and persist (only editable in draft)
  $st = $pdo->prepare("SELECT status FROM inquiry_quotes WHERE id=?");
  $st->execute([$id]); $status = $st->fetchColumn();

  $sum_sub = 0; $sum_tax = 0; $sum_tot = 0;

  if ($status==='draft') {
    $pdo->prepare("DELETE FROM inquiry_quote_items WHERE quote_id=?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO inquiry_quote_items
      (quote_id, inquiry_item_id, item_id, qty, uom_id, unit_price, discount_percent, tax_percent, delivery_days, remarks, line_subtotal, line_tax, line_total)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

    foreach ($postLines as $ln) {
      $item_id  = (int)($ln['item_id'] ?? 0);
      $qty      = (float)($ln['qty'] ?? 0);
      $uom_id   = (int)($ln['uom_id'] ?? 0);
      if ($item_id<=0 || $qty<=0 || $uom_id<=0) continue;

      $price    = (float)($ln['unit_price'] ?? 0);
      $discP    = (float)($ln['discount_percent'] ?? 0);
      $taxP     = (float)($ln['tax_percent'] ?? 0);
      $delDays  = ($ln['delivery_days'] !== '' && $ln['delivery_days'] !== null) ? (int)$ln['delivery_days'] : null;
      $lnRemarks= trim((string)($ln['remarks'] ?? ''));

      $gross = $qty * $price;
      $discAmt = $gross * ($discP/100);
      $sub = $gross - $discAmt;
      $tax = $sub * ($taxP/100);
      $tot = $sub + $tax;

      $sum_sub += $sub; $sum_tax += $tax; $sum_tot += $tot;

      $ins->execute([
        $id,
        !empty($ln['inquiry_item_id']) ? (int)$ln['inquiry_item_id'] : null,
        $item_id,
        $qty,
        $uom_id,
        $price,
        $discP,
        $taxP,
        $delDays,
        $lnRemarks,
        $sub, $tax, $tot
      ]);
    }

    $pdo->prepare("UPDATE inquiry_quotes SET total_before_tax=?, total_tax=?, total_after_tax=? WHERE id=?")
        ->execute([round($sum_sub,2), round($sum_tax,2), round($sum_tot,2), $id]);
  }

  // status transitions
  if ($action==='submit' && $status==='draft') {
    $pdo->prepare("UPDATE inquiry_quotes SET status='submitted' WHERE id=?")->execute([$id]);
  }
  if ($action==='lock' && in_array($status,['draft','submitted'],true)) {
    $pdo->prepare("UPDATE inquiry_quotes SET status='locked' WHERE id=?")->execute([$id]);
  }

  header('Location: /purchase/inquiry_quotes_list.php'); exit;
}

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0"><?= $is_edit?'Edit Quote':'New Quote' ?></h1>
    <a class="btn btn-outline-secondary ms-auto" href="/purchase/inquiry_quotes_list.php">Back</a>
  </div>

  <form method="post" class="card p-3 shadow-sm" id="qForm">
    <input type="hidden" name="_action" value="save" id="formAction">
    <input type="hidden" name="lines_json" id="lines_json">

    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Inquiry</label>
        <select class="form-select" name="inquiry_id" id="inquiry_id" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
          <option value="">—</option>
          <?php foreach($inquiries as $i): ?>
            <option value="<?=$i['id']?>" <?=($hdr['inquiry_id']??null)==$i['id']?'selected':''?>><?=htmlspecialchars($i['inquiry_no'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Supplier</label>
        <select class="form-select" name="supplier_id" id="supplier_id" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
          <option value="">—</option>
          <?php foreach($suppliers as $s): ?>
            <option value="<?=$s['id']?>" <?=($hdr['supplier_id']??null)==$s['id']?'selected':''?>>
              <?=htmlspecialchars(($s['code']?'['.$s['code'].'] ':'').$s['name'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Quote No</label>
        <input class="form-control" name="quote_no" value="<?=htmlspecialchars($hdr['quote_no']??'')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
      </div>
      <div class="col-md-3">
        <label class="form-label">Quote Date</label>
        <input type="date" class="form-control" name="quote_date" value="<?=htmlspecialchars($hdr['quote_date']??date('Y-m-d'))?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
      </div>
      <div class="col-md-2">
        <label class="form-label">Currency</label>
        <input class="form-control" name="currency" value="<?=htmlspecialchars($hdr['currency']??'INR')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
      </div>
      <div class="col-md-2">
        <label class="form-label d-block">Tax Inclusive</label>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" name="tax_inclusive" value="1" <?=($hdr['tax_inclusive']??1)?'checked':''?> <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
        </div>
      </div>
      <div class="col-md-8">
        <label class="form-label">Remarks</label>
        <input class="form-control" name="remarks" value="<?=htmlspecialchars($hdr['remarks']??'')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
      </div>
    </div>

    <hr class="my-3">
    <div class="d-flex align-items-center mb-2">
      <h5 class="mb-0">Lines</h5>
      <?php if(($hdr['status']??'draft')==='draft'): ?>
        <button type="button" class="btn btn-sm btn-outline-success ms-2" id="btnSeed">Seed from Inquiry</button>
      <?php endif; ?>
    </div>

    <div id="linesWrap"></div>

    <div class="mt-3 row g-2">
      <div class="col-md-3 ms-auto">
        <div class="border rounded p-2">
          <div class="d-flex justify-content-between"><span>Subtotal</span><strong id="sum_sub"><?=number_format((float)($hdr['total_before_tax']??0),2)?></strong></div>
          <div class="d-flex justify-content-between"><span>Tax</span><strong id="sum_tax"><?=number_format((float)($hdr['total_tax']??0),2)?></strong></div>
          <div class="d-flex justify-content-between"><span>Total</span><strong id="sum_tot"><?=number_format((float)($hdr['total_after_tax']??0),2)?></strong></div>
        </div>
      </div>
    </div>

    <div class="mt-3 d-flex gap-2">
      <?php if(($hdr['status']??'draft')==='draft'): ?>
        <button class="btn btn-primary" type="submit" onclick="document.getElementById('formAction').value='save'">Save</button>
        <button class="btn btn-warning" type="submit" onclick="document.getElementById('formAction').value='submit'">Submit</button>
        <button class="btn btn-dark" type="submit" onclick="document.getElementById('formAction').value='lock'">Lock</button>
      <?php else: ?>
        <span class="badge bg-secondary">Status: <?=htmlspecialchars($hdr['status'])?></span>
      <?php endif; ?>
      <a class="btn btn-outline-secondary" href="/purchase/inquiry_quotes_list.php">Back</a>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const isDraft = <?= json_encode(($hdr['status']??'draft')==='draft') ?>;
  const uoms   = <?= json_encode($uoms) ?>;
  const items  = <?= json_encode($items) ?>;
  const existing = <?= json_encode($lines) ?>;

  const linesWrap = document.getElementById('linesWrap');
  const sumSub = document.getElementById('sum_sub');
  const sumTax = document.getElementById('sum_tax');
  const sumTot = document.getElementById('sum_tot');

  function opt(v,t){ const o=document.createElement('option'); o.value=v; o.textContent=t; return o; }

  function recalcTotals(){
    let sub=0, tax=0, tot=0;
    linesWrap.querySelectorAll('.qrow').forEach(r=>{
      const qty = parseFloat(r.querySelector('.ln_qty').value || '0');
      const price = parseFloat(r.querySelector('.ln_price').value || '0');
      const discP = parseFloat(r.querySelector('.ln_disc').value || '0');
      const taxP  = parseFloat(r.querySelector('.ln_tax').value  || '0');

      const gross = qty*price;
      const discAmt = gross*(discP/100);
      const s = gross - discAmt;
      const t = s*(taxP/100);
      const T = s+t;

      r.querySelector('.ln_sub').textContent = s.toFixed(2);
      r.querySelector('.ln_tax_amt').textContent = t.toFixed(2);
      r.querySelector('.ln_tot').textContent = T.toFixed(2);

      sub+=s; tax+=t; tot+=T;
    });
    sumSub.textContent=sub.toFixed(2);
    sumTax.textContent=tax.toFixed(2);
    sumTot.textContent=tot.toFixed(2);
  }

  function lineRow(d={}){
    const w=document.createElement('div');
    w.className='qrow row g-2 align-items-end mb-2 border rounded p-2';
    w.innerHTML=`
      <input type="hidden" class="ln_inquiry_item_id" value="${d.inquiry_item_id||''}">
      <div class="col-md-4">
        <label class="form-label">Item</label>
        <select class="form-select ln_item" ${!isDraft?'disabled':''}>
          <option value="">—</option>
        </select>
        <div class="small text-muted">${d.item_name? (d.item_name): ''}</div>
      </div>
      <div class="col-md-2">
        <label class="form-label">Qty</label>
        <input class="form-control ln_qty" type="number" step="0.000001" value="${d.qty||''}" ${!isDraft?'disabled':''}>
      </div>
      <div class="col-md-2">
        <label class="form-label">UOM</label>
        <select class="form-select ln_uom" ${!isDraft?'disabled':''}></select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Unit Price</label>
        <input class="form-control ln_price" type="number" step="0.000001" value="${d.unit_price||''}" ${!isDraft?'disabled':''}>
      </div>
      <div class="col-md-2">
        <label class="form-label">Disc %</label>
        <input class="form-control ln_disc" type="number" step="0.01" value="${d.discount_percent||0}" ${!isDraft?'disabled':''}>
      </div>
      <div class="col-md-2">
        <label class="form-label">Tax %</label>
        <input class="form-control ln_tax" type="number" step="0.01" value="${d.tax_percent||0}" ${!isDraft?'disabled':''}>
      </div>
      <div class="col-md-2">
        <label class="form-label">Delivery Days</label>
        <input class="form-control ln_del" type="number" step="1" value="${d.delivery_days||''}" ${!isDraft?'disabled':''}>
      </div>
      <div class="col-md-3">
        <label class="form-label">Remarks</label>
        <input class="form-control ln_rem" value="${d.remarks||''}" ${!isDraft?'disabled':''}>
      </div>
      <div class="col-md-3">
        <label class="form-label">Amounts</label>
        <div class="d-flex justify-content-between small">
          <span>Sub:</span><strong class="ln_sub">0.00</strong>
        </div>
        <div class="d-flex justify-content-between small">
          <span>Tax:</span><strong class="ln_tax_amt">0.00</strong>
        </div>
        <div class="d-flex justify-content-between">
          <span>Total:</span><strong class="ln_tot">0.00</strong>
        </div>
      </div>
      ${isDraft?'<div class="col-12 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'.qrow\').remove(); recalcTotals();">Remove</button></div>':''}
    `;
    const itemSel = w.querySelector('.ln_item'); items.forEach(it=>itemSel.appendChild(opt(it.id, `${it.material_code} — ${it.name}`)));
    if (d.item_id) itemSel.value=String(d.item_id);
    const uomSel = w.querySelector('.ln_uom'); uoms.forEach(u=>uomSel.appendChild(opt(u.id, u.code)));
    if (d.uom_id) uomSel.value=String(d.uom_id);

    ['.ln_qty','.ln_price','.ln_disc','.ln_tax'].forEach(sel=>{
      w.querySelector(sel).addEventListener('input', recalcTotals);
    });

    // calculate once
    recalcTotals();
    return w;
  }

  function addLine(d={}){ linesWrap.appendChild(lineRow(d)); }

  // seed from Inquiry
  const btnSeed = document.getElementById('btnSeed');
  if (btnSeed) btnSeed.addEventListener('click', async ()=>{
    const iq = document.getElementById('inquiry_id').value;
    const sp = document.getElementById('supplier_id').value;
    if (!iq || !sp) { alert('Select Inquiry and Supplier first'); return; }
    try{
      const res = await fetch('/purchase/inquiry_quote_seed.php?inquiry_id='+encodeURIComponent(iq)+'&supplier_id='+encodeURIComponent(sp));
      if (!res.ok) throw new Error('Seed HTTP '+res.status);
      const rows = await res.json();
      if (!Array.isArray(rows) || !rows.length) { alert('No lines to import'); return; }
      linesWrap.innerHTML='';
      rows.forEach(r=> addLine({
        inquiry_item_id: r.inquiry_item_id,
        item_id: r.item_id, item_name: (r.material_code? r.material_code+' — ' : '') + (r.item_name || ''),
        qty: r.qty, uom_id: r.uom_id, unit_price: '', discount_percent: 0, tax_percent: 0, delivery_days: '', remarks: r.line_notes || ''
      }));
      recalcTotals();
    }catch(e){ alert('Seed error: '+e.message); console.error(e); }
  });

  // preload existing (editing)
  existing.forEach(addLine); recalcTotals();

  // serialize on submit
  const form = document.getElementById('qForm');
  form.addEventListener('submit', ()=>{
    const lines = Array.from(linesWrap.querySelectorAll('.qrow')).map(r=>({
      inquiry_item_id: Number(r.querySelector('.ln_inquiry_item_id').value || 0) || null,
      item_id: Number(r.querySelector('.ln_item').value || 0),
      qty: parseFloat(r.querySelector('.ln_qty').value || '0'),
      uom_id: Number(r.querySelector('.ln_uom').value || 0),
      unit_price: parseFloat(r.querySelector('.ln_price').value || '0'),
      discount_percent: parseFloat(r.querySelector('.ln_disc').value || '0'),
      tax_percent: parseFloat(r.querySelector('.ln_tax').value || '0'),
      delivery_days: r.querySelector('.ln_del').value || null,
      remarks: r.querySelector('.ln_rem').value || ''
    })).filter(x=>x.item_id>0 && x.qty>0 && x.uom_id>0);
    document.getElementById('lines_json').value = JSON.stringify(lines);
  });
});
</script>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
