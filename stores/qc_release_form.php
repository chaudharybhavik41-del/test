<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_permission('stores.qc.manage');

$pdo = db();
$warehouses = $pdo->query("SELECT id, name FROM warehouses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$bins  = $pdo->query("SELECT id, name, warehouse_id FROM bins ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query("SELECT id, CONCAT(code,' - ',name) label FROM items ORDER BY code LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
$uoms  = $pdo->query("SELECT id, code FROM uoms ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$csrf = csrf_token();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>QC Release</title>
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
    input,select{padding:6px}
  </style>
</head>
<body>
  <div class="card">
    <h2>QC Release</h2>
    <form method="post" action="qc_release_post.php" onsubmit="return validateForm()">
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
      <div class="row">
        <div class="field">
          <label>Warehouse</label>
          <select name="warehouse_id" id="warehouse_id" required>
            <option value="">-- Select --</option>
            <?php foreach($warehouses as $w): ?>
              <option value="<?=$w['id']?>"><?=htmlspecialchars($w['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>QC Bin (source)</label>
          <select name="qc_bin_id" id="qc_bin_id" required>
            <option value="">-- Select QC Bin --</option>
            <?php foreach($bins as $b): ?>
              <option data-wh="<?=$b['warehouse_id']?>" value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label>To Bin</label>
          <select name="to_bin_id" id="to_bin_id">
            <option value="">-- Any --</option>
            <?php foreach($bins as $b): ?>
              <option data-wh="<?=$b['warehouse_id']?>" value="<?=$b['id']?>"><?=htmlspecialchars($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1">
          <label>Remarks</label>
          <input type="text" name="remarks" placeholder="Optional">
        </div>
      </div>

      <div class="card">
        <h3>Lines</h3>
        <table id="lines">
          <thead><tr>
            <th style="width:35%;">Item</th><th>UoM</th><th>Qty</th><th>Batch (optional)</th><th>Remarks</th><th></th>
          </tr></thead>
          <tbody></tbody>
        </table>
        <button class="btn" type="button" onclick="addLine()">+ Add line</button>
      </div>

      <button class="btn primary" type="submit">Release from QC</button>
    </form>
  </div>

<script>
const items = <?=json_encode($items)?>;
const uoms  = <?=json_encode($uoms)?>;

function addLine(){
  const tbody = document.querySelector('#lines tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <select name="lines[item_id][]" required>
        <option value="">-- Select Item --</option>
        ${items.map(it=>`<option value="${it.id}">${escapeHtml(it.label)}</option>`).join('')}
      </select>
    </td>
    <td>
      <select name="lines[uom_id][]" required>
        <option value="">-- UoM --</option>
        ${uoms.map(u=>`<option value="${u.id}">${escapeHtml(u.code)}</option>`).join('')}
      </select>
    </td>
    <td><input type="number" name="lines[qty][]" min="0.001" step="0.001" required></td>
    <td><input type="number" name="lines[batch_id][]" step="1" min="1" placeholder="ID"></td>
    <td><input type="text" name="lines[remarks][]" placeholder="Optional"></td>
    <td><button class="btn" type="button" onclick="this.closest('tr').remove()">Remove</button></td>
  `;
  tbody.appendChild(tr);
}

function validateForm(){
  const wh = document.getElementById('warehouse_id').value;
  const qcBin = document.getElementById('qc_bin_id').value;
  if (!wh || !qcBin) { alert('Warehouse and QC bin are required'); return false; }
  return true;
}

function escapeHtml(str){
  return String(str).replace(/[&<>\"'`=\\/]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;','\\':'&#x5C;'}[s]));
}
</script>
</body>
</html>
