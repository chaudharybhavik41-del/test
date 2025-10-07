<?php
/** PATH: /public_html/purchase/indents_form.php — Indent Form with Subcategory + Item dropdowns (robust fallbacks) */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('purchase.indent.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/* numbering helpers (year+seq) */
function fmt_indent(int $y, int $seq): string { return sprintf('IND-%04d-%04d', $y, $seq); }
function allocate_indent_no(PDO $pdo): string {
  $y = (int)date('Y');
  $pdo->beginTransaction();
  try {
    $s = $pdo->prepare("SELECT seq FROM indent_sequences WHERE year=? FOR UPDATE");
    $s->execute([$y]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $seq = $row ? (int)$row['seq'] : 0;
    if (!$row) $pdo->prepare("INSERT INTO indent_sequences(year,seq) VALUES(?,0)")->execute([$y]);
    $seq++;
    $pdo->prepare("UPDATE indent_sequences SET seq=? WHERE year=?")->execute([$seq,$y]);
    $no = fmt_indent($y,$seq);
    $pdo->commit();
    return $no;
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/* dropdowns */
$projects  = $pdo->query("SELECT id, CONCAT(code, ' — ', name) AS label FROM projects ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$uoms      = $pdo->query("SELECT id, CONCAT(code, ' — ', name) AS label FROM uom WHERE status='active' ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, CONCAT(code, ' — ', name) AS label FROM locations WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* material subcategories — robust loader with fallback to "All Items" */
$subcats = [];
try {
  $hasMS = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='material_subcategories'")->fetchColumn();
  $hasMC = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='material_categories'")->fetchColumn();
  if ($hasMS) {
    $colSCstatus = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='material_subcategories' AND COLUMN_NAME='status'")->fetchColumn();
    $colSCactive = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='material_subcategories' AND COLUMN_NAME='active'")->fetchColumn();
    $scActiveWhere = '1=1';
    if ($colSCstatus)      $scActiveWhere = "COALESCE(s.status,'active')='active'";
    elseif ($colSCactive)  $scActiveWhere = "COALESCE(s.active,1)=1";

    if ($hasMC) {
      $sql = "SELECT s.id, CONCAT(COALESCE(c.code,''),'/',COALESCE(s.code,''),' — ',COALESCE(s.name,'')) AS label
              FROM material_subcategories s
              JOIN material_categories c ON c.id = s.category_id
              WHERE $scActiveWhere
              ORDER BY c.code, s.code, s.name";
    } else {
      $sql = "SELECT s.id, CONCAT(COALESCE(s.code,''),' — ',COALESCE(s.name,'')) AS label
              FROM material_subcategories s
              WHERE $scActiveWhere
              ORDER BY s.code, s.name";
    }
    $subcats = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) { /* ignore and fallback below */ }

if (!$subcats) {
  // FINAL FALLBACK: single option to load ALL items
  $subcats = [['id'=>0, 'label'=>'All Items']];
}

/* load model */
$id = (int)($_GET['id'] ?? 0);
$indent = ['indent_no'=>'','project_id'=>null,'remarks'=>'','priority'=>'normal','delivery_location_id'=>null,'status'=>'draft'];
$lines = [];

if ($id) {
  $h = $pdo->prepare("SELECT * FROM indents WHERE id=?");
  $h->execute([$id]);
  if ($row = $h->fetch(PDO::FETCH_ASSOC)) $indent = $row;

  // join items to know subcategory for preselect
  $d = $pdo->prepare("
    SELECT li.*,
           it.subcategory_id
    FROM indent_items li
    LEFT JOIN items it ON it.id = li.item_id
    WHERE li.indent_id=?
    ORDER BY li.sort_order, li.id
  ");
  $d->execute([$id]);
  $lines = $d->fetchAll(PDO::FETCH_ASSOC);
}

/* handle submit */
$error = '';
$action = $_POST['action'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $general = isset($_POST['is_general']) && $_POST['is_general'] === '1';
  $projId  = $general ? null : ((int)($_POST['project_id'] ?? 0) ?: null);

  $nextStatus = $_POST['status'] ?? ($indent['status'] ?? 'draft');
  if ($action === 'submit')   $nextStatus = 'raised';
  if ($action === 'approve')  $nextStatus = 'approved';
  if ($action === 'reject')   $nextStatus = 'draft';
  if (in_array($action, ['approve','reject'], true) && !user_has_permission('purchase.indent.approve')) {
    $error = 'You do not have permission to approve/reject.';
  }

  $data = [
    'project_id'           => $projId,
    'remarks'              => trim($_POST['remarks'] ?? ''),
    'priority'             => in_array(($_POST['priority'] ?? 'normal'), ['low','normal','high'], true) ? $_POST['priority'] : 'normal',
    'delivery_location_id' => (int)($_POST['delivery_location_id'] ?? 0) ?: null,
    'status'               => $nextStatus,
  ];

  $li_item = $_POST['li_item'] ?? [];
  $li_make = $_POST['li_make'] ?? [];
  $li_desc = $_POST['li_desc'] ?? [];
  $li_qty  = $_POST['li_qty']  ?? [];
  $li_uom  = $_POST['li_uom']  ?? [];
  $li_need = $_POST['li_need'] ?? [];
  $li_rem  = $_POST['li_rem']  ?? [];

  if (!$error) {
    if ($id) {
      $stmt = $pdo->prepare("UPDATE indents SET project_id=:project_id, remarks=:remarks, priority=:priority,
              delivery_location_id=:delivery_location_id, status=:status WHERE id=:id");
      $stmt->execute($data + ['id'=>$id]);
      $pdo->prepare("DELETE FROM indent_items WHERE indent_id=?")->execute([$id]);
    } else {
      $indent_no = allocate_indent_no($pdo);
      $stmt = $pdo->prepare("INSERT INTO indents (indent_no,project_id,remarks,priority,delivery_location_id,status)
                             VALUES (?,?,?,?,?,?)");
      $stmt->execute([$indent_no,$data['project_id'],$data['remarks'],$data['priority'],$data['delivery_location_id'],$data['status']]);
      $id = (int)$pdo->lastInsertId();
    }

    $valid = 0;
    for ($i=0; $i<count($li_item); $i++) {
      $itemId = (int)($li_item[$i] ?? 0);
      $qty    = (float)($li_qty[$i]  ?? 0);
      if ($itemId > 0 && $qty > 0) {
        $valid++;
        $makeId = (int)($li_make[$i] ?? 0) ?: null;
        $pdo->prepare("INSERT INTO indent_items
                       (indent_id,item_id,make_id,description,qty,uom_id,needed_by,remarks,sort_order)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$id,$itemId,$makeId, trim($li_desc[$i] ?? ''), $qty, (int)($li_uom[$i] ?? 0) ?: null,
                       ($li_need[$i] ?: null), trim($li_rem[$i] ?? ''), $i+1]);
      }
    }

    if ($valid === 0) {
      $error = 'Please add at least one line (item + qty).';
    } else {
      header("Location: indents_list.php"); exit;
    }
  }
}

/* peek number for new form */
$peek_no = '';
if (!$id) {
  $y = (int)date('Y');
  $s = $pdo->prepare("SELECT seq FROM indent_sequences WHERE year=?");
  $s->execute([$y]);
  $row = $s->fetch(PDO::FETCH_ASSOC);
  $peek_no = fmt_indent($y, $row ? ((int)$row['seq']+1) : 1);
}

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= $id ? "Edit Indent" : "New Indent" ?></h2>
    <a href="indents_list.php" class="btn btn-outline-secondary">Back</a>
  </div>

  <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" id="indentForm" autocomplete="off">
    <div class="row">
      <div class="col-md-5 mb-3">
        <label class="form-label">Indent No</label>
        <div class="input-group">
          <input type="text" id="indent_no" class="form-control" value="<?= htmlspecialchars($id ? (string)$indent['indent_no'] : (string)$peek_no) ?>" readonly>
        </div>
        <div class="form-text">Number is allocated on save.</div>
      </div>

      <div class="col-md-7 mb-3">
        <label class="form-label">Project (optional)</label>
        <div class="d-flex gap-2">
          <select name="project_id" id="project_id" class="form-select">
            <option value="">— General Indent (no project) —</option>
            <?php foreach ($projects as $pr): ?>
              <option value="<?= (int)$pr['id'] ?>" <?= ((int)($indent['project_id'] ?? 0)===(int)$pr['id'])?'selected':'' ?>>
                <?= htmlspecialchars($pr['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="is_general" name="is_general" value="1" <?= empty($indent['project_id']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_general">General</label>
          </div>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-7 mb-3">
        <label class="form-label">Notes</label>
        <input type="text" name="remarks" class="form-control" value="<?= htmlspecialchars((string)$indent['remarks']) ?>">
      </div>
      <div class="col-md-2 mb-3">
        <label class="form-label">Priority</label>
        <select name="priority" class="form-select">
          <?php foreach (['low','normal','high'] as $p): ?>
            <option value="<?= $p ?>" <?= ($indent['priority'] ?? 'normal')===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">Delivery To (Location)</label>
        <select name="delivery_location_id" class="form-select">
          <option value="">— Select —</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= (int)$loc['id'] ?>" <?= ((int)($indent['delivery_location_id'] ?? 0)===(int)$loc['id'])?'selected':'' ?>>
              <?= htmlspecialchars($loc['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <h5 class="mt-3">Items</h5>
    <div class="table-responsive">
      <table class="table table-bordered align-middle" id="linesTable">
        <thead>
          <tr>
            <th style="width:20%;">Subcategory</th>
            <th style="width:26%;">Item</th>
            <th style="width:14%;">Make (brand)</th>
            <th>Description</th>
            <th style="width:10%;">Qty</th>
            <th style="width:12%;">UOM</th>
            <th style="width:14%;">Needed By</th>
            <th style="width:8%;"></th>
          </tr>
        </thead>
        <tbody>
        <?php if ($lines): foreach ($lines as $ln): ?>
          <tr>
            <td>
              <select class="form-select li-subcat">
                <?php foreach ($subcats as $sc): ?>
                  <option value="<?= (int)$sc['id'] ?>" <?= ((int)($ln['subcategory_id'] ?? 0)===(int)$sc['id'])?'selected':'' ?>>
                    <?= htmlspecialchars($sc['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select class="form-select li-item" name="li_item[]">
                <?php if (!empty($ln['item_id'])): ?>
                  <option value="<?= (int)$ln['item_id'] ?>" selected>Loading…</option>
                <?php else: ?>
                  <option value="">— Select —</option>
                <?php endif; ?>
              </select>
              <div class="form-text small text-muted d-none li-item-hint">Pick a subcategory (or All Items)</div>
            </td>
            <td>
              <select class="form-select li-make" name="li_make[]">
                <?php if (!empty($ln['make_id'])): ?>
                  <option value="<?= (int)$ln['make_id'] ?>" selected>Selected</option>
                <?php else: ?><option value="">—</option><?php endif; ?>
              </select>
            </td>
            <td><input class="form-control" name="li_desc[]" value="<?= htmlspecialchars((string)$ln['description']) ?>"></td>
            <td><input class="form-control" name="li_qty[]" type="number" step="0.001" min="0.001" value="<?= htmlspecialchars((string)$ln['qty']) ?>"></td>
            <td>
              <select class="form-select" name="li_uom[]">
                <option value="">—</option>
                <?php foreach ($uoms as $u): ?>
                  <option value="<?= (int)$u['id'] ?>" <?= ((int)$ln['uom_id']===(int)$u['id'])?'selected':'' ?>><?= htmlspecialchars($u['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input class="form-control" name="li_need[]" type="date" value="<?= htmlspecialchars((string)$ln['needed_by']) ?>"></td>
            <td class="text-center"><button class="btn btn-sm btn-outline-danger" type="button" onclick="removeLine(this)">✕</button></td>
          </tr>
        <?php endforeach; else: ?>
          <tr>
            <td>
              <select class="form-select li-subcat">
                <?php foreach ($subcats as $sc): ?>
                  <option value="<?= (int)$sc['id'] ?>"><?= htmlspecialchars($sc['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select class="form-select li-item" name="li_item[]">
                <option value="">— Select —</option>
              </select>
              <div class="form-text small text-muted d-none li-item-hint">Pick a subcategory (or All Items)</div>
            </td>
            <td>
              <select class="form-select li-make" name="li_make[]"><option value="">—</option></select>
            </td>
            <td><input class="form-control" name="li_desc[]" value=""></td>
            <td><input class="form-control" name="li_qty[]" type="number" step="0.001" min="0.001" value=""></td>
            <td>
              <select class="form-select" name="li_uom[]">
                <option value="">—</option>
                <?php foreach ($uoms as $u): ?>
                  <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input class="form-control" name="li_need[]" type="date" value=""></td>
            <td class="text-center"><button class="btn btn-sm btn-outline-danger" type="button" onclick="removeLine(this)">✕</button></td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <button class="btn btn-outline-primary" type="button" onclick="addLine()">+ Add Line</button>

    <div class="d-flex flex-wrap gap-2 mt-4">
      <button class="btn btn-success" type="submit" name="action" value="save">Save</button>
      <button class="btn btn-primary" type="submit" name="action" value="submit">Submit for Approval</button>
      <?php if (user_has_permission('purchase.indent.approve')): ?>
        <button class="btn btn-outline-success" type="submit" name="action" value="approve">Approve</button>
        <button class="btn btn-outline-danger" type="submit" name="action" value="reject">Reject</button>
      <?php endif; ?>
      <a href="indents_list.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<style>
  .pos-rel { position: relative; }
</style>

<script>
function removeLine(btn){ const tb=btn.closest('tbody'); if(tb.rows.length>1) btn.closest('tr').remove(); }
function addLine(){
  const tbody = document.querySelector('#linesTable tbody');
  const tr0   = tbody.rows[0];
  const tr    = tr0.cloneNode(true);
  // clear values
  tr.querySelectorAll('input').forEach(i=>{ i.value=''; });
  tr.querySelectorAll('select').forEach(s=>{
    if (s.classList.contains('li-item') || s.classList.contains('li-make')) {
      s.innerHTML = '<option value="">—</option>';
    } else {
      // keep first option (subcategory "All Items" or first subcat)
      if (s.classList.contains('li-subcat')) { /* leave selected as is to auto-load */ }
      else { s.selectedIndex = 0; }
    }
  });
  const hint = tr.querySelector('.li-item-hint'); if(hint) hint.classList.add('d-none');
  tbody.appendChild(tr);
  wireRow(tr, {prefetch:true});
}

async function fetchJSON(url){
  const res = await fetch(url, {headers: {'Accept':'application/json'}});
  if(!res.ok) throw new Error('HTTP '+res.status);
  return await res.json();
}

async function loadItemsForSubcat(tr, subcatId, selectedItemId){
  const itemSel = tr.querySelector('.li-item');
  const hint    = tr.querySelector('.li-item-hint');
  itemSel.innerHTML = '<option value="">Loading…</option>';
  try{
    const js = await fetchJSON('items_options.php?subcategory_id=' + encodeURIComponent(subcatId || '0'));
    itemSel.innerHTML = '<option value="">— Select —</option>';
    if (js && js.ok && Array.isArray(js.items) && js.items.length) {
      js.items.forEach(it=>{
        const op = document.createElement('option');
        op.value = it.id;
        op.textContent = it.label;
        if (selectedItemId && String(selectedItemId) === String(it.id)) op.selected = true;
        itemSel.appendChild(op);
      });
      if(hint) hint.classList.add('d-none');
    } else {
      if(hint){ hint.textContent = 'No items found'; hint.classList.remove('d-none'); }
    }
  } catch(e){
    itemSel.innerHTML = '<option value="">— Select —</option>';
    if(hint){ hint.textContent = 'Failed to load items'; hint.classList.remove('d-none'); }
  }
}

async function loadMakesForItem(tr, itemId){
  const makeSel = tr.querySelector('.li-make');
  makeSel.innerHTML = '<option value="">—</option>';
  if(!itemId) return;
  try{
    const ms = await fetchJSON('item_makes.php?item_id=' + encodeURIComponent(itemId));
    if(ms.ok && Array.isArray(ms.makes)){
      ms.makes.forEach(m=>{
        const op=document.createElement('option'); op.value=m.id; op.textContent=m.name; makeSel.appendChild(op);
      });
    }
  }catch(e){}
}

function wireRow(tr, opts={prefetch:true}){
  const subSel  = tr.querySelector('.li-subcat');
  const itemSel = tr.querySelector('.li-item');

  subSel.addEventListener('change', ()=>{
    const sc = (subSel.value ?? '0');
    loadItemsForSubcat(tr, sc, null);
    tr.querySelector('.li-make').innerHTML = '<option value="">—</option>';
    itemSel.value = '';
  });

  itemSel.addEventListener('change', ()=>{
    loadMakesForItem(tr, itemSel.value || '');
  });

  // Prefetch: if subcategory has a value (including "0" for All Items), load immediately.
  if (opts.prefetch) {
    const sc = (subSel.value ?? '0');
    const selectedItem = itemSel.querySelector('option[selected]')?.value || itemSel.value || '';
    loadItemsForSubcat(tr, sc, selectedItem).then(()=> { if (selectedItem) loadMakesForItem(tr, selectedItem); });
  }
}

// Wire existing rows
document.querySelectorAll('#linesTable tbody tr').forEach(tr=>wireRow(tr));
</script>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>
