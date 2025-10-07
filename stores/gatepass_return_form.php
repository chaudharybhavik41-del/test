<?php
/** PATH: /public_html/stores/gatepass_return_form.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.gatepass.manage');

$pdo = db();
$gp_id = (int)($_GET['gp_id'] ?? 0);
$whs = $pdo->query("SELECT id, code, name FROM warehouses WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("
  SELECT gp.*, w.code src_code, w.name src_name
  FROM gatepasses gp
  JOIN warehouses w ON w.id = gp.source_warehouse_id
  WHERE gp.id=?");
$st->execute([$gp_id]);
$gp = $st->fetch(PDO::FETCH_ASSOC);

$lines = [];
if ($gp) {
  $st2 = $pdo->prepare("
    SELECT gi.*, it.material_code, it.name item_name, u.code uom_code, u.name uom_name
    FROM gatepass_items gi
    JOIN items it ON it.id=gi.item_id
    LEFT JOIN uom u ON u.id=gi.uom_id
    WHERE gi.gp_id=?
    ORDER BY it.name");
  $st2->execute([$gp_id]);
  $lines = $st2->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = "Gate Pass Return";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <a href="gatepass_list.php?pending=1" class="btn btn-sm btn-outline-secondary">Back</a>
  </div>

  <?php if (!$gp): ?>
    <div class="alert alert-warning">Gate pass not found.</div>
  <?php else: ?>
    <div class="alert alert-info small">
      GP: <strong><?= htmlspecialchars($gp['gp_no']) ?></strong>
      &middot; Date: <?= htmlspecialchars($gp['gp_date']) ?>
      &middot; From: <span class="badge bg-secondary-subtle text-secondary border"><?= htmlspecialchars($gp['src_code']) ?></span> <?= htmlspecialchars($gp['src_name']) ?>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-md-6">
        <label class="form-label">Return to Warehouse</label>
        <select id="ret_wh" class="form-select form-select-sm">
          <?php foreach ($whs as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= ((int)$w['id'] === (int)$gp['source_warehouse_id'])?'selected':'' ?>>
              <?= htmlspecialchars($w['code'].' — '.$w['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>Item</th>
            <th class="text-center">UOM</th>
            <th class="text-end">Sent</th>
            <th class="text-end">Returned</th>
            <th style="width:140px" class="text-end">Return Now</th>
          </tr>
        </thead>
        <tbody id="tb">
          <?php foreach ($lines as $ln):
            $sent = (float)$ln['qty']; $ret = (float)$ln['qty_returned']; $bal = max($sent - $ret, 0); ?>
            <tr data-ln='<?= json_encode(['id'=>$ln['id'],'item_id'=>$ln['item_id'],'uom_id'=>$ln['uom_id'],'bal'=>$bal], JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_TAG) ?>'>
              <td><?= htmlspecialchars(($ln['material_code']?($ln['material_code'].' — '):'').$ln['item_name']) ?></td>
              <td class="text-center"><?= htmlspecialchars($ln['uom_code'] ?? $ln['uom_name'] ?? '') ?></td>
              <td class="text-end"><?= number_format($sent,3) ?></td>
              <td class="text-end"><?= number_format($ret,3) ?></td>
              <td><input type="number" min="0" max="<?= number_format($bal,3,'.','') ?>" step="0.001" class="form-control form-control-sm retQty text-end" value="<?= number_format($bal,3,'.','') ?>"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="text-end">
      <button id="postBtn" class="btn btn-primary btn-sm">Post Return</button>
    </div>
  <?php endif; ?>
</div>

<?php if ($gp): ?>
<script>
document.getElementById('postBtn').addEventListener('click', async ()=>{
  const btn = document.getElementById('postBtn'); btn.disabled=true; btn.textContent='Posting…';
  try {
    const ret_wh = parseInt(document.getElementById('ret_wh').value||'0',10);
    const lines = [];
    document.querySelectorAll('#tb tr').forEach(tr=>{
      const meta = JSON.parse(tr.getAttribute('data-ln'));
      const qty = parseFloat(tr.querySelector('.retQty').value || '0');
      if (qty>0) lines.push({gp_item_id: meta.id, item_id: meta.item_id, uom_id: meta.uom_id, qty});
    });
    if (!ret_wh) { alert('Select return warehouse'); return; }
    if (lines.length===0) { alert('Enter at least one return qty'); return; }

    const r = await fetch('_ajax/gp_return_post.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({gp_id: <?= (int)$gp_id ?>, warehouse_id: ret_wh, lines})
    });
    const raw = await r.text(); const s=raw.indexOf('{'), e=raw.lastIndexOf('}');
    const json = (s!=-1&&e!=-1&&e>s)?raw.slice(s,e+1):raw; const data = JSON.parse(json);
    if (data.ok) { alert('Return posted: '+data.gpr_no); location.href='gatepass_list.php?pending=1'; }
    else { alert('Return failed: '+(data.error||'Unknown')); }
  } finally { btn.disabled=false; btn.textContent='Post Return'; }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
