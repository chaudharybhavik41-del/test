<?php
/** PATH: /public_html/stores/purchase_advice_view.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('purchase.advice.view');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Invalid advice id');
}

/* -------- Header -------- */
$h = $pdo->prepare("
  SELECT pa.id, pa.advice_no, pa.advice_date, pa.warehouse_id, pa.status, pa.remarks,
         w.code AS wh_code, w.name AS wh_name,
         u.name  AS created_by_name
  FROM purchase_advice pa
  JOIN warehouses w ON w.id = pa.warehouse_id
  LEFT JOIN users u    ON u.id = pa.created_by
  WHERE pa.id = ?
");
$h->execute([$id]);
$hdr = $h->fetch(PDO::FETCH_ASSOC);
if (!$hdr) {
  http_response_code(404);
  exit('Purchase Advice not found');
}

/* -------- Lines -------- */
$ls = $pdo->prepare("
  SELECT ai.id, ai.item_id, ai.uom_id, ai.onhand, ai.min_qty, ai.max_qty,
         ai.reorder_point, ai.safety_stock, ai.suggested_qty, ai.remarks,
         it.material_code, it.name AS item_name,
         u.code AS uom_code, u.name AS uom_name
  FROM purchase_advice_items ai
  JOIN items it ON it.id = ai.item_id
  LEFT JOIN uom   u ON u.id = ai.uom_id
  WHERE ai.advice_id = ?
  ORDER BY it.name
");
$ls->execute([$id]);
$rows = $ls->fetchAll(PDO::FETCH_ASSOC);

/* Permissions */
$can_convert_indent = function_exists('has_permission') ? has_permission('purchase.indent.manage') : true;

$page_title = 'Purchase Advice';
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?> <span class="text-muted">#<?= htmlspecialchars($hdr['advice_no']) ?></span></h1>
    <div class="d-flex gap-2">
      <a href="purchase_advice_list.php" class="btn btn-sm btn-outline-secondary">Back</a>

      <!-- ✅ Convert to Indent (replaces Create PR) -->
      <?php if ($can_convert_indent): ?>
        <button id="btnToIndent" class="btn btn-sm btn-primary">Convert to Indent</button>
      <?php endif; ?>

      <!-- (No Create PR button anymore) -->
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-3">
      <div class="small text-muted">Advice No</div>
      <div class="fw-semibold"><?= htmlspecialchars($hdr['advice_no']) ?></div>
    </div>
    <div class="col-md-3">
      <div class="small text-muted">Advice Date</div>
      <div class="fw-semibold"><?= htmlspecialchars($hdr['advice_date']) ?></div>
    </div>
    <div class="col-md-4">
      <div class="small text-muted">Warehouse</div>
      <div class="fw-semibold"><span class="badge bg-secondary-subtle text-secondary border"><?= htmlspecialchars($hdr['wh_code']) ?></span> <?= htmlspecialchars($hdr['wh_name']) ?></div>
    </div>
    <div class="col-md-2">
      <div class="small text-muted">Status</div>
      <?php
        $cls = ['draft'=>'warning','approved'=>'success','cancelled'=>'danger'][$hdr['status']] ?? 'secondary';
      ?>
      <div><span class="badge bg-<?= $cls ?>-subtle text-<?= $cls ?> border"><?= htmlspecialchars($hdr['status']) ?></span></div>
    </div>
    <div class="col-12">
      <div class="small text-muted">Remarks</div>
      <div><?= htmlspecialchars($hdr['remarks'] ?? '—') ?></div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Item</th>
          <th class="text-center">UOM</th>
          <th class="text-end">On-hand</th>
          <th class="text-end">Min</th>
          <th class="text-end">ROP</th>
          <th class="text-end">Safety</th>
          <th class="text-end">Max</th>
          <th class="text-end">Suggested</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No lines.</td></tr>
        <?php else: $i=1; foreach ($rows as $r): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td class="fw-semibold"><?= htmlspecialchars(($r['material_code'] ? ($r['material_code'].' — ') : '').$r['item_name']) ?></td>
            <td class="text-center"><?= htmlspecialchars($r['uom_code'] ?? $r['uom_name'] ?? '') ?></td>
            <td class="text-end"><?= number_format((float)$r['onhand'], 3) ?></td>
            <td class="text-end"><?= number_format((float)$r['min_qty'], 3) ?></td>
            <td class="text-end"><?= number_format((float)$r['reorder_point'], 3) ?></td>
            <td class="text-end"><?= number_format((float)$r['safety_stock'], 3) ?></td>
            <td class="text-end"><?= number_format((float)$r['max_qty'], 3) ?></td>
            <td class="text-end fw-semibold"><?= number_format((float)$r['suggested_qty'], 3) ?></td>
            <td class="small text-muted"><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($can_convert_indent): ?>
<script>
document.getElementById('btnToIndent')?.addEventListener('click', async ()=>{
  if (!confirm('Create a Purchase Indent from this Advice?')) return;
  const b = document.getElementById('btnToIndent');
  b.disabled = true; b.textContent = 'Converting…';
  try {
    const resp = await fetch('_ajax/pa_to_indent.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ advice_id: <?= (int)$hdr['id'] ?> })
    });
    const raw = await resp.text();
    const s = raw.indexOf('{'), e = raw.lastIndexOf('}');
    const data = JSON.parse((s!=-1 && e!=-1 && e>s) ? raw.slice(s,e+1) : raw);
    if (data.ok) {
      // ✅ success: go to Indent View page if you have it
      // Change the path below if your indent view file name differs
      location.href = 'purchase_indent_view.php?id=' + data.indent_id;
    } else {
      alert('Failed: ' + (data.error || 'Unknown error'));
    }
  } catch (err) {
    alert('Error: ' + (err?.message || err));
  } finally {
    b.disabled = false; b.textContent = 'Convert to Indent';
  }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
