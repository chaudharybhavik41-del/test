<?php
/** PATH: /public_html/stores/requisitions_list.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.req.view');

$pdo = db();
$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'requested';
$where = []; $p = [];
if ($q !== '') {
  $where[] = "(mr.req_no LIKE ? OR pr.name LIKE ?)";
  $p[] = "%$q%"; $p[] = "%$q%";
}
if ($status !== '') { $where[] = "mr.status = ?"; $p[] = $status; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT mr.*, pr.code AS project_code, pr.name AS project_name
        FROM material_requisitions mr
        LEFT JOIN projects pr ON pr.id = mr.project_id
        $w
        ORDER BY mr.id DESC
        LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($p);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Material Requisitions";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <div>
      <?php if (has_permission('stores.req.manage')): ?>
      <a href="requisitions_form.php" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> New Requisition
      </a>
      <?php endif; ?>
      <a href="issues_list.php" class="btn btn-outline-secondary btn-sm">Issues</a>
    </div>
  </div>

  <form class="row g-2 mb-3">
    <div class="col-auto">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Search req no / project" value="<?= htmlspecialchars($q) ?>">
    </div>
    <div class="col-auto">
      <select name="status" class="form-select form-select-sm">
        <?php foreach (['requested'=>'Requested','issued'=>'Issued','cancelled'=>'Cancelled','draft'=>'Draft'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $k===$status?'selected':'' ?>>Status: <?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-secondary">Filter</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>Req No</th>
          <th>Date</th>
          <th>Project</th>
          <th>Requested By</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No requisitions found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($r['req_no']) ?></td>
            <td><?= htmlspecialchars($r['requested_date']) ?></td>
            <td>
              <?php if ($r['project_id']): ?>
                <span class="badge bg-info-subtle text-info border"><?= htmlspecialchars($r['project_code'] ?? '') ?></span>
                <?= htmlspecialchars($r['project_name'] ?? '') ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['requested_by_type']) ?> #<?= (int)$r['requested_by_id'] ?></td>
            <td>
              <span class="badge <?= $r['status']==='requested'?'bg-warning text-dark':($r['status']==='issued'?'bg-success':'bg-secondary') ?>">
                <?= strtoupper($r['status']) ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ($r['status']==='requested' && has_permission('stores.req.issue')): ?>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal"
                        data-bs-target="#issueModal" data-req-id="<?= (int)$r['id'] ?>" data-req-no="<?= htmlspecialchars($r['req_no']) ?>">
                  Issue
                </button>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Issue Modal -->
<div class="modal fade" id="issueModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Issue against <span id="imReqNo"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-2">
          <div class="col-md-4">
            <label class="form-label">Warehouse</label>
            <select id="imWarehouse" class="form-select form-select-sm">
              <?php
              $wh = $pdo->query("SELECT id, code, name FROM warehouses WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
              foreach ($wh as $w) {
                echo '<option value="'.(int)$w['id'].'">'.htmlspecialchars($w['code'].' — '.$w['name']).'</option>';
              }
              ?>
            </select>
          </div>
        </div>
        <div id="imLinesWrap" class="table-responsive small">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Item</th>
                <th class="text-center">UOM</th>
                <th class="text-center">Req</th>
                <th class="text-center">Issued</th>
                <th class="text-center">To Issue</th>
              </tr>
            </thead>
            <tbody id="imLinesBody">
              <tr><td colspan="5" class="text-center text-muted">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="form-text">Partial issue allowed. Leave zero to skip a line.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button id="imPostBtn" class="btn btn-primary btn-sm">Post Issue</button>
      </div>
    </div>
  </div>
</div>

<script>
let imReqId = 0;

function ensureDebugBox() {
  let box = document.getElementById('issueDebug');
  if (!box) {
    const container = document.querySelector('.container-fluid') || document.body;
    box = document.createElement('div');
    box.id = 'issueDebug';
    box.className = 'alert alert-warning d-none';
    container.insertBefore(box, container.firstChild);
  }
  return box;
}
function showDebug(msg) {
  const box = ensureDebugBox();
  box.textContent = msg;
  box.classList.remove('d-none');
}

// util
function esc(s){return (s??'').replace(/[&<>"']/g,m=>({ "&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;" }[m]));}
async function fetchJSON(url){
  const r = await fetch(url);
  const raw = await r.text();
  const s = raw.indexOf('{'), e = raw.lastIndexOf('}');
  const json = (s!==-1 && e!==-1 && e>s) ? raw.slice(s,e+1) : raw;
  return JSON.parse(json);
}

// ----- Load modal + lines -----
const issueModal = document.getElementById('issueModal');
issueModal?.addEventListener('show.bs.modal', async ev => {
  const btn = ev.relatedTarget;
  imReqId = parseInt(btn.getAttribute('data-req-id') || '0', 10);
  document.getElementById('imReqNo').textContent = btn.getAttribute('data-req-no') || '';
  await loadIssueLines();   // initial load
});

// reload on-hand if warehouse changes
document.getElementById('imWarehouse')?.addEventListener('change', ()=>loadIssueLines());

async function loadIssueLines(){
  const body = document.getElementById('imLinesBody');
  body.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Loading…</td></tr>';
  try {
    const data = await fetchJSON('requisitions_lines_api.php?req_id='+imReqId);
    const wh = parseInt(document.getElementById('imWarehouse').value||'0',10);
    body.innerHTML = '';
    if (!data.ok || !Array.isArray(data.lines) || data.lines.length===0) {
      body.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No lines found</td></tr>';
      return;
    }
    for (const ln of data.lines) {
      const rq = parseFloat(ln.qty_requested || 0);
      const isd = parseFloat(ln.qty_issued || 0);
      const bal = Math.max(rq - isd, 0);
      let onhand = null;
      if (wh>0) {
        try {
          const oh = await fetchJSON('stock_onhand_api.php?item_id='+ln.item_id+'&warehouse_id='+wh);
          onhand = (oh.ok ? parseFloat(oh.onhand || 0) : null);
        } catch(_){}
      }
      const badge = (onhand===null)
        ? '<span class="badge bg-secondary">—</span>'
        : (onhand >= bal
            ? `<span class="badge bg-success-subtle text-success border">On-hand: ${onhand.toFixed(3)}</span>`
            : `<span class="badge bg-danger-subtle text-danger border">On-hand: ${onhand.toFixed(3)}</span>`);

      body.insertAdjacentHTML('beforeend', `
        <tr data-line='${JSON.stringify(ln).replace(/'/g,"&#39;")}'>
          <td>${esc(ln.item_code ?? '')} — ${esc(ln.item_name ?? '')}<div class="small mt-1">${badge}</div></td>
          <td class="text-center">${esc(ln.uom_code ?? '')}</td>
          <td class="text-center">${rq.toFixed(3)}</td>
          <td class="text-center">${isd.toFixed(3)}</td>
          <td class="text-center" style="width:120px">
            <input type="number" step="0.001" min="0" max="${bal.toFixed(3)}"
                   class="form-control form-control-sm imQty" value="${bal.toFixed(3)}">
          </td>
        </tr>
      `);
    }
  } catch (e) {
    showDebug('Failed to load lines: ' + (e?.message || e));
  }
}

// ----- Post Issue -----
document.getElementById('imPostBtn')?.addEventListener('click', async () => {
  const btn = document.getElementById('imPostBtn');
  btn.disabled = true; btn.textContent = 'Posting…';

  try {
    const wh = parseInt(document.getElementById('imWarehouse').value || '0', 10);
    if (!imReqId || imReqId <= 0) { alert('Missing requisition id'); return; }
    if (!wh || wh <= 0) { alert('Please select a warehouse'); return; }

    const lines = [];
    document.querySelectorAll('#imLinesBody tr').forEach(tr => {
      const ln = JSON.parse((tr.getAttribute('data-line') || '{}').replace(/&#39;/g,"'"));
      const qty = parseFloat(tr.querySelector('.imQty')?.value || '0');
      if (qty > 0) {
        lines.push({
          req_item_id: parseInt(ln.id, 10),
          item_id: parseInt(ln.item_id, 10),
          uom_id: parseInt(ln.uom_id, 10),
          qty_to_issue: qty
        });
      }
    });

    if (lines.length === 0) { alert('Nothing to issue'); return; }

    const resp = await fetch('_ajax/req_post_issue.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ req_id: imReqId, warehouse_id: wh, lines })
    });

    const raw = await resp.text();
    if (!resp.ok) {
      showDebug('Issue POST failed (HTTP '+resp.status+'): ' + raw.slice(0, 1000));
      alert('Posting failed. See debug note at top.');
      return;
    }
    const s = raw.indexOf('{'), e = raw.lastIndexOf('}');
    const json = (s !== -1 && e !== -1 && e > s) ? raw.slice(s, e + 1) : raw;
    const data = JSON.parse(json);

    if (data.ok) location.reload();
    else { showDebug('API error: ' + (data.error || 'Unknown error')); alert('Issue failed: ' + (data.error || 'Unknown error')); }
  } catch (err) {
    showDebug('JS error: ' + (err?.message || err));
    alert('A script error occurred. See debug note at top.');
  } finally {
    btn.disabled = false; btn.textContent = 'Post Issue';
  }
});
</script>


<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
