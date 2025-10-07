<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/quote_seq.php';

require_login();
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;
if ($is_edit) require_permission('purchase.quote.view'); else require_permission('purchase.quote.manage');

$hdr = [
  'quote_no'=>'','inquiry_id'=>'','supplier_id'=>'','party_id'=>'',
  'quote_date'=>date('Y-m-d'),'currency'=>'INR','tax_inclusive'=>1,
  'remarks'=>'','status'=>'draft',
  'total_before_tax'=>0,'total_tax'=>0,'total_after_tax'=>0
];
$lines=[]; $meta=[];
$form_error = '';

/* Load KG UOM id (preferred for RMI pricing) */
$kgUomId = (int)($pdo->query("SELECT id FROM uom WHERE code IN ('KG','kg','Kg') ORDER BY FIELD(code,'KG','kg','Kg') LIMIT 1")->fetchColumn() ?: 0);

/* Load for edit */
if ($is_edit) {
  $st=$pdo->prepare("SELECT * FROM inquiry_quotes WHERE id=?");
  $st->execute([$id]);
  if ($r=$st->fetch(PDO::FETCH_ASSOC)) $hdr=array_merge($hdr,$r);

  $st=$pdo->prepare("SELECT i.inquiry_no, i.status AS inquiry_status, p.name AS supplier_name
                     FROM inquiry_quotes iq
                     JOIN inquiries i ON i.id=iq.inquiry_id
                     LEFT JOIN parties p ON p.id=iq.supplier_id
                     WHERE iq.id=?");
  $st->execute([$id]); $meta=$st->fetch(PDO::FETCH_ASSOC) ?: [];

  // Existing CI lines
  $ci = $pdo->prepare("
    SELECT iqi.*, ii.item_id, ii.qty, ii.uom_id, u.code AS uom_code,
           CONCAT(it.material_code,' — ',it.name) AS item_label,
           'CI' AS _src, ii.id AS _key_id
    FROM inquiry_quote_items iqi
    JOIN inquiry_items ii ON ii.id=iqi.inquiry_item_id
    JOIN uom u ON u.id=ii.uom_id
    JOIN items it ON it.id=ii.item_id
    WHERE iqi.quote_id=? AND (iqi.src IS NULL OR iqi.src='CI')
    ORDER BY iqi.id
  ");
  $ci->execute([$id]);
  $Lci = $ci->fetchAll(PDO::FETCH_ASSOC);

  // Existing RMI lines
  $rm = $pdo->prepare("
    SELECT iqi.*, il.item_id, il.qty, il.qty_uom_id AS uom_id, il.weight_kg, u.code AS uom_code,
           COALESCE(il.description, CONCAT('Raw Material Line #',il.id)) AS item_label,
           'RMI' AS _src, il.id AS _key_id
    FROM inquiry_quote_items iqi
    JOIN inquiry_lines il ON il.id=iqi.inquiry_line_id
    LEFT JOIN uom u ON u.id=il.qty_uom_id
    WHERE iqi.quote_id=? AND iqi.src='RMI'
    ORDER BY iqi.id
  ");
  $rm->execute([$id]);
  $Lrm = $rm->fetchAll(PDO::FETCH_ASSOC);

  $lines = array_merge($Lci,$Lrm);
}

/* Inquiry picker – show both Draft + Issued so lines appear; but saving requires Issued */
$inq_all = $pdo->query("
  SELECT id, inquiry_no, status
  FROM inquiries
  WHERE status IN ('draft','issued')
  ORDER BY id DESC
  LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);

$inq_id_param = (int)($_GET['inquiry_id'] ?? 0);

/* SAVE */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='save') {
  require_permission('purchase.quote.manage');

  // Lock state
  $currently_locked = false;
  if ($is_edit) {
    $cur = $pdo->prepare("SELECT inquiry_id, supplier_id, status FROM inquiry_quotes WHERE id=?");
    $cur->execute([$id]);
    $curRow = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$curRow) { $form_error='Quote not found.'; }
    $currently_locked = (($curRow['status'] ?? '') === 'locked');
  }

  $inquiry_id  = (int)($_POST['inquiry_id'] ?? ($hdr['inquiry_id'] ?? 0));
  $supplier_id = (int)($_POST['supplier_id'] ?? ($hdr['supplier_id'] ?? 0));
  $quote_date  = $_POST['quote_date'] ?: date('Y-m-d');
  $currency    = $_POST['currency'] ?: 'INR';
  $tax_incl    = isset($_POST['tax_inclusive']) ? 1 : 0;
  $remarks     = trim($_POST['remarks'] ?? '');

  // Require issued before saving
  $st=$pdo->prepare("SELECT status FROM inquiries WHERE id=?");
  $st->execute([$inquiry_id]);
  $inq_status = (string)$st->fetchColumn();
  if ($inq_status!=='issued') {
    $form_error = 'This Inquiry is not issued yet. Please issue the Inquiry before saving the quote.';
  }

  // Supplier must belong to inquiry
  if (!$form_error) {
    $st=$pdo->prepare("SELECT 1 FROM inquiry_suppliers WHERE inquiry_id=? AND party_id=?");
    $st->execute([$inquiry_id,$supplier_id]);
    if (!$st->fetch()) $form_error = 'Selected supplier is not attached to this Inquiry.';
  }

  // Authoritative CI and RMI maps (RMI includes weight_kg)
  $ci_map=$rm_map=[];
  if (!$form_error) {
    $stm=$pdo->prepare("SELECT id, item_id, qty, uom_id FROM inquiry_items WHERE inquiry_id=?");
    $stm->execute([$inquiry_id]);
    while($r=$stm->fetch(PDO::FETCH_ASSOC)){
      $ci_map[(int)$r['id']] = [
        'item_id'=>(int)$r['item_id'],
        'qty'=>(float)$r['qty'],
        'uom_id'=>(int)$r['uom_id']
      ];
    }
    $stm=$pdo->prepare("SELECT id, item_id, qty, qty_uom_id, weight_kg FROM inquiry_lines WHERE inquiry_id=? AND (source_type IN ('RMI','GI') OR source_type IS NULL)");
    $stm->execute([$inquiry_id]);
    while($r=$stm->fetch(PDO::FETCH_ASSOC)){
      $rm_map[(int)$r['id']] = [
        'item_id'=>(int)$r['item_id'],
        'qty_nos'=>(float)$r['qty'],
        'qty_uom_id'=>(int)($r['qty_uom_id'] ?? 0),
        'weight_kg'=>(float)($r['weight_kg'] ?? 0)
      ];
    }
  }

  // Incoming lines payload
  $L = json_decode($_POST['lines_json'] ?? '[]', true) ?: [];

  // Recalc totals (RMI uses weight_kg for pricing)
  $total_bt=0; $total_tax=0; $total_at=0;
  foreach ($L as &$ln) {
    $unit = (float)($ln['unit_price'] ?? 0);
    $disc = (float)($ln['discount_percent'] ?? 0);
    $taxp = (float)($ln['tax_percent'] ?? 0);

    if (($ln['src'] ?? 'CI') === 'RMI') {
      $rid = (int)($ln['inquiry_line_id'] ?? 0);
      $qtykg = isset($rm_map[$rid]) ? (float)$rm_map[$rid]['weight_kg'] : 0.0;
      $gross = $qtykg * $unit;
    } else {
      $cid = (int)($ln['inquiry_item_id'] ?? 0);
      $qty = isset($ci_map[$cid]) ? (float)$ci_map[$cid]['qty'] : 0.0;
      $gross = $qty * $unit;
    }

    $bt = $gross * (1 - $disc/100);
    $tax = $bt * ($taxp/100);
    $at  = $bt + $tax;

    $ln['_bt']=$bt; $ln['_tax']=$tax; $ln['_at']=$at;
    $total_bt += $bt; $total_tax += $tax; $total_at += $at;
  } unset($ln);

  if (!$form_error) {
    try{
      $pdo->beginTransaction();

      if (!$is_edit) {
        $qt_no = next_quote_no();
        $pdo->prepare("INSERT INTO inquiry_quotes
          (quote_no,inquiry_id,party_id,supplier_id,quote_date,currency,tax_inclusive,total_before_tax,total_tax,total_after_tax,remarks,status,created_by)
          VALUES (?,?,?,?,?,?,?,?,?,?,?, 'draft', ?)")
          ->execute([$qt_no,$inquiry_id,$supplier_id,$supplier_id,$quote_date,$currency,$tax_incl,$total_bt,$total_tax,$total_at,$remarks,current_user_id()]);
        $id = (int)$pdo->lastInsertId();
      } else {
        $cur_locked = (($hdr['status'] ?? '') === 'locked');
        if ($cur_locked) {
          $pdo->prepare("UPDATE inquiry_quotes SET remarks=? WHERE id=?")->execute([$remarks,$id]);
        } else {
          $pdo->prepare("UPDATE inquiry_quotes
            SET inquiry_id=?, party_id=?, supplier_id=?, quote_date=?, currency=?, tax_inclusive=?, total_before_tax=?, total_tax=?, total_after_tax=?, remarks=?
            WHERE id=?")
            ->execute([$inquiry_id,$supplier_id,$supplier_id,$quote_date,$currency,$tax_incl,$total_bt,$total_tax,$total_at,$remarks,$id]);

          $pdo->prepare("DELETE FROM inquiry_quote_items WHERE quote_id=?")->execute([$id]);
        }
      }

      // Insert lines (if not locked). For RMI: qty = weight_kg, uom = KG
      $cur_locked = (($hdr['status'] ?? '') === 'locked');
      if (!$cur_locked) {
        $ins = $pdo->prepare("
          INSERT INTO inquiry_quote_items
            (quote_id, src, inquiry_item_id, inquiry_line_id, item_id, qty, uom_id,
             unit_price, discount_percent, tax_percent, delivery_days, remarks,
             line_total_before_tax, line_tax, line_total_after_tax, line_total)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        foreach ($L as $ln) {
          $src = (string)($ln['src'] ?? 'CI');
          if ($src==='RMI') {
            $lid = (int)($ln['inquiry_line_id'] ?? 0);
            if (!$lid || !isset($rm_map[$lid])) continue;
            $m = $rm_map[$lid];
            $qty_for_pricing = (float)$m['weight_kg'];
            $uom_for_pricing = $kgUomId ?: (int)$m['qty_uom_id'] ?: null;

            $ins->execute([
              $id, 'RMI', null, $lid,
              (int)$m['item_id'],
              $qty_for_pricing,
              $uom_for_pricing,
              (float)($ln['unit_price'] ?? 0),
              (float)($ln['discount_percent'] ?? 0),
              (float)($ln['tax_percent'] ?? 0),
              !empty($ln['delivery_days'])?(int)$ln['delivery_days']:null,
              trim((string)($ln['remarks']??'')),
              (float)($ln['_bt'] ?? 0), (float)($ln['_tax'] ?? 0), (float)($ln['_at'] ?? 0),
              (float)($ln['_at'] ?? 0)
            ]);
          } else {
            $iid = (int)($ln['inquiry_item_id'] ?? 0);
            if (!$iid || !isset($ci_map[$iid])) continue;
            $m = $ci_map[$iid];
            $ins->execute([
              $id, 'CI', $iid, null,
              (int)$m['item_id'],
              (float)$m['qty'],
              (int)$m['uom_id'],
              (float)($ln['unit_price'] ?? 0),
              (float)($ln['discount_percent'] ?? 0),
              (float)($ln['tax_percent'] ?? 0),
              !empty($ln['delivery_days'])?(int)$ln['delivery_days']:null,
              trim((string)($ln['remarks']??'')),
              (float)($ln['_bt'] ?? 0), (float)($ln['_tax'] ?? 0), (float)($ln['_at'] ?? 0),
              (float)($ln['_at'] ?? 0)
            ]);
          }
        }
      }

      $pdo->commit();
      header('Location: /purchase/quotes_list.php'); exit;

    } catch(Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      $form_error = 'Save failed: '.$e->getMessage();
    }
  }
}

/* Determine selected inquiry & suppliers */
$selected_inquiry_id = $is_edit ? (int)$hdr['inquiry_id'] : ($inq_id_param ?: 0);
$selected_inquiry_status = '';
if ($selected_inquiry_id) {
  $st=$pdo->prepare("SELECT status FROM inquiries WHERE id=?");
  $st->execute([$selected_inquiry_id]);
  $selected_inquiry_status = (string)$st->fetchColumn();
}

$suppliers_for_inquiry=[];
if ($selected_inquiry_id) {
  $st=$pdo->prepare("SELECT s.party_id AS supplier_id, p.code, p.name
                     FROM inquiry_suppliers s
                     JOIN parties p ON p.id=s.party_id
                     WHERE s.inquiry_id=? ORDER BY p.name");
  $st->execute([$selected_inquiry_id]); $suppliers_for_inquiry=$st->fetchAll(PDO::FETCH_ASSOC);
}

/* Inquiry lines (read-only source) */
$inq_ci=[]; $inq_rm=[];
if ($selected_inquiry_id) {
  $st=$pdo->prepare("SELECT ii.id AS inquiry_item_id, ii.item_id, ii.qty, ii.uom_id, u.code AS uom_code,
                            CONCAT(it.material_code,' — ',it.name) AS item_label
                     FROM inquiry_items ii
                     JOIN items it ON it.id=ii.item_id
                     JOIN uom u ON u.id=ii.uom_id
                     WHERE ii.inquiry_id=? ORDER BY ii.id");
  $st->execute([$selected_inquiry_id]); $inq_ci=$st->fetchAll(PDO::FETCH_ASSOC);

  $st=$pdo->prepare("SELECT il.id AS inquiry_line_id, il.item_id, il.qty, il.qty_uom_id AS uom_id,
                            il.weight_kg, u.code AS uom_code,
                            COALESCE(il.description, CONCAT('Raw Material Line #',il.id)) AS item_label
                     FROM inquiry_lines il
                     LEFT JOIN uom u ON u.id=il.qty_uom_id
                     WHERE il.inquiry_id=? AND (il.source_type IN ('RMI','GI') OR il.source_type IS NULL)
                     ORDER BY il.id");
  $st->execute([$selected_inquiry_id]); $inq_rm=$st->fetchAll(PDO::FETCH_ASSOC);
}

$is_locked = ($hdr['status'] ?? '') === 'locked';

include __DIR__.'/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= $is_edit?'Edit Quote':'New Quote' ?></h1>
    <?php if($is_locked): ?><span class="badge bg-success">Selected (locked)</span><?php endif; ?>
  </div>

  <?php if ($form_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($form_error) ?></div>
  <?php elseif ($selected_inquiry_id && $selected_inquiry_status!=='issued'): ?>
    <div class="alert alert-warning">
      Inquiry is <strong><?=htmlspecialchars($selected_inquiry_status)?></strong>. You can view lines,
      but you must <strong>Issue</strong> the Inquiry before saving a quote.
    </div>
  <?php endif; ?>

  <form method="post" class="card shadow-sm p-3" id="qForm">
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="lines_json" id="lines_json">

    <div class="row g-3">
      <div class="col-md-2">
        <label class="form-label">Quote No</label>
        <input class="form-control" value="<?=htmlspecialchars($hdr['quote_no']??'')?>" disabled>
      </div>

      <div class="col-md-4">
        <label class="form-label">Inquiry</label>
        <select name="inquiry_id" id="inquiry_id" class="form-select" <?= $is_edit?'disabled':''?>>
          <option value="">— Choose Inquiry —</option>
          <optgroup label="Issued">
            <?php foreach($inq_all as $i): if ($i['status']==='issued'): ?>
              <option value="<?=$i['id']?>" <?=($selected_inquiry_id==$i['id'])?'selected':''?>><?=htmlspecialchars($i['inquiry_no'])?> (issued)</option>
            <?php endif; endforeach; ?>
          </optgroup>
          <optgroup label="Draft">
            <?php foreach($inq_all as $i): if ($i['status']==='draft'): ?>
              <option value="<?=$i['id']?>" <?=($selected_inquiry_id==$i['id'])?'selected':''?>><?=htmlspecialchars($i['inquiry_no'])?> (draft)</option>
            <?php endif; endforeach; ?>
          </optgroup>
        </select>
        <?php if ($is_edit): ?><input type="hidden" name="inquiry_id" value="<?= (int)$selected_inquiry_id ?>"><?php endif; ?>
      </div>

      <div class="col-md-3">
        <label class="form-label">Supplier</label>
        <select name="supplier_id" id="supplier_id" class="form-select" required <?= $is_locked?'disabled':''?>>
          <option value=""><?= $suppliers_for_inquiry ? '—' : 'No suppliers attached to this inquiry' ?></option>
          <?php foreach($suppliers_for_inquiry as $s): ?>
            <option value="<?=$s['supplier_id']?>" <?=($hdr['supplier_id']??0)==$s['supplier_id']?'selected':''?>>
              <?=htmlspecialchars(($s['code']?'['.$s['code'].'] ':'').$s['name'])?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($is_locked): ?><input type="hidden" name="supplier_id" value="<?= (int)($hdr['supplier_id'] ?? 0) ?>"><?php endif; ?>
      </div>

      <div class="col-md-3"><label class="form-label">Quote Date</label>
        <input type="date" name="quote_date" class="form-control" value="<?=htmlspecialchars($hdr['quote_date'])?>" <?= $is_locked?'disabled':'' ?>></div>
      <div class="col-md-2"><label class="form-label">Currency</label>
        <input name="currency" class="form-control" value="<?=htmlspecialchars($hdr['currency'])?>" <?= $is_locked?'disabled':'' ?>></div>
      <div class="col-md-2">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" name="tax_inclusive" value="1" <?=($hdr['tax_inclusive']??1)?'checked':''?> <?= $is_locked?'disabled':''?>>
          <label class="form-check-label">Tax Inclusive</label>
        </div>
      </div>
      <div class="col-md-10"><label class="form-label">Remarks</label>
        <input name="remarks" class="form-control" value="<?=htmlspecialchars($hdr['remarks']??'')?>"></div>
    </div>

    <hr class="my-4">

    <h5 class="mb-2">Lines (from Inquiry)</h5>
    <?php if (!$selected_inquiry_id): ?>
      <div class="alert alert-info">Choose an Inquiry above to load its lines.</div>
    <?php endif; ?>
    <div id="linesWrap" class="mb-2"></div>

    <div class="mt-3 d-flex gap-3">
      <div><strong>Total (Before Tax): </strong><span id="tot_bt"><?= number_format((float)$hdr['total_before_tax'], 2, '.', '') ?></span></div>
      <div><strong>Tax: </strong><span id="tot_tax"><?= number_format((float)$hdr['total_tax'], 2, '.', '') ?></span></div>
      <div><strong>Total (After Tax): </strong><span id="tot_at"><?= number_format((float)$hdr['total_after_tax'], 2, '.', '') ?></span></div>
    </div>

    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-primary" type="submit" <?= $is_locked?'disabled':''?>>Save</button>
      <a class="btn btn-outline-secondary" href="/purchase/quotes_list.php">Back</a>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const isLocked = <?= json_encode($is_locked) ?>;

  const CI = <?= json_encode(array_map(fn($r)=>[
    'src'=>'CI',
    'inquiry_item_id'=>(int)$r['inquiry_item_id'],
    'key'=>'CI:'.((int)$r['inquiry_item_id']),
    'item_label'=>$r['item_label'],
    'qty'=>(float)$r['qty'],
    'uom_code'=>$r['uom_code']
  ], $inq_ci)) ?>;

  const RMI = <?= json_encode(array_map(fn($r)=>[
    'src'=>'RMI',
    'inquiry_line_id'=>(int)$r['inquiry_line_id'],
    'key'=>'RMI:'.((int)$r['inquiry_line_id']),
    'item_label'=>$r['item_label'],
    'qty_nos'=>(float)$r['qty'],
    'uom_code'=>($r['uom_code'] ?? ''),      // likely NOS
    'weight_kg'=>(float)($r['weight_kg'] ?? 0)
  ], $inq_rm)) ?>;

  const inqLines = [...CI, ...RMI];

  // Existing quote lines (only for edit)
  const existing = <?= json_encode(array_map(function($r){
    $src = $r['_src'] ?? ($r['src'] ?? 'CI');
    $kid = (int)($r['_key_id'] ?? 0);
    return [
      'key' => $src.':'.$kid,
      'unit_price' => $r['unit_price'],
      'discount_percent' => $r['discount_percent'],
      'tax_percent' => $r['tax_percent'],
      'delivery_days' => $r['delivery_days'],
      'remarks' => $r['remarks']
    ];
  }, $lines)) ?>;
  const exMap = Object.fromEntries(existing.map(x => [String(x.key), x]));

  const wrap = document.getElementById('linesWrap');
  const totBt = document.getElementById('tot_bt');
  const totTax = document.getElementById('tot_tax');
  const totAt = document.getElementById('tot_at');

  function money(n){ return (Math.round((n + Number.EPSILON) * 100)/100).toFixed(2); }
  function n(v,def=0){ const x=parseFloat(v); return Number.isFinite(x)?x:def; }
  function esc(s){ return String(s??'').replaceAll('"','&quot;'); }

  function mkRow(L){
    const ex = exMap[String(L.key)] || {};
    const isRMI = (L.src === 'RMI');

    const d = {
      src: L.src,
      key: L.key,
      label: L.item_label,
      qty: L.qty ?? 0,                 // CI only
      uom_code: L.uom_code || '',
      qty_nos: L.qty_nos ?? 0,         // RMI only (Nos)
      weight_kg: L.weight_kg ?? 0,     // RMI only (pricing qty)
      unit_price: ex.unit_price ?? '',
      discount_percent: ex.discount_percent ?? '0',
      tax_percent: ex.tax_percent ?? '18',
      delivery_days: ex.delivery_days ?? '',
      remarks: ex.remarks ?? ''
    };

    const dis = isLocked ? 'disabled' : '';
    const el = document.createElement('div');
    el.className = 'row g-2 align-items-end mb-2 border rounded p-2 q-line';

    if (isRMI) {
      // Raw material: show Nos + Weight KG, rate per KG (use weight for math)
      el.innerHTML = `
        <div class="col-md-4">
          <label class="form-label">Item (RMI)</label>
          <input class="form-control" value="${esc(d.label)}" disabled>
          <input type="hidden" class="ln_src" value="RMI">
          <input type="hidden" class="ln_key" value="${esc(d.key)}">
        </div>
        <div class="col-md-2">
          <label class="form-label">Qty (Nos)</label>
          <input class="form-control ln_qty_nos" value="${d.qty_nos}" disabled>
        </div>
        <div class="col-md-1">
          <label class="form-label">UOM</label>
          <input class="form-control" value="${esc(d.uom_code)}" disabled>
        </div>
        <div class="col-md-2">
          <label class="form-label">Weight (KG)</label>
          <input class="form-control ln_qtykg" value="${d.weight_kg}" disabled>
        </div>
        <div class="col-md-2">
          <label class="form-label">Rate / KG</label>
          <input class="form-control ln_unit" type="number" step="0.000001" value="${d.unit_price}" ${dis}>
        </div>
        <div class="col-md-1">
          <label class="form-label">Line Total</label>
          <input class="form-control ln_total" value="0.00" disabled>
        </div>
        <div class="col-md-2">
          <label class="form-label">Disc %</label>
          <input class="form-control ln_disc" type="number" step="0.001" value="${d.discount_percent}" ${dis}>
        </div>
        <div class="col-md-2">
          <label class="form-label">Tax %</label>
          <input class="form-control ln_taxp" type="number" step="0.001" value="${d.tax_percent}" ${dis}>
        </div>
        <div class="col-md-2">
          <label class="form-label">Days</label>
          <input class="form-control ln_days" type="number" step="1" value="${d.delivery_days}" ${dis}>
        </div>
        <div class="col-md-12">
          <input class="form-control ln_remarks" placeholder="Remarks" value="${esc(d.remarks)}" ${dis}>
        </div>
      `;
    } else {
      // Consumable: standard qty × unit
      el.innerHTML = `
        <div class="col-md-4">
          <label class="form-label">Item (CI)</label>
          <input class="form-control" value="${esc(d.label)}" disabled>
          <input type="hidden" class="ln_src" value="CI">
          <input type="hidden" class="ln_key" value="${esc(d.key)}">
        </div>
        <div class="col-md-1">
          <label class="form-label">Qty</label>
          <input class="form-control ln_qty" value="${d.qty}" disabled>
        </div>
        <div class="col-md-1">
          <label class="form-label">UOM</label>
          <input class="form-control" value="${esc(d.uom_code)}" disabled>
        </div>
        <div class="col-md-2">
          <label class="form-label">Unit Price</label>
          <input class="form-control ln_unit" type="number" step="0.000001" value="${d.unit_price}" ${dis}>
        </div>
        <div class="col-md-1">
          <label class="form-label">Disc %</label>
          <input class="form-control ln_disc" type="number" step="0.001" value="${d.discount_percent}" ${dis}>
        </div>
        <div class="col-md-1">
          <label class="form-label">Tax %</label>
          <input class="form-control ln_taxp" type="number" step="0.001" value="${d.tax_percent}" ${dis}>
        </div>
        <div class="col-md-1">
          <label class="form-label">Days</label>
          <input class="form-control ln_days" type="number" step="1" value="${d.delivery_days}" ${dis}>
        </div>
        <div class="col-md-1">
          <label class="form-label">Line Total</label>
          <input class="form-control ln_total" value="0.00" disabled>
        </div>
        <div class="col-md-12">
          <input class="form-control ln_remarks" placeholder="Remarks" value="${esc(d.remarks)}" ${dis}>
        </div>
      `;
    }

    if (!isLocked) {
      ['ln_unit','ln_disc','ln_taxp','ln_days'].forEach(k=>{
        const node = el.querySelector('.'+k);
        if (node) node.addEventListener('input', recalcAll);
      });
    }
    return el;
  }

  if (inqLines.length === 0 && <?= (int)$selected_inquiry_id ?: 0 ?> > 0) {
    const alert = document.createElement('div');
    alert.className = 'alert alert-info';
    alert.textContent = 'No lines found in this Inquiry.';
    wrap.appendChild(alert);
  } else {
    inqLines.forEach(s => wrap.appendChild(mkRow(s)));
  }

  function recalcAll(){
    if (isLocked) return;
    let bt=0, tax=0, at=0;
    wrap.querySelectorAll('.q-line').forEach(r=>{
      const isRMI = (r.querySelector('.ln_src').value === 'RMI');
      const unit = n(r.querySelector('.ln_unit').value,0);
      const disc = n(r.querySelector('.ln_disc').value,0);
      const taxp = n(r.querySelector('.ln_taxp').value,0);

      let baseQty = 0;
      if (isRMI) {
        baseQty = n(r.querySelector('.ln_qtykg').value,0);     // weight in KG
      } else {
        baseQty = n(r.querySelector('.ln_qty').value,0);       // qty for CI
      }

      const gross=baseQty*unit;
      const b=gross*(1-disc/100);
      const t=b*(taxp/100);
      const a=b+t;
      bt+=b; tax+=t; at+=a;
      r.querySelector('.ln_total').value = money(a);
    });
    totBt.textContent=money(bt);
    totTax.textContent=money(tax);
    totAt.textContent=money(at);
  }
  if (!isLocked) recalcAll();

  // Serialize lines
  document.getElementById('qForm').addEventListener('submit', ()=>{
    if (isLocked) { document.getElementById('lines_json').value = '[]'; return; }
    const out=[];
    wrap.querySelectorAll('.q-line').forEach(r=>{
      const src = String(r.querySelector('.ln_src').value||'CI');
      const key = String(r.querySelector('.ln_key').value||'');
      const id  = Number(key.split(':')[1]||0);
      out.push({
        src,
        inquiry_item_id: src==='CI' ? id : 0,
        inquiry_line_id: src==='RMI'? id : 0,
        unit_price: parseFloat(r.querySelector('.ln_unit').value||'0')||0,
        discount_percent: parseFloat(r.querySelector('.ln_disc').value||'0')||0,
        tax_percent: parseFloat(r.querySelector('.ln_taxp').value||'0')||0,
        delivery_days: parseInt(r.querySelector('.ln_days').value||'0')||0,
        remarks: (r.querySelector('.ln_remarks').value||'').trim()
      });
    });
    document.getElementById('lines_json').value = JSON.stringify(out);
  });

  // Change inquiry (new quote)
  const inqSel = document.getElementById('inquiry_id');
  if (inqSel && !<?= json_encode($is_edit) ?>) {
    inqSel.addEventListener('change', ()=>{
      const v = inqSel.value || '';
      if (!v) return;
      window.location = '/purchase/quotes_form.php?inquiry_id='+encodeURIComponent(v);
    });
  }
});
</script>
<?php include __DIR__.'/../ui/layout_end.php';