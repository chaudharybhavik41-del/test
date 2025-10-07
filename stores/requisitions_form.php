<?php
/** PATH: /public_html/stores/requisitions_form.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.req.manage');

$pdo = db();

/* ----------------- Helpers (robust, no INFORMATION_SCHEMA needed) ----------------- */
function load_projects(PDO $pdo): array {
  try { $rows = $pdo->query("SELECT id, code, name FROM projects ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ $rows = $pdo->query("SELECT id, name FROM projects ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); foreach($rows as &$r){$r['code']=null;} }
  foreach ($rows as &$r) { $r['_label'] = ($r['code']?($r['code'].' — '):'').($r['name']??''); }
  return $rows;
}

/** Load Items with category + subcategory ids (your schema columns). */
function load_items(PDO $pdo): array {
  // Prefer only active to keep list small
  $sql = "SELECT id, material_code, name, category_id, subcategory_id FROM items WHERE status='active' ORDER BY name ASC LIMIT 5000";
  try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) {
    // Fallback if status column behaves differently
    $sql = "SELECT id, material_code, name, category_id, subcategory_id FROM items ORDER BY name ASC LIMIT 5000";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }
}

/** Load categories/subcategories if tables exist; otherwise derive labels from items */
function try_load_simple(PDO $pdo, string $table, array $cols = ['id','name'], string $order='name ASC', int $limit=10000): array {
  $colList = implode(',', array_map(fn($c)=>"`$c`", $cols));
  try { return $pdo->query("SELECT $colList FROM `$table` ORDER BY $order LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return []; }
}

$projects = load_projects($pdo);
$itemsRaw = load_items($pdo);           // [{id,material_code,name,category_id,subcategory_id},...]

// Try common table names; otherwise we’ll synthesize labels from IDs
$categoriesTbl = try_load_simple($pdo, 'categories', ['id','name']);
if (!$categoriesTbl) $categoriesTbl = try_load_simple($pdo, 'item_categories', ['id','name']);
$subcatsTbl     = try_load_simple($pdo, 'subcategories', ['id','name']);
if (!$subcatsTbl) $subcatsTbl     = try_load_simple($pdo, 'item_subcategories', ['id','name']);

// Build id=>label maps (fallback = "Category #ID"/"Subcategory #ID")
$catMap = []; foreach ($categoriesTbl as $c) { $catMap[(int)$c['id']] = trim((string)$c['name']); }
$subMap = []; foreach ($subcatsTbl as $s)    { $subMap[(int)$s['id']] = trim((string)$s['name']); }
foreach ($itemsRaw as &$it) {
  $it['label'] = (trim((string)$it['material_code']) !== '' ? ($it['material_code'].' — ') : '') . ($it['name'] ?? '');
  $it['cat_label']  = $catMap[$it['category_id'] ?? 0]    ?? ('Category #'.(int)($it['category_id'] ?? 0));
  $it['sub_label']  = $subMap[$it['subcategory_id'] ?? 0] ?? ('Subcategory #'.(int)($it['subcategory_id'] ?? 0));
}
unset($it);

// Build distinct category/subcategory option lists from items (so filters never empty)
$catOptions = [];
$subOptions = [];
foreach ($itemsRaw as $it) {
  $cid = (int)($it['category_id'] ?? 0);
  $sid = (int)($it['subcategory_id'] ?? 0);
  if ($cid) $catOptions[$cid] = $it['cat_label'];
  if ($sid) $subOptions[$sid] = $it['sub_label'];
}
// Sort nicely
asort($catOptions, SORT_NATURAL|SORT_FLAG_CASE);
asort($subOptions, SORT_NATURAL|SORT_FLAG_CASE);

// UOMs
try { $uomRows = $pdo->query("SELECT id, COALESCE(code, name) AS label FROM uom ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); }
catch(Throwable $e){ $uomRows = $pdo->query("SELECT id, name AS label FROM uom ORDER BY id LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); }

/* ----------------- Render ----------------- */
$page_title = "New Material Requisition";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <a href="requisitions_list.php" class="btn btn-outline-secondary btn-sm">Back to list</a>
  </div>

  <div class="card mb-3">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-4">
          <label class="form-label mb-1">Category filter</label>
          <select id="filter_category" class="form-select form-select-sm">
            <option value="">All categories</option>
            <?php foreach ($catOptions as $cid=>$cname): ?>
              <option value="<?= (int)$cid ?>"><?= htmlspecialchars($cname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label mb-1">Subcategory filter</label>
          <select id="filter_subcategory" class="form-select form-select-sm">
            <option value="">All subcategories</option>
            <?php foreach ($subOptions as $sid=>$sname): ?>
              <option value="<?= (int)$sid ?>"><?= htmlspecialchars($sname) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Picking a subcategory shrinks item lists.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label mb-1">Type to filter items</label>
          <input type="text" id="filter_text" class="form-control form-control-sm" placeholder="Search code or name…">
        </div>
      </div>
    </div>
  </div>

  <form id="reqForm" class="card card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Project</label>
        <select id="project_id" class="form-select form-select-sm">
          <option value="">— Not linked —</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['_label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Requested By</label>
        <div class="input-group input-group-sm">
          <select id="requested_by_type" class="form-select form-select-sm" style="max-width:140px">
            <option value="staff">Staff</option>
            <option value="contractor">Contractor</option>
          </select>
          <input type="number" id="requested_by_id" class="form-control form-control-sm" placeholder="ID">
        </div>
        <div class="form-text">Use the ID of staff/contractor.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Remarks</label>
        <input type="text" id="remarks" class="form-control form-control-sm" maxlength="200">
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
            <th style="width:40%">Item</th>
            <th style="width:15%">UOM</th>
            <th style="width:15%" class="text-end">Qty</th>
            <th>Remarks</th>
            <th style="width:40px"></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="text-end">
      <button type="button" id="saveBtn" class="btn btn-primary btn-sm">Save Requisition</button>
    </div>
  </form>
</div>

<script>
// ===== data from PHP =====
const ALL_ITEMS = <?= json_encode($itemsRaw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const UOMS      = <?= json_encode($uomRows,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

// ---- filter widgets ----
const $cat = document.getElementById('filter_category');
const $sub = document.getElementById('filter_subcategory');
const $txt = document.getElementById('filter_text');

// ---- tiny debug outlet (inserts if needed) ----
function ensureDebugBox() {
  let box = document.getElementById('reqDebug');
  if (!box) {
    const wrap = document.querySelector('.container');
    box = document.createElement('div');
    box.id = 'reqDebug';
    box.className = 'alert alert-warning d-none mt-2';
    wrap.insertBefore(box, wrap.firstChild.nextSibling);
  }
  return box;
}
function showDebug(msg) {
  const box = ensureDebugBox();
  box.textContent = msg;
  box.classList.remove('d-none');
}

// ---- filtering helpers ----
function filterItems() {
  const cid = parseInt($cat?.value || '0', 10) || null;
  const sid = parseInt($sub?.value || '0', 10) || null;
  const t = ($txt?.value || '').toLowerCase().trim();

  return ALL_ITEMS.filter(it => {
    if (cid && parseInt(it.category_id||0,10)!==cid) return false;
    if (sid && parseInt(it.subcategory_id||0,10)!==sid) return false;
    if (t) {
      const hay = ((it.material_code||'') + ' ' + (it.name||'')).toLowerCase();
      if (!hay.includes(t)) return false;
    }
    return true;
  });
}
function escapeHtml(s){return (s??'').replace(/[&<>"']/g,m=>({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;" }[m]));}
function buildItemOptions(items) {
  if (!items || items.length===0) return '<option value="">— no matches —</option>';
  return '<option value="">— select —</option>' + items.map(i=>{
    const label = (i.material_code?i.material_code+' — ':'') + (i.name||'');
    return `<option value="${i.id}">${escapeHtml(label)}</option>`;
  }).join('');
}
function buildUomOptions() {
  if (!UOMS || UOMS.length===0) return '<option value="">—</option>';
  return '<option value="">—</option>' + UOMS.map(u=>`<option value="${u.id}">${escapeHtml(u.label||'UOM')}</option>`).join('');
}
function rowTpl() {
  return `<tr>
    <td><select class="form-select form-select-sm item_id">${buildItemOptions(filterItems())}</select></td>
    <td><select class="form-select form-select-sm uom_id">${buildUomOptions()}</select></td>
    <td><input type="number" step="0.001" min="0" class="form-control form-control-sm qty_requested text-end" placeholder="0.000"></td>
    <td><input type="text" class="form-control form-control-sm line_remarks" maxlength="150"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
  </tr>`;
}
function refreshAllItemSelects() {
  const filtered = filterItems();
  const optsHtml = buildItemOptions(filtered);
  document.querySelectorAll('#itemsTable select.item_id').forEach(sel=>{
    const prev = sel.value;
    sel.innerHTML = optsHtml;
    if (prev && [...sel.options].some(o=>o.value===prev)) sel.value = prev;
  });
}

// ---- table actions ----
document.getElementById('addRow').addEventListener('click', ()=>{
  document.querySelector('#itemsTable tbody').insertAdjacentHTML('beforeend', rowTpl());
});
document.querySelector('#itemsTable tbody').addEventListener('click', e=>{
  if (e.target.classList.contains('delRow')) e.target.closest('tr').remove();
});

// live filters
$cat?.addEventListener('change', refreshAllItemSelects);
$sub?.addEventListener('change', refreshAllItemSelects);
$txt?.addEventListener('input', refreshAllItemSelects);

// initial row
document.getElementById('addRow').click();

// ---- Save handler (robust) ----
document.getElementById('saveBtn').addEventListener('click', async ()=>{
  const btn = document.getElementById('saveBtn');
  btn.disabled = true; btn.textContent = 'Saving…';

  try {
    const project_id = parseInt(document.getElementById('project_id').value||'0',10) || null;
    const requested_by_type = document.getElementById('requested_by_type').value;
    const requested_by_id = parseInt(document.getElementById('requested_by_id').value||'0',10);
    const remarks = (document.getElementById('remarks').value||'').trim();

    const lines = [];
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr=>{
      const item_id = parseInt(tr.querySelector('.item_id').value||'0',10);
      const uom_id  = parseInt(tr.querySelector('.uom_id').value||'0',10);
      const qty     = parseFloat(tr.querySelector('.qty_requested').value||'0');
      const lrmk    = (tr.querySelector('.line_remarks').value||'').trim();
      if (item_id>0 && uom_id>0 && qty>0) lines.push({item_id, uom_id, qty_requested: qty, remarks: lrmk});
    });

    if (requested_by_id<=0) { alert('Requested By ID required'); return; }
    if (lines.length===0)   { alert('Add at least one valid line'); return; }

    // fire request
    const resp = await fetch('_ajax/req_create.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({project_id, requested_by_type, requested_by_id, remarks, items: lines})
    });

    // If server returned non-200, show body
    if (!resp.ok) {
      const text = await resp.text();
      showDebug('Save failed (HTTP '+resp.status+'): ' + text.slice(0, 1000));
      alert('Save failed. See debug note at top.');
      return;
    }

    // Try parse JSON; if it fails, show raw text
    let data;
    const raw = await resp.text();
    // try to recover if there is leading noise before JSON
    const firstBrace = raw.indexOf('{');
    const lastBrace  = raw.lastIndexOf('}');
    const maybeJson  = (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace)
    ? raw.slice(firstBrace, lastBrace + 1)
    : raw;
    try {
    data = JSON.parse(maybeJson);
    } catch (e) {
    showDebug('Server did not return clean JSON. Raw response: ' + raw.slice(0, 1000));
    alert('Unexpected server response. See debug note at top.');
    return;
    }

    if (data && data.ok) {
      window.location.href = 'requisitions_list.php';
    } else {
      showDebug('Save error from API: ' + (data?.error || 'Unknown'));
      alert('Save failed: ' + (data?.error || 'Unknown'));
    }
  } catch (err) {
    showDebug('JS error: ' + (err?.message || err));
    alert('A script error occurred. See debug note at top.');
  } finally {
    btn.disabled = false; btn.textContent = 'Save Requisition';
  }
});
</script>


<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
