<?php
/** PATH: /public_html/stores/minmax_report.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('purchase.advice.view'); // view report
$can_create_advice = function_exists('has_permission') ? has_permission('purchase.advice.manage') : true;

$pdo = db();

/* ---------- filters ---------- */
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
$q            = trim((string)($_GET['q'] ?? '')); // search item code/name
$show_only_below = isset($_GET['below']) && $_GET['below']=='1';

$warehouses = $pdo->query("SELECT id, code, name FROM warehouses WHERE active=1 ORDER BY name")
                  ->fetchAll(PDO::FETCH_ASSOC);

/* ---------- SQL (joins BEFORE WHERE) ---------- */
$sql = "
  SELECT
    i.id AS item_id,
    i.uom_id,
    i.material_code,
    i.name AS item_name,

    p.warehouse_id,
    p.min_qty, p.max_qty, p.reorder_point, p.safety_stock, p.policy_mode,

    COALESCE(soh.qty, 0) AS onhand,

    /* reserved = specific-to-this-warehouse + global (NULL warehouse) */
    COALESCE(res_wh.qty, 0) + COALESCE(res_glob.qty, 0) AS reserved_qty,

    /* adaptive cache (optional) */
    ac.avg_daily_90d, ac.avg_daily_14d, ac.spike_ratio, ac.suggested_reorder_point,

    u.code AS uom_code, u.name AS uom_name
  FROM items_stock_policy p
  JOIN items i ON i.id = p.item_id
  LEFT JOIN uom u ON u.id = i.uom_id

  /* on-hand per item+warehouse */
  LEFT JOIN (
    SELECT item_id, warehouse_id, SUM(qty) qty
    FROM stock_onhand
    GROUP BY item_id, warehouse_id
  ) soh
    ON soh.item_id = p.item_id AND soh.warehouse_id = p.warehouse_id

  /* adaptive cache */
  LEFT JOIN policy_adaptive_cache ac
    ON ac.item_id = p.item_id AND ac.warehouse_id = p.warehouse_id

  /* reservations specific to this warehouse */
  LEFT JOIN (
    SELECT item_id, warehouse_id, SUM(qty) qty
    FROM item_reservations
    WHERE status = 'active'
    GROUP BY item_id, warehouse_id
  ) res_wh
    ON res_wh.item_id = p.item_id AND res_wh.warehouse_id = p.warehouse_id

  /* global reservations (warehouse_id IS NULL) */
  LEFT JOIN (
    SELECT item_id, SUM(qty) qty
    FROM item_reservations
    WHERE status = 'active' AND warehouse_id IS NULL
    GROUP BY item_id
  ) res_glob
    ON res_glob.item_id = p.item_id

  WHERE (? = 0 OR p.warehouse_id = ?)
";
$params = [$warehouse_id, $warehouse_id];

if ($q !== '') {
  $sql .= " AND (i.material_code LIKE ? OR i.name LIKE ?) ";
  $params[] = "%$q%";
  $params[] = "%$q%";
}

$sql .= " ORDER BY i.name ";

/* ---------- run ---------- */
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- compute metrics row-by-row ---------- */
$ADAPT_SPIKE_MIN_RATIO = 1.30;
$data = [];
foreach ($rows as $r) {
  $onhand = (float)$r['onhand'];
  $res    = (float)$r['reserved_qty'];
  $free   = max(0.0, $onhand - $res);

  $min  = (float)$r['min_qty'];
  $max  = (float)$r['max_qty'];
  $rop  = (float)$r['reorder_point'];
  $sfty = (float)$r['safety_stock'];
  $mode = (string)$r['policy_mode'];

  $avg90 = (float)($r['avg_daily_90d'] ?? 0);
  $avg14 = (float)($r['avg_daily_14d'] ?? 0);
  $ratio = (float)($r['spike_ratio'] ?? 0);
  $sugROP= (float)($r['suggested_reorder_point'] ?? 0);

  // base threshold
  $threshold = max($min, $rop, $sfty);

  // adaptive bump if enabled and spike detected
  $spike = false;
  if ($mode === 'adaptive') {
    $spike = ($ratio >= $ADAPT_SPIKE_MIN_RATIO) || ($avg14 > $avg90 && $avg14 >= 1 && $avg90 == 0);
    if ($spike && $sugROP > $threshold) {
      $threshold = $sugROP;
    }
  }

  // suggestion uses free (on-hand minus reserved)
  $suggest = ($free < $threshold) ? max(0.0, $max - $onhand) : 0.0;

  if ($show_only_below && $suggest <= 0.0) {
    continue; // filter to only the rows below threshold
  }

  $data[] = [
    'item_id'   => (int)$r['item_id'],
    'code'      => (string)$r['material_code'],
    'name'      => (string)$r['item_name'],
    'uom'       => (string)($r['uom_code'] ?? $r['uom_name'] ?? ''),
    'onhand'    => $onhand,
    'reserved'  => $res,
    'free'      => $free,
    'min'       => $min,
    'rop'       => $rop,
    'safety'    => $sfty,
    'max'       => $max,
    'mode'      => $mode,
    'spike'     => $spike,
    'ratio'     => $ratio,
    'thr'       => $threshold,
    'suggest'   => $suggest,
  ];
}

