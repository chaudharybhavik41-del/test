<?php
/* Stock Transfer – items.material_code/name + auto-select item uom_id */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_permission('stores.transfer.manage');
$pdo = db();

/* Helpers */
function table_exists(PDO $pdo, string $name): bool {
  try { return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($name))->fetchColumn(); }
  catch (Throwable $e) { return false; }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetchColumn(); }
  catch (Throwable $e) { return false; }
}

/* Optional bins */
$hasBins = table_exists($pdo, 'bins');
$bins = [];
if ($hasBins) {
  $bins = $pdo->query("SELECT id, warehouse_id, COALESCE(name, CONCAT('BIN-',id)) AS name FROM bins ORDER BY 3")->fetchAll(PDO::FETCH_ASSOC);
}

/* Warehouses */
$warehouses = $pdo->query("SELECT id, COALESCE(name, CONCAT('WH-',id)) AS name FROM warehouses ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);

/* Items – your schema (material_code, name, uom_id, status) */
$items = $pdo->query("
  SELECT i.id, i.material_code, i.name, i.uom_id
  FROM items i
  WHERE i.status = 'active'
  ORDER BY i.material_code, i.name
  LIMIT 2000
")->fetchAll(PDO::FETCH_ASSOC);

/* UoM – prefer singular `uom`, fallback to `uoms` if needed */
$uomTable = table_exists($pdo,'uom') ? 'uom' : (table_exists($pdo,'uoms') ? 'uoms' : null);
$uoms = [];
if ($uomTable) {
  $hasCode = column_exists($pdo, $uomTable, 'code');
  $hasName = column_exists($pdo, $uomTable, 'name');
  $labelExpr = $hasCode ? 'code' : ($hasName ? 'name' : 'CAST(id AS CHAR)');
  $uoms = $pdo->query("SELECT id, $labelExpr AS label FROM `$uomTable` WHERE " . ($uomTable==='uom'?'status=\'active\'':'1=1') . " ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);
} else {
  // Backend allows null uom_id; keep going with a generic option
  $uoms = [['id'=>null,'label'=>'EA']];
}

/* Projects (optional) */
$projects = [];
try { $projects = $pdo->query("SELECT id, COALESCE(name, CONCAT('PRJ-',id)) AS name FROM projects ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Stock Transfer</title>
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
  </style>
</head>
<body>

<?php if (!$hasBins): ?>
  <div class="notice">Bins table not found — bin fields are hidden. Transfers will be by warehouse only.</div>
<?php endif; ?>

<form method="post" action="transfer_post.php" onsubmit="return validateForm()">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
  <div class="card">
    <h2>Stock Transfer</h2>
    <div class="row">
      <div class="field">
        <label>From Warehouse</label>
        <select name="from_warehouse_id" id="from_warehouse_id" required>
          <option value="">-- Select --</option>
          <?php foreach($warehouses as $w): ?>
            <option value="<?=$w['id']?>"><?=htmlspecialchars($w['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($hasBins): ?>
      <div class="field">
        <label>From Bin (optional)</label>
        <select name="from_bin_id" id="from_bin_id">
          <option value="">-- Any --</option>
          <?php foreach($bins as $b): ?>
            <option data-wh="<?=$b['warehouse_id']?>" value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="field">
        <label>To Warehouse</label>
        <select name="to_warehouse_id" id="to_warehouse_id" required>
          <option value="">-- Select --</option>
          <?php foreach($warehouses as $w): ?>
            <option value="<?=$w['id']?>"><?=htmlspecialchars($w['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($hasBins): ?>
      <div class="field">
        <label>To Bin (optional)</label>
        <select name="to_bin_id" id="to_bin_id">
          <option value="">-- Any --</option>
          <?php foreach($bins as $b): ?>
            <option data-wh="<?=$b['warehouse_id']?>" value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if (!empty($projects)): ?>
      <div class="field">
        <label>Project (optional)</label>
        <select name="project_id">
          <option value="">-- None --</option>
          <?php foreach($projects as $p): ?>
            <option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="field" style="flex:1;">
        <label>Remarks</label>
        <input type="text" name="remarks" placeholder="Optional remarks">
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Lines</h3>
    <table id="lines">
      <thead>
        <tr><th style="width:35%;">Item</th><th>UoM</th><th>Qty</th><th>Batch (optional)</th><th>Remarks</th><th></th></tr>
      </thead>
      <tbody></tbody>
    </table>
    <button class="btn" type="button" onclick="addLine()">+ Add line</button>
  </div>

  <div class="card">
    <button class="btn primary" type="submit">Post Transfer</button>
  </div>
</form>

<script>
const items = <?=json_encode(array_map(function($r){
  return ['id'=>(int)$r['id'], 'label'=>$r['material_code'].' - '.$r['name'], 'uom_id'=>(int)$r['uom_id']];
}, $items))?>;

const uoms = <?=json_encode($uoms)?>;
const hasBins = <?= $hasBins ? 'true' : 'false' ?>;

// Build a quick lookup: item_id -> default uom_id
const defaultUomByItem = {};
for (const it of items) defaultUomByItem[it.id] = it.uom_id;

function addLine(){
  const tbody = document.querySelector('#lines tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <select name="lines[item_id][]" class="itemSel" required>
        <option value="">-- Select Item --</option>
        ${items.map(it=>`<option value="${it.id}">${escapeHtml(it.label)}</option>`).join('')}
      </select>
    </td>
    <td>
      <select name="lines[uom_id][]" class="uomSel" ${uoms.length ? 'required' : ''}>
        <option value="">-- UoM --</option>
        ${uoms.map(u=>`<option value="${u.id===null?'':u.id}">${escapeHtml(u.label)}</option>`).join('')}
      </select>
    </td>
    <td><input type="number" name="lines[qty][]" min="0.001" step="0.001" required></td>
    <td><input type="number" name="lines[batch_id][]" step="1" min="1" placeholder="ID"></td>
    <td><input type="text" name="lines[remarks][]" placeholder="Optional"></td>
    <td><button class="btn" type="button" onclick="this.closest('tr').remove()">Remove</button></td>
  `;
  tbody.appendChild(tr);

  // Auto-select UoM immediately and keep in sync on item change
  const itemSel = tr.querySelector('.itemSel');
  const uomSel  = tr.querySelector('.uomSel');
  const applyDefault = () => {
    const itemId = parseInt(itemSel.value || '0', 10);
    const def = defaultUomByItem[itemId];
    if (!def) return;
    // Set only if that UoM exists in dropdown
    for (const opt of uomSel.options) {
      if (String(opt.value) === String(def)) { uomSel.value = String(def); break; }
    }
  };
  itemSel.addEventListener('change', applyDefault);
  // If the user adds a line and picks an item right away, this will run on change.
}

function validateForm(){
  const a = document.getElementById('from_warehouse_id').value;
  const b = document.getElementById('to_warehouse_id').value;
  if (a && b && a === b && !hasBins) {
    alert('Source and destination warehouse cannot be the same (no bins available).');
    return false;
  }
  return true;
}

function escapeHtml(str){
  return String(str).replace(/[&<>\"'`=\\/]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;','\\':'&#x5C;'}[s]));
}
</script>
</body>
</html>