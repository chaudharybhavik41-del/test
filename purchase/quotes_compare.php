<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
require_permission('purchase.quote.compare');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$inquiry_id = (int)($_GET['inquiry_id'] ?? 0);

/* Picker if none */
if ($inquiry_id<=0) {
  $choices = $pdo->query("
    SELECT i.id, i.inquiry_no, i.status
    FROM inquiries i
    WHERE i.status IN ('issued','closed')
    ORDER BY i.id DESC
    LIMIT 200
  ")->fetchAll(PDO::FETCH_ASSOC);

  include __DIR__.'/../ui/layout_start.php'; ?>
  <div class="container py-4">
    <h1 class="h4 mb-3">Quote Comparison</h1>
    <form class="row g-2" method="get">
      <div class="col-md-6">
        <label class="form-label">Inquiry</label>
        <select name="inquiry_id" class="form-select" required>
          <option value="">—</option>
          <?php foreach ($choices as $c): ?>
            <option value="<?=$c['id']?>"><?=htmlspecialchars($c['inquiry_no'])?> (<?=htmlspecialchars($c['status'])?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><button class="btn btn-primary w-100">Open</button></div>
      <div class="col-md-3"><a class="btn btn-outline-secondary w-100" href="/purchase/quotes_list.php">Back</a></div>
    </form>
  </div>
  <?php include __DIR__.'/../ui/layout_end.php'; exit;
}

/* Inquiry header */
$inq = $pdo->prepare("SELECT id, inquiry_no, status FROM inquiries WHERE id=?");
$inq->execute([$inquiry_id]);
$inq = $inq->fetch(PDO::FETCH_ASSOC);
if (!$inq) { http_response_code(404); echo "Inquiry not found"; exit; }

/* Lines (CI + RMI) */
$ci = $pdo->prepare("SELECT ii.id AS key_id, 'CI' AS src, ii.qty, u.code AS uom_code,
                            CONCAT(it.material_code,' — ',it.name) AS label
                     FROM inquiry_items ii
                     JOIN items it ON it.id=ii.item_id
                     JOIN uom u ON u.id=ii.uom_id
                     WHERE ii.inquiry_id=?
                     ORDER BY ii.id");
$ci->execute([$inquiry_id]); $ci=$ci->fetchAll(PDO::FETCH_ASSOC);

$rm = $pdo->prepare("SELECT il.id AS key_id, 'RMI' AS src, il.qty, u.code AS uom_code,
                            COALESCE(il.description, CONCAT('Raw Material Line #',il.id)) AS label
                     FROM inquiry_lines il
                     LEFT JOIN uom u ON u.id=il.qty_uom_id
                     WHERE il.inquiry_id=? AND (il.source_type IN ('RMI','GI') OR il.source_type IS NULL)
                     ORDER BY il.id");
$rm->execute([$inquiry_id]); $rm=$rm->fetchAll(PDO::FETCH_ASSOC);

$lines = array_merge($ci,$rm);

/* Suppliers that have quotes (or not) */
$quotes = $pdo->prepare("SELECT iq.id AS quote_id, iq.supplier_id, p.name AS supplier_name, iq.status, iq.total_after_tax
                         FROM inquiry_quotes iq
                         JOIN parties p ON p.id=iq.supplier_id
                         WHERE iq.inquiry_id=?
                         ORDER BY p.name, iq.id");
$quotes->execute([$inquiry_id]);
$quotes = $quotes->fetchAll(PDO::FETCH_ASSOC);
if (!$quotes) {
  include __DIR__.'/../ui/layout_start.php'; ?>
  <div class="container py-4">
    <h1 class="h4 mb-3">Quote Comparison</h1>
    <div class="alert alert-info">No quotes yet for Inquiry <strong><?=htmlspecialchars($inq['inquiry_no'])?></strong>.</div>
    <a class="btn btn-outline-secondary" href="/purchase/quotes_compare.php">Choose another Inquiry</a>
  </div>
  <?php include __DIR__.'/../ui/layout_end.php'; exit;
}

/* Supplier list */
$suppliers=[]; foreach($quotes as $q){ $suppliers[$q['supplier_id']] = $q['supplier_name']; }

/* Price map per line */
$priceMap = []; // [src:key_id][supplier_id] => row
$st=$pdo->prepare("SELECT q.quote_id, q.src, q.inquiry_item_id, q.inquiry_line_id, q.unit_price, q.discount_percent, q.tax_percent,
                          q.delivery_days, q.remarks, s.supplier_id, q.line_total_after_tax
                   FROM inquiry_quote_items q
                   JOIN inquiry_quotes s ON s.id=q.quote_id
                   WHERE s.inquiry_id=?
                   ORDER BY COALESCE(q.src,'CI'), q.inquiry_item_id, q.inquiry_line_id, s.supplier_id");
$st->execute([$inquiry_id]);
while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
  $src = $r['src'] ?: 'CI';
  $kid = $src==='RMI' ? (int)$r['inquiry_line_id'] : (int)$r['inquiry_item_id'];
  if ($kid<=0) continue;
  $priceMap[$src.':'.$kid][(int)$r['supplier_id']] = $r;
}

/* Previously locked selections (works after the tiny SQL change) */
$locked = []; // [src:key_id] => supplier_id
$st=$pdo->prepare("SELECT src, inquiry_item_id, inquiry_line_id, supplier_id
                   FROM inquiry_quote_selections WHERE inquiry_id=?");
$st->execute([$inquiry_id]);
while($r=$st->fetch(PDO::FETCH_ASSOC)){
  $src = $r['src'] ?: 'CI';
  $kid = $src==='RMI' ? (int)$r['inquiry_line_id'] : (int)$r['inquiry_item_id'];
  if ($kid>0) $locked[$src.':'.$kid] = (int)$r['supplier_id'];
}

include __DIR__.'/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Quote Comparison – <?=htmlspecialchars($inq['inquiry_no'])?></h1>
    <form method="post" action="/purchase/quotes_lock.php" class="d-flex gap-2" onsubmit="return confirm('Lock selection for this inquiry?');">
      <input type="hidden" name="_action" value="lock">
      <input type="hidden" name="inquiry_id" value="<?=$inquiry_id?>">
      <input type="hidden" id="selection_json" name="selection_json" value="[]">
      <button class="btn btn-primary btn-sm" type="submit">Lock Selection</button>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th style="min-width:360px">Item</th>
          <?php foreach ($suppliers as $sid=>$name): ?>
            <th class="text-center" style="min-width:220px"><?=htmlspecialchars($name)?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody id="cmpBody">
        <?php foreach ($lines as $ln):
          $key = ($ln['src'] ?: 'CI').':'.$ln['key_id'];
          $isLocked = array_key_exists($key, $locked);
        ?>
          <tr data-key="<?=htmlspecialchars($key)?>" class="<?= $isLocked ? 'table-success' : '' ?>">
            <td>
              <div><strong><?=htmlspecialchars($ln['label'])?></strong> <span class="badge bg-secondary ms-1"><?=htmlspecialchars($ln['src'])?></span></div>
              <div class="text-muted small">Qty: <?=number_format((float)$ln['qty'],3)?> <?=htmlspecialchars($ln['uom_code'])?></div>
              <input type="hidden" class="sel_supplier_id" value="<?= $isLocked ? (int)$locked[$key] : '' ?>">
            </td>
            <?php foreach ($suppliers as $sid=>$name):
              $row = $priceMap[$key][$sid] ?? null;
              $chosen = $isLocked ? ((int)$locked[$key] === $sid) : false;
              $disabled = $isLocked ? 'disabled' : '';
            ?>
              <td>
                <?php if ($row): ?>
                  <div class="form-check">
                    <input class="form-check-input pick" type="radio" name="pick_<?=htmlspecialchars($key)?>" data-supplier="<?=$sid?>" <?=$chosen?'checked':''?> <?=$disabled?>>
                    <label class="form-check-label small">Select</label>
                  </div>
                  <div class="small">Unit: <?=number_format((float)$row['unit_price'], 2)?></div>
                  <div class="small">Disc %: <?=number_format((float)$row['discount_percent'], 2)?></div>
                  <div class="small">Tax %: <?=number_format((float)$row['tax_percent'], 2)?></div>
                  <div class="small">Days: <?= isset($row['delivery_days']) ? htmlspecialchars((string)$row['delivery_days']) : '' ?></div>
                  <div class="small">Line Total: <strong><?=number_format((float)($row['line_total_after_tax'] ?? 0),2)?></strong></div>
                  <input type="hidden" class="quote_id_<?=$sid?>" value="<?= (int)$row['quote_id'] ?>">
                <?php else: ?>
                  <div class="text-muted small">— no quote —</div>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <a class="btn btn-outline-secondary" href="/purchase/quotes_compare.php">Choose another Inquiry</a>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  const body = document.getElementById('cmpBody');
  const selJson = document.getElementById('selection_json');

  body.querySelectorAll('input.pick').forEach(r=>{
    r.addEventListener('change', ()=>{
      const tr = r.closest('tr');
      tr.querySelector('.sel_supplier_id').value = r.getAttribute('data-supplier');
    });
  });

  document.querySelector('form[action="/purchase/quotes_lock.php"]').addEventListener('submit', ()=>{
    const out=[];
    body.querySelectorAll('tr[data-key]').forEach(tr=>{
      const key = tr.getAttribute('data-key'); // CI:12 or RMI:5
      const [src, id] = key.split(':');
      const sid = Number(tr.querySelector('.sel_supplier_id').value || 0);
      if (!sid) return;
      const qidInput = tr.querySelector(`.quote_id_${sid}`);
      const quote_id = qidInput ? Number(qidInput.value || 0) : 0;
      if (!quote_id) return;
      out.push({
        src, supplier_id: sid, quote_id,
        inquiry_item_id: src==='CI' ? Number(id) : null,
        inquiry_line_id: src==='RMI'? Number(id) : null
      });
    });
    selJson.value = JSON.stringify(out);
  });
});
</script>
<?php include __DIR__.'/../ui/layout_end.php';