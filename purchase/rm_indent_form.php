<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_permission('purchase.indent.manage');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* numbering (series=RMI) using number_sequences */
function next_no(PDO $pdo, string $series): string {
  $y = (int)date('Y');
  $pdo->beginTransaction();
  try {
    $sel = $pdo->prepare("SELECT year,seq FROM number_sequences WHERE series=? FOR UPDATE");
    $sel->execute([$series]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['year'] !== $y) {
      $pdo->prepare("REPLACE INTO number_sequences(series,year,seq) VALUES(?,?,0)")->execute([$series,$y]);
      $row = ['year'=>$y,'seq'=>0];
    }
    $seq = (int)$row['seq'] + 1;
    $pdo->prepare("UPDATE number_sequences SET seq=? WHERE series=?")->execute([$seq,$series]);
    $pdo->commit();
    return sprintf('%s-%04d-%04d', $series, $y, $seq);
  } catch(Throwable $e){ $pdo->rollBack(); throw $e; }
}

function plate_kg(float $Lmm,float $Wmm,float $Tmm,float $density): float {
  return round(($Lmm/1000)*($Wmm/1000)*($Tmm/1000)*($density*1000),3);
}
function struct_kg(float $lenM,float $wPerM): float { return round($lenM * $wPerM, 3); }