/* ---------- page ---------- */
$page_title = "Min/Max Report";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <div class="d-flex gap-2">
      <?php if ($can_create_advice): ?>
      <button id="btnCreateAdvice" class="btn btn-sm btn-primary">Create Purchase Advice</button>
      <?php endif; ?>
    </div>
  </div>

  <form class="row g-2 mb-3">
    <div class="col-auto">
      <select name="warehouse_id" class="form-select form-select-sm">
        <option value="0">All Warehouses</option>
        <?php foreach ($warehouses as $w): ?>
          <option value="<?= (int)$w['id'] ?>" <?= ((int)$w['id'] === $warehouse_id) ? 'selected' : '' ?>>
            <?= htmlspecialchars(($w['code'] ?? '') . ' — ' . $w['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <input class="form-control form-control-sm" name="q" placeholder="Search code / name" value="<?= htmlspecialchars($q) ?>">
    </div>
    <div class="col-auto form-check d-flex align-items-center">
      <input class="form-check-input me-1" type="checkbox" id="below" name="below" value="1" <?= $show_only_below?'checked':'' ?>>
      <label class="form-check-label" for="below">Only below threshold</label>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-secondary">Filter</button>
      <a class="btn btn-sm btn-outline-primary" href="?">Reset</a>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm align-middle" id="tbl">
      <thead class="table-light">
      <tr>
        <?php if ($can_create_advice): ?><th style="width:28px"><input type="checkbox" id="chkAll"></th><?php endif; ?>
        <th>Item</th>
        <th class="text-center">UOM</th>
        <th class="text-end">On-hand</th>
        <th class="text-end">Reserved</th>
        <th class="text-end">Free</th>
        <th class="text-end">Min</th>
        <th class="text-end">ROP</th>
        <th class="text-end">Safety</th>
        <th class="text-end">Max</th>
        <th>Mode</th>
        <th class="text-end">Threshold</th>
        <th class="text-end">Suggested</th>
        <th>Notes</th>
      </tr>
      </thead>
      <tbody>
      <?php if (!$data): ?>
        <tr><td colspan="<?= $can_create_advice?14:13 ?>" class="text-center text-muted py-4">No items.</td></tr>
      <?php else: foreach ($data as $r):
        $below = $r['free'] < $r['thr'];
        $rowCls = $below ? 'table-warning' : '';
      ?>
        <tr class="<?= $rowCls ?>">
          <?php if ($can_create_advice): ?>
          <td><input type="checkbox" class="rowChk" <?= $r['suggest']>0 ? '' : 'disabled' ?>
                     data-item='<?= htmlspecialchars(json_encode([
                       'item_id'=>$r['item_id']
                     ], JSON_UNESCAPED_UNICODE)) ?>'></td>
          <?php endif; ?>
          <td class="fw-semibold"><?= htmlspecialchars(($r['code'] ? ($r['code'].' — ') : '').$r['name']) ?></td>
          <td class="text-center"><?= htmlspecialchars($r['uom']) ?></td>
          <td class="text-end"><?= number_format($r['onhand'], 3) ?></td>
          <td class="text-end"><?= number_format($r['reserved'], 3) ?></td>
          <td class="text-end"><?= number_format($r['free'], 3) ?></td>
          <td class="text-end"><?= number_format($r['min'], 3) ?></td>
          <td class="text-end"><?= number_format($r['rop'], 3) ?></td>
          <td class="text-end"><?= number_format($r['safety'], 3) ?></td>
          <td class="text-end"><?= number_format($r['max'], 3) ?></td>
          <td>
            <?php if ($r['mode']==='adaptive'): ?>
              <span class="badge bg-info-subtle text-info border">adaptive</span>
              <?php if ($r['spike']): ?><span class="badge bg-danger-subtle text-danger border ms-1">spike<?= $r['ratio'] ? ' ×'.number_format($r['ratio'],2) : '' ?></span><?php endif; ?>
            <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary border">static</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= number_format($r['thr'], 3) ?></td>
          <td class="text-end fw-semibold"><?= number_format($r['suggest'], 3) ?></td>
          <td class="small text-muted"><?= $below ? 'Below threshold' : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($can_create_advice): ?>
  <div class="mt-3 text-end">
    <button id="btnCreateAdviceBottom" class="btn btn-sm btn-primary">Create Purchase Advice</button>
  </div>
  <?php endif; ?>

</div>

<?php if ($can_create_advice): ?>
<script>
const chkAll = document.getElementById('chkAll');
chkAll?.addEventListener('change', ()=>{
  document.querySelectorAll('.rowChk:not([disabled])').forEach(x=>{ x.checked = chkAll.checked; });
});

async function createAdvice() {
  const sel = Array.from(document.querySelectorAll('.rowChk:checked'));
  if (sel.length===0) { alert('Select at least one row with suggested qty.'); return; }

  const item_ids = sel.map(x => JSON.parse(x.dataset.item).item_id);
  const warehouse_id = <?= (int)$warehouse_id ?> || null;
  if (!warehouse_id) { alert('Please filter by a specific warehouse to create advice.'); return; }

  const btns = [document.getElementById('btnCreateAdvice'), document.getElementById('btnCreateAdviceBottom')].filter(Boolean);
  btns.forEach(b=>{ b.disabled=true; b.textContent='Creating…'; });

  try{
    const r = await fetch('_ajax/create_purchase_advice.php', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ warehouse_id, item_ids })
    });
    const raw = await r.text(); const s = raw.indexOf('{'), e = raw.lastIndexOf('}');
    const json = (s!=-1&&e!=-1&&e>s) ? raw.slice(s,e+1) : raw;
    const data = JSON.parse(json);
    if (data.ok) {
      alert('Purchase Advice created: '+data.advice_no);
      location.href = 'purchase_advice_view.php?id='+data.advice_id;
    } else {
      alert('Create failed: '+(data.error||'Unknown'));
    }
  } catch(e) {
    alert('Error: ' + (e?.message||e));
  } finally {
    btns.forEach(b=>{ b.disabled=false; b.textContent='Create Purchase Advice'; });
  }
}

document.getElementById('btnCreateAdvice')?.addEventListener('click', createAdvice);
document.getElementById('btnCreateAdviceBottom')?.addEventListener('click', createAdvice);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
