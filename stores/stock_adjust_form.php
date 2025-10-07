<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_permission('stores.adjust.manage'); // keep your permission
$pdo = db();

/* helpers */
$tbl = function(string $name) use ($pdo): bool {
  try { return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($name))->fetchColumn(); }
  catch (Throwable $e) { return false; }
};
$col = function(string $table, string $c) use ($pdo): bool {
  try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($c))->fetchColumn(); }
  catch (Throwable $e) { return false; }
};

/* Warehouses */
$warehouses = $pdo->query("SELECT id, COALESCE(name, CONCAT('WH-',id)) AS name FROM warehouses ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);

/* Bins (optional, per-line support) */
$hasBins = $tbl('bins');
$bins = [];
if ($hasBins) {
  $bins = $pdo->query("SELECT id, warehouse_id, COALESCE(name, CONCAT('BIN-',id)) AS name FROM bins ORDER BY 3")->fetchAll(PDO::FETCH_ASSOC);
}

/* Items (your schema) */
$items = $pdo->query("
  SELECT i.id, i.material_code, i.name, i.uom_id
  FROM items i
  WHERE i.status='active'
  ORDER BY i.material_code, i.name
  LIMIT 2000
")->fetchAll(PDO::FETCH_ASSOC);

/* UoMs: prefer singular `uom`, fallback to `uoms` if present */
$uomTable = $tbl('uom') ? 'uom' : ($tbl('uoms') ? 'uoms' : null);
$uoms = [];
if ($uomTable) {
  $hasCode = $col($uomTable, 'code');
  $hasName = $col($uomTable, 'name');
  $labelExpr = $hasCode ? 'code' : ($hasName ? 'name' : 'CAST(id AS CHAR)');
  $activeFilter = ($uomTable === 'uom' && $col('uom','status')) ? "WHERE status='active'" : "";
  $uoms = $pdo->query("SELECT id, $labelExpr AS label FROM `$uomTable` $activeFilter ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);
} else {
  $uoms = [['id'=>null,'label'=>'EA']];
}

/* Reasons (optional table) */
$hasReasons = $tbl('stock_adj_reasons');
$reasons = [];
if ($hasReasons) {
  // be schema-safe: code/name may or may not exist; build a label smartly
  $rCols = $pdo->query("SHOW COLUMNS FROM stock_adj_reasons")->fetchAll(PDO::FETCH_COLUMN, 0);
  $hasRCode = in_array('code', $rCols, true);
  $hasRName = in_array('name', $rCols, true);
  $label = ($hasRCode && $hasRName) ? "CONCAT(code, ' — ', name)"
         : ($hasRCode ? "code" : ($hasRName ? "name" : "CAST(id AS CHAR)"));
  $reasons = $pdo->query("SELECT id, $label AS label FROM stock_adj_reasons ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);
}

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Stock Adjustment</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px}
    .card{border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px}
    .row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
    .field{display:flex;flex-direction:column;min-width:220px}
    table{width:100%;border-collapse:collapse;font-size:14px}
    th,td{border-bottom:1px solid #eee;padding:8px;text-align:left}
    th{background:#fafafa}
    .btn{display:inline-block;padding:8px 12px;border:1px solid #ccc;border-radius:6px;background:#fff;cursor:pointer;text-decoration:none;color:#111}
    .btn.primary{border-color:#2573ef;background:#2f7df4;color:#fff}
    input[type=number]{width:120px}
    select,input{padding:6px}
    .notice{padding:8px 12px;background:#fff9e6;border:1px solid #f1d48a;border-radius:6px;margin-bottom:12px}
    .tag{display:inline-block;padding:2px 6px;border:1px solid #aaa;border-radius:4px;font-size:12px;margin-left:8px}
    .muted{opacity:.75}
    .right{display:flex;gap:8px;align-items:center}
    .hidden{display:none}
  </style>
</head>
<body>

<form id="adjForm" method="post" action="/stores/_ajax/stock_adjust_post.php" onsubmit="return validateForm()">
  <!-- CSRF required by your endpoint when posting via regular form -->
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">

  <div class="card">
    <div class="right" style="justify-content:space-between;">
      <h2>Stock Adjustment</h2>
      <div class="muted">Posting to: <code>/stores/_ajax/stock_adjust_post.php</code></div>
    </div>
    <div class="row">
      <div class="field">
        <label>Warehouse *</label>
        <select name="warehouse_id" id="warehouse_id" required>
          <option value="">-- Select --</option>
          <?php foreach($warehouses as $w): ?>
            <option value="<?=$w['id']?>"><?=htmlspecialchars($w['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($hasBins): ?>
      <div class="field">
        <label>Default Bin (optional)</label>
        <select id="header_bin_id">
          <option value="">-- Any --</option>
          <?php foreach($bins as $b): ?>
            <option data-wh="<?=$b['warehouse_id']?>" value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option>
          <?php endforeach; ?>
        </select>
        <small class="muted">New lines will default to this bin</small>
      </div>
      <?php endif; ?>

      <div class="field">
        <label>Mode *</label>
        <select name="mode" id="mode" required>
          <option value="IN">Add to stock (+)</option>
          <option value="OUT">Remove from stock (-)</option>
        </select>
      </div>

      <?php if ($hasReasons): ?>
      <div class="field">
        <label>Reason</label>
        <select name="reason_id" id="reason_id">
          <option value="">-- Select --</option>
          <?php foreach($reasons as $r): ?>
            <option value="<?=$r['id']?>"><?=htmlspecialchars($r['label'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="field" style="flex:1;">
        <label>Remarks</label>
        <input type="text" name="remarks" placeholder="Optional header remarks">
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Lines <span class="tag">Auto-select UoM from Item</span></h3>
    <table id="lines">
      <thead>
        <tr>
          <th style="width:30%;">Item *</th>
          <th>UoM *</th>
          <th>Qty *</th>
          <th class="unitCostHdr">Unit Cost *</th>
          <?php if ($hasBins): ?><th>Bin</th><?php endif; ?>
          <th>Batch</th>
          <th>Line Remarks</th>
          <th></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
    <button class="btn" type="button" onclick="addLine()">+ Add line</button>
    <div class="notice" id="costNotice">Unit Cost is required for <b>IN</b> adjustments and hidden for <b>OUT</b>.</div>
  </div>

  <div class="card">
    <button class="btn primary" type="submit">Post Adjustment</button>
  </div>
</form>

<script>
const ITEMS = <?=json_encode(array_map(function($r){
  return ['id'=>(int)$r['id'], 'label'=>$r['material_code'].' - '.$r['name'], 'uom_id'=>(int)$r['uom_id']];
}, $items))?>;
const UOMS = <?=json_encode($uoms)?>;
const hasBins = <?= $hasBins ? 'true' : 'false' ?>;

// lookup: item_id -> default uom_id
const defaultUomByItem = {}; ITEMS.forEach(it => defaultUomByItem[it.id] = it.uom_id);

function addLine(){
  const tbody = document.querySelector('#lines tbody');
  const tr = document.createElement('tr');

  const binCell = hasBins ? `
    <td>
      <select name="lines[bin_id][]" class="binSel">
        <option value="">-- Any --</option>
        <?php if ($hasBins): foreach($bins as $b): ?>
          <option data-wh="<?=$b['warehouse_id']?>" value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option>
        <?php endforeach; endif; ?>
      </select>
    </td>
  ` : '';

  tr.innerHTML = `
    <td>
      <select name="lines[item_id][]" class="itemSel" required>
        <option value="">-- Select Item --</option>
        ${ITEMS.map(it=>`<option value="${it.id}">${escapeHtml(it.label)}</option>`).join('')}
      </select>
    </td>
    <td>
      <select name="lines[uom_id][]" class="uomSel" ${UOMS.length ? 'required' : ''}>
        <option value="">-- UoM --</option>
        ${UOMS.map(u=>`<option value="${u.id===null?'':u.id}">${escapeHtml(u.label)}</option>`).join('')}
      </select>
    </td>
    <td><input type="number" name="lines[qty][]" min="0.001" step="0.001" required></td>
    <td class="unitCostCell"><input type="number" name="lines[unit_cost][]" min="0" step="0.0001" placeholder="0.0000"></td>
    ${binCell}
    <td><input type="number" name="lines[batch_id][]" step="1" min="1" placeholder="ID"></td>
    <td><input type="text" name="lines[remarks][]" placeholder="Optional"></td>
    <td><button class="btn" type="button" onclick="this.closest('tr').remove()">Remove</button></td>
  `;
  tbody.appendChild(tr);

  wireRow(tr);
  applyModeToRow(tr);      // set unit_cost required/hidden based on mode
  defaultBinToRow(tr);     // if header bin chosen, set it
}

function wireRow(tr){
  const itemSel = tr.querySelector('.itemSel');
  const uomSel  = tr.querySelector('.uomSel');
  if (!itemSel || !uomSel) return;

  const applyDefault = () => {
    const itemId = parseInt(itemSel.value || '0', 10);
    const def = defaultUomByItem[itemId];
    if (!def) return;
    for (const opt of uomSel.options) {
      if (String(opt.value) === String(def)) { uomSel.value = String(def); break; }
    }
  };
  itemSel.addEventListener('change', applyDefault);
}

function applyModeToRow(tr){
  const mode = document.getElementById('mode').value;
  const cell = tr.querySelector('.unitCostCell');
  const input = cell ? cell.querySelector('input[name="lines[unit_cost][]"]') : null;
  if (!cell || !input) return;
  if (mode === 'IN') {
    cell.classList.remove('hidden');
    input.required = true;
    input.disabled = false;
  } else {
    cell.classList.add('hidden');
    input.required = false;
    input.disabled = true;
    input.value = ''; // clear for OUT
  }
}

function defaultBinToRow(tr){
  if (!hasBins) return;
  const headerBin = document.getElementById('header_bin_id');
  const lineBin   = tr.querySelector('.binSel');
  if (!headerBin || !lineBin) return;
  if (headerBin.value) lineBin.value = headerBin.value;
}

function applyModeToAll(){
  document.querySelectorAll('#lines tbody tr').forEach(applyModeToRow);
  const mode = document.getElementById('mode').value;
  document.getElementById('costNotice').innerHTML =
    mode === 'IN' ? 'Unit Cost is required for <b>IN</b> adjustments and hidden for <b>OUT</b>.'
                  : 'Unit Cost is hidden for <b>OUT</b> adjustments.';
  // toggle header visibility
  document.querySelectorAll('.unitCostHdr').forEach(th => {
    if (mode === 'IN') th.classList.remove('hidden'); else th.classList.add('hidden');
  });
}

function validateForm(){
  const wh = document.getElementById('warehouse_id').value;
  const mode = document.getElementById('mode').value;
  if (!wh) { alert('Warehouse is required'); return false; }
  if (!mode) { alert('Mode is required'); return false; }
  const rows = document.querySelectorAll('#lines tbody tr');
  if (!rows.length) { alert('Add at least one line'); return false; }
  if (mode === 'IN') {
    // ensure at least one unit_cost is provided and all lines have it
    for (const tr of rows) {
      const uc = tr.querySelector('input[name="lines[unit_cost][]"]');
      if (!uc || uc.disabled || uc.value === '') { alert('Unit Cost is required for IN.'); return false; }
    }
  }
  return true;
}

function escapeHtml(str){
  return String(str).replace(/[&<>\"'`=\\/]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;','\\':'&#x5C;'}[s]));
}

// events
document.getElementById('mode').addEventListener('change', applyModeToAll);
<?php if ($hasBins): ?>
document.getElementById('warehouse_id').addEventListener('change', () => {
  const whId = parseInt(document.getElementById('warehouse_id').value || '0', 10);
  const hdr = document.getElementById('header_bin_id');
  if (hdr) {
    for (const opt of hdr.options) {
      if (!opt.value) continue;
      opt.disabled = (parseInt(opt.getAttribute('data-wh')||'0',10) !== whId);
    }
    hdr.value = '';
  }
  document.querySelectorAll('.binSel').forEach(sel => {
    for (const opt of sel.options) {
      if (!opt.value) continue;
      opt.disabled = (parseInt(opt.getAttribute('data-wh')||'0',10) !== whId);
    }
    sel.value = '';
  });
});
<?php endif; ?>

// init
addLine();
applyModeToAll();
</script>
</body>
</html>