/* dropdowns */
$projects  = $pdo->query("SELECT id, CONCAT(code,' — ',name) label FROM projects ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, CONCAT(code,' — ',name) label FROM locations WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* load header+lines */
$id = (int)($_GET['id'] ?? 0);
$h = ['rmi_no'=>'', 'project_id'=>null, 'priority'=>'normal', 'delivery_location_id'=>null, 'remarks'=>'', 'status'=>'draft'];
$lines = [];
if ($id) {
  $st = $pdo->prepare("SELECT * FROM rm_indents WHERE id=?"); $st->execute([$id]); $hdr = $st->fetch(PDO::FETCH_ASSOC);
  if ($hdr) $h = $hdr;
  $st = $pdo->prepare("SELECT * FROM rm_indent_lines WHERE rmi_id=? ORDER BY sort_order,id"); $st->execute([$id]); $lines = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $data = [
    'project_id' => (int)($_POST['project_id'] ?? 0) ?: null,
    'priority'   => in_array(($_POST['priority'] ?? 'normal'), ['low','normal','high'], true) ? $_POST['priority'] : 'normal',
    'delivery_location_id' => (int)($_POST['delivery_location_id'] ?? 0) ?: null,
    'remarks'    => trim($_POST['remarks'] ?? ''),
    'status'     => ($_POST['action'] ?? 'save') === 'submit' ? 'raised' : 'draft',
  ];
  if ($id) {
    $pdo->prepare("UPDATE rm_indents SET project_id=?,priority=?,delivery_location_id=?,remarks=?,status=? WHERE id=?")
        ->execute([$data['project_id'],$data['priority'],$data['delivery_location_id'],$data['remarks'],$data['status'],$id]);
    $pdo->prepare("DELETE FROM rm_indent_lines WHERE rmi_id=?")->execute([$id]);
  } else {
    $rmi_no = next_no($pdo,'RMI');
    $pdo->prepare("INSERT INTO rm_indents (rmi_no,project_id,priority,delivery_location_id,remarks,status) VALUES (?,?,?,?,?,?)")
        ->execute([$rmi_no,$data['project_id'],$data['priority'],$data['delivery_location_id'],$data['remarks'],$data['status']]);
    $id = (int)$pdo->lastInsertId();
    $h['rmi_no'] = $rmi_no;
  }

  // lines
  $N = (int)($_POST['line_count'] ?? 0);
  $ins = $pdo->prepare("INSERT INTO rm_indent_lines
    (rmi_id,item_id,make_id,length_mm,width_mm,thickness_mm,density_gcc,qty_nos,section_id,length_m,qty_len,wt_per_m_kg,description,needed_by,remarks,theoretical_weight_kg,sort_order,project_id)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

  for ($i=0; $i<$N; $i++) {
    $type = $_POST["type_$i"] ?? 'plate';
    $item = (int)($_POST["item_$i"] ?? 0);
    if ($item<=0) continue;

    $make = (int)($_POST["make_$i"] ?? 0) ?: null;
    $desc = trim($_POST["desc_$i"] ?? '');
    $need = $_POST["need_$i"] ?: null;
    $rem  = trim($_POST["rem_$i"] ?? '');

    $L=$W=$T=$rho=$qtyNos=null; $sec=$lenM=$qtyLen=$wpm=null; $kg=0.0;

    if ($type==='plate') {
      $L=(float)($_POST["L_$i"] ?? 0); $W=(float)($_POST["W_$i"] ?? 0); $T=(float)($_POST["T_$i"] ?? 0);
      $rho=(float)($_POST["rho_$i"] ?? 7.85); $qtyNos=(float)($_POST["nos_$i"] ?? 0);
      $kg = plate_kg($L,$W,$T,$rho)*$qtyNos;
    } else {
      $sec = (int)($_POST["sec_$i"] ?? 0) ?: null;
      $wpm = (float)($_POST["wpm_$i"] ?? 0);
      $lenM = (float)($_POST["lenM_$i"] ?? 0); $qtyLen = (float)($_POST["qtyLen_$i"] ?? 0);
      $kg = struct_kg($lenM,$wpm)*$qtyLen;
    }

    $ins->execute([$id,$item,$make,$L,$W,$T,$rho,$qtyNos,$sec,$lenM,$qtyLen,$wpm,$desc,$need,$rem, round($kg,3), $i+1, $data['project_id']]);
  }

  header('Location: rm_indents_list.php'); exit;
}

include __DIR__.'/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= $id ? 'Edit RMI' : 'New RMI' ?></h2>
    <a class="btn btn-outline-secondary" href="rm_indents_list.php">Back</a>
  </div>

  <form method="post" id="rmiForm" autocomplete="off">
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">RMI No</label>
        <input class="form-control" value="<?=htmlspecialchars((string)$h['rmi_no'])?>" readonly>
      </div>
      <div class="col-md-4">
        <label class="form-label">Project</label>
        <select name="project_id" class="form-select">
          <option value="">— General —</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?=$p['id']?>" <?=((int)($h['project_id']??0)===(int)$p['id']?'selected':'')?>><?=htmlspecialchars($p['label'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Priority</label>
        <select name="priority" class="form-select">
          <?php foreach (['low','normal','high'] as $pp): ?>
            <option value="<?=$pp?>" <?=($h['priority']===$pp?'selected':'')?>><?=ucfirst($pp)?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Delivery To</label>
        <select name="delivery_location_id" class="form-select">
          <option value="">—</option>
          <?php foreach ($locations as $l): ?>
            <option value="<?=$l['id']?>" <?=((int)($h['delivery_location_id']??0)===(int)$l['id']?'selected':'')?>><?=htmlspecialchars($l['label'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label">Remarks</label>
        <input class="form-control" name="remarks" value="<?=htmlspecialchars((string)$h['remarks'])?>">
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Lines (Plates / Structurals)</strong>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-primary" type="button" onclick="addRow('plate')">+ Plate</button>
          <button class="btn btn-sm btn-secondary" type="button" onclick="addRow('struct')">+ Structural</button>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-bordered table-sm align-middle mb-0" id="linesTbl">
            <thead class="table-light">
              <tr>
                <th style="width:90px;">Type</th>
                <th style="min-width:240px;">Item (type to search)</th>
                <th>Make</th>
                <th class="text-center">Plate: L/W/T/mm • ρ • Nos</th>
                <th class="text-center">Structural: wt/m • Len(m) • Qty</th>
                <th>Description</th>
                <th>Needed By</th>
                <th class="text-end">Line kg</th>
                <th style="width:42px;"></th>
              </tr>
            </thead>
            <tbody id="tbody">
              <?php
              $i=0; foreach ($lines as $ln): $isPlate = ($ln['thickness_mm']!==null || $ln['qty_nos']!==null); ?>
              <tr data-i="<?=$i?>">
                <td>
                  <select class="form-select form-select-sm type" name="type_<?=$i?>">
                    <option value="plate" <?=$isPlate?'selected':''?>>Plate</option>
                    <option value="struct" <?=!$isPlate?'selected':''?>>Structural</option>
                  </select>
                </td>
                <td class="pos-rel">
                  <input type="hidden" name="item_<?=$i?>" class="item-id" value="<?= (int)$ln['item_id'] ?>">
                  <input type="text" class="form-control form-control-sm item-search" placeholder="Type 2+ chars…">
                  <div class="dropdown-menu shadow item-results"></div>
                </td>
                <td><select class="form-select form-select-sm make" name="make_<?=$i?>"><option value="<?= (int)($ln['make_id']??0) ?>" selected><?= $ln['make_id']?'Selected':'—' ?></option></select></td>

                <td class="plate">
                  <div class="d-flex gap-1">
                    <input class="form-control form-control-sm" name="L_<?=$i?>"  value="<?= (float)$ln['length_mm'] ?>"  placeholder="L">
                    <input class="form-control form-control-sm" name="W_<?=$i?>"  value="<?= (float)$ln['width_mm'] ?>"   placeholder="W">
                    <input class="form-control form-control-sm" name="T_<?=$i?>"  value="<?= (float)$ln['thickness_mm'] ?>" placeholder="T">
                    <input class="form-control form-control-sm" name="rho_<?=$i?>" value="<?= (float)($ln['density_gcc']??7.85) ?>" placeholder="ρ">
                    <input class="form-control form-control-sm" name="nos_<?=$i?>" value="<?= (float)$ln['qty_nos'] ?>" placeholder="Nos">
                  </div>
                </td>
                <td class="struct">
                  <div class="d-flex gap-1">
                    <input class="form-control form-control-sm" name="wpm_<?=$i?>"   value="<?= (float)($ln['wt_per_m_kg']??0) ?>" placeholder="kg/m">
                    <input class="form-control form-control-sm" name="lenM_<?=$i?>" value="<?= (float)$ln['length_m'] ?>"     placeholder="Len">
                    <input class="form-control form-control-sm" name="qtyLen_<?=$i?>" value="<?= (float)$ln['qty_len'] ?>"    placeholder="Qty">
                  </div>
                </td>

                <td><input class="form-control form-control-sm" name="desc_<?=$i?>" value="<?= htmlspecialchars((string)$ln['description']) ?>"></td>
                <td><input class="form-control form-control-sm" name="need_<?=$i?>" type="date" value="<?= htmlspecialchars((string)$ln['needed_by']) ?>"></td>
                <td><input class="form-control form-control-sm text-end kg" name="kg_<?=$i?>" value="<?= number_format((float)$ln['theoretical_weight_kg'],3) ?>" readonly></td>
                <td class="text-center"><button class="btn btn-sm btn-link text-danger" type="button" onclick="rm(this)">&times;</button></td>
              </tr>
              <?php $i++; endforeach; ?>
            </tbody>
            <tfoot class="table-light">
              <tr><th colspan="7" class="text-end">Total Theoretical (kg)</th>
                  <th><input class="form-control form-control-sm text-end" id="totKg" value="0.000" readonly></th>
                  <th></th></tr>
              <tr><td colspan="9"><input type="hidden" id="line_count" name="line_count" value="<?=$i?>"></td></tr>
            </tfoot>
          </table>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <button class="btn btn-success" name="action" value="save">Save</button>
        <button class="btn btn-primary" name="action" value="submit">Submit</button>
      </div>
    </div>
  </form>
</div>

<style>
.pos-rel{position:relative}
.item-results{position:absolute;top:100%;left:0;right:0;max-height:220px;overflow:auto;display:none}
.item-results.show{display:block}
</style>

<script>
function num(v){const n=parseFloat(v);return isNaN(n)?0:n;}
function platePer(L,W,T,rho){if(!L||!W||!T||!rho) return 0; return (L/1000)*(W/1000)*(T/1000)*(rho*1000);}
function structPer(len,wpm){return len*wpm;}

function toggleRow(tr){
  const type = tr.querySelector('.type').value;
  tr.querySelectorAll('.plate').forEach(e=>e.style.display = type==='plate'?'block':'none');
  tr.querySelectorAll('.struct').forEach(e=>e.style.display = type==='struct'?'block':'none');
  recalc(tr); recalcTotal();
}
function recalc(tr){
  const type = tr.querySelector('.type').value;
  let kg=0;
  if(type==='plate'){
    const L=num(tr.querySelector('[name^="L_"]').value);
    const W=num(tr.querySelector('[name^="W_"]').value);
    const T=num(tr.querySelector('[name^="T_"]').value);
    const R=num(tr.querySelector('[name^="rho_"]').value||7.85);
    const N=num(tr.querySelector('[name^="nos_"]').value);
    kg = platePer(L,W,T,R)*N;
  }else{
    const WPM=num(tr.querySelector('[name^="wpm_"]').value);
    const LM=num(tr.querySelector('[name^="lenM_"]').value);
    const Q=num(tr.querySelector('[name^="qtyLen_"]').value);
    kg = structPer(LM,WPM)*Q;
  }
  tr.querySelector('.kg').value = kg.toFixed(3);
}
function recalcTotal(){
  let t=0; document.querySelectorAll('#tbody tr').forEach(tr=>{
    const v = parseFloat(tr.querySelector('.kg')?.value||'0'); if(!isNaN(v)) t+=v;
  });
  document.getElementById('totKg').value = t.toFixed(3);
}
function rm(btn){ const tr=btn.closest('tr'); tr.remove(); recalcTotal(); }
function addRow(kind){
  const tb=document.getElementById('tbody');
  const i = parseInt(document.getElementById('line_count').value||'0');
  const tr=document.createElement('tr'); tr.dataset.i=i;
  tr.innerHTML = `
    <td>
      <select class="form-select form-select-sm type" name="type_${i}">
        <option value="plate" ${kind==='plate'?'selected':''}>Plate</option>
        <option value="struct" ${kind==='struct'?'selected':''}>Structural</option>
      </select>
    </td>
    <td class="pos-rel">
      <input type="hidden" name="item_${i}" class="item-id" value="">
      <input type="text" class="form-control form-control-sm item-search" placeholder="Type 2+ chars…">
      <div class="dropdown-menu shadow item-results"></div>
    </td>
    <td><select class="form-select form-select-sm make" name="make_${i}"><option value="">—</option></select></td>
    <td class="plate">
      <div class="d-flex gap-1">
        <input class="form-control form-control-sm" name="L_${i}"  placeholder="L">
        <input class="form-control form-control-sm" name="W_${i}"  placeholder="W">
        <input class="form-control form-control-sm" name="T_${i}"  placeholder="T">
        <input class="form-control form-control-sm" name="rho_${i}" value="7.85" placeholder="ρ">
        <input class="form-control form-control-sm" name="nos_${i}" placeholder="Nos">
      </div>
    </td>
    <td class="struct">
      <div class="d-flex gap-1">
        <input class="form-control form-control-sm" name="wpm_${i}" placeholder="kg/m">
        <input class="form-control form-control-sm" name="lenM_${i}" placeholder="Len">
        <input class="form-control form-control-sm" name="qtyLen_${i}" placeholder="Qty">
      </div>
    </td>
    <td><input class="form-control form-control-sm" name="desc_${i}"></td>
    <td><input class="form-control form-control-sm" name="need_${i}" type="date"></td>
    <td><input class="form-control form-control-sm text-end kg" name="kg_${i}" value="0.000" readonly></td>
    <td class="text-center"><button class="btn btn-sm btn-link text-danger" type="button" onclick="rm(this)">&times;</button></td>
  `;
  tb.appendChild(tr);
  document.getElementById('line_count').value = i+1;
  wireRow(tr);
  toggleRow(tr);
}

async function fetchJSON(url){
  const res = await fetch(url, {headers:{'Accept':'application/json'}});
  const ct = res.headers.get('content-type')||''; if(!ct.includes('application/json')) return {ok:false,error:'non_json'};
  try { return await res.json(); } catch { return {ok:false,error:'parse'}; }
}
function wireRow(tr){
  tr.querySelector('.type').addEventListener('change', ()=>{toggleRow(tr);});
  tr.querySelectorAll('input').forEach(el=>el.addEventListener('input', ()=>{recalc(tr);recalcTotal();}));

  const input = tr.querySelector('.item-search');
  const hidden= tr.querySelector('.item-id');
  const results = tr.querySelector('.item-results');
  const makeSel = tr.querySelector('.make');
  let timer=null,lastQ='';

  function clear(){results.innerHTML='';results.classList.remove('show');}
  function show(){results.classList.add('show');}

  input.addEventListener('input', ()=>{
    const q=input.value.trim();
    if(q===lastQ) return; lastQ=q;
    if(timer) clearTimeout(timer);
    timer=setTimeout(async ()=>{
      if(q.length<2){ clear(); return; }
      const js=await fetchJSON('items_search.php?q='+encodeURIComponent(q));
      results.innerHTML=''; if(!(js&&js.ok)) return;
      (js.items||[]).forEach(it=>{
        const b=document.createElement('button'); b.type='button'; b.className='dropdown-item'; b.textContent=it.label;
        b.addEventListener('click', async ()=>{
          input.value=it.label; hidden.value=it.id; clear();
          makeSel.innerHTML='<option value="">—</option>';
          const m = await fetchJSON('item_makes.php?item_id='+it.id);
          if(m&&m.ok&&Array.isArray(m.makes)){ m.makes.forEach(x=>{ const o=document.createElement('option'); o.value=x.id; o.textContent=x.name; makeSel.appendChild(o);});}
        });
        results.appendChild(b);
      });
      show();
    }, 200);
  });
  document.addEventListener('click', e=>{ if(!tr.contains(e.target)) clear(); });
}
document.querySelectorAll('#tbody tr').forEach(tr=>{ wireRow(tr); toggleRow(tr);});
recalcTotal();
</script>

<?php include __DIR__.'/../ui/layout_end.php';