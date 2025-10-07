<?php
/** PATH: /public_html/purchase/bom_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
if (function_exists('require_permission')) { @require_permission('purchase.bom.manage'); }

$pdo = db();
$pdo->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->query("SET collation_connection = 'utf8mb4_general_ci'");

/** Helpers */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function num($v, int $p = 3): string { return ($v===''||$v===null) ? '' : number_format((float)$v, $p, '.', ''); }

function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
  $q->execute([$table]); return (bool)$q->fetchColumn();
}

/** Generate next BOM number (BOM-YYYY-####). Never insert '' into a UNIQUE bom_no. */
function next_bom_no(PDO $pdo): string {
  $y = (int)date('Y');
  $st = $pdo->prepare("
    SELECT MAX(CAST(SUBSTRING_INDEX(bom_no, '-', -1) AS UNSIGNED)) AS maxseq
    FROM bom
    WHERE bom_no LIKE CONCAT('BOM-', ?, '-%')
  ");
  $st->execute([$y]);
  $seq = (int)($st->fetchColumn() ?: 0) + 1;
  return 'BOM-' . $y . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/** Load header (new or existing) */
$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

if ($is_edit) {
  $st = $pdo->prepare("SELECT * FROM bom WHERE id=?");
  $st->execute([$id]);
  $hdr = $st->fetch(PDO::FETCH_ASSOC);
  if (!$hdr) { http_response_code(404); exit('BOM not found'); }
} else {
  $hdr = [
    'bom_no' => '',
    'project_id' => null,
    'revision' => '',
    'status' => 'draft',
    'notes' => '',
  ];
}

/** Masters */
$projects = [];
try {
  $projects = $pdo->query("SELECT id, CONCAT(code,' — ',name) label FROM projects ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $projects = []; }

$uoms = [];
try {
  $uoms = $pdo->query("SELECT id, CONCAT(code,' — ',name) label FROM uom ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $uoms = []; }

$makes = [];
try {
  $makes = $pdo->query("SELECT id, name FROM makes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $makes = []; }

/** Spans (optional) */
$hasSpans   = table_exists($pdo, 'project_spans');
$hasBomSpan = table_exists($pdo, 'bom_spans');

$included_span_ids = [];
$spans = [];
if ($is_edit && $hasSpans && $hasBomSpan) {
  $q = $pdo->prepare("SELECT proj_span_id AS id FROM bom_spans WHERE bom_id=?");
  $q->execute([$id]);
  $included_span_ids = array_map('intval', array_column($q->fetchAll(PDO::FETCH_ASSOC), 'id'));
}
if ($hasSpans && !empty($hdr['project_id'])) {
  try {
    $st = $pdo->prepare("SELECT id, CONCAT(code,' — ',name) AS label FROM project_spans WHERE project_id=? ORDER BY id");
    $st->execute([(int)$hdr['project_id']]);
    $spans = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $spans = []; }
}

/** POST: Save */
$ok = $_GET['ok'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pdo->beginTransaction();
  try {
    // Header
    $project_id = (int)($_POST['project_id'] ?? 0) ?: null;
    $revision   = trim((string)($_POST['revision'] ?? ''));
    $status     = trim((string)($_POST['status'] ?? 'draft'));
    $notes      = trim((string)($_POST['notes'] ?? ''));

    if ($is_edit) {
      $pdo->prepare("UPDATE bom SET project_id=?, revision=?, status=?, notes=? WHERE id=?")
          ->execute([$project_id, $revision, $status, $notes, $id]);
    } else {
      $bom_no_in = trim((string)($_POST['bom_no'] ?? ''));
      $bom_no = $bom_no_in !== '' ? $bom_no_in : next_bom_no($pdo);
      $pdo->prepare("INSERT INTO bom (bom_no, project_id, revision, status, notes) VALUES (?,?,?,?,?)")
          ->execute([$bom_no, $project_id, $revision, $status, $notes]);
      $id = (int)$pdo->lastInsertId();
      $is_edit = true;
      $hdr['bom_no'] = $bom_no;
    }

    // Optional spans
    if ($hasSpans && $hasBomSpan) {
      $posted_spans = array_map('intval', (array)($_POST['span_ids'] ?? []));
      $pdo->prepare("DELETE FROM bom_spans WHERE bom_id=?")->execute([$id]);
      if ($posted_spans) {
        $insSp = $pdo->prepare("INSERT INTO bom_spans (bom_id, proj_span_id) VALUES (?,?)");
        foreach ($posted_spans as $sid) { $insSp->execute([$id, $sid]); }
      }
    }

    // Replace components
    $line_count = max(0, (int)($_POST['line_count'] ?? 0));
    $pdo->prepare("DELETE FROM bom_components WHERE bom_id=?")->execute([$id]);

    $ins = $pdo->prepare("
      INSERT INTO bom_components
        (bom_id, sr_no, span_no, span_id, part_id, line_code, segment_idx,
         description, item_id, uom_id,
         length_mm, width_mm, thickness_mm, density_gcc, scrap_allow_pct,
         qty, weight_kg, make_id, spec_text, remark, sort_order)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    for ($i = 1; $i <= $line_count; $i++) {
      // Names from UI: sr_, span_no_, span_id_, part_, code_, seg_, desc_, item_, uom_, len_, wid_, thk_,
      // rho_, scrap_, qty_, wkg_, make_, spec_, rem_
      $sr      = (int)($_POST["sr_$i"] ?? 0);
      $spanNo  = trim((string)($_POST["span_no_$i"] ?? ''));
      $spanId  = $_POST["span_id_$i"] !== '' ? (int)($_POST["span_id_$i"] ?? 0) : null;

      $partId  = trim((string)($_POST["part_$i"] ?? ''));
      $lineCode= trim((string)($_POST["code_$i"] ?? ''));
      $segIdx  = ($_POST["seg_$i"] ?? '') !== '' ? (int)$_POST["seg_$i"] : null;

      $desc    = trim((string)($_POST["desc_$i"] ?? ''));
      $itemId  = ($_POST["item_$i"] ?? '') !== '' ? (int)$_POST["item_$i"] : null;
      $uomId   = ($_POST["uom_$i"] ?? '') !== '' ? (int)$_POST["uom_$i"] : null;

      $len     = ($_POST["len_$i"] ?? '') !== '' ? (float)$_POST["len_$i"] : null; // mm
      $wid     = ($_POST["wid_$i"] ?? '') !== '' ? (float)$_POST["wid_$i"] : null; // mm
      $thk     = ($_POST["thk_$i"] ?? '') !== '' ? (float)$_POST["thk_$i"] : null; // mm
      $rho_gcc = ($_POST["rho_$i"] ?? '') !== '' ? (float)$_POST["rho_$i"] : null; // g/cc
      $scrap   = ($_POST["scrap_$i"] ?? '') !== '' ? (float)$_POST["scrap_$i"] : 0.0; // %

      $qty     = ($_POST["qty_$i"] ?? '') !== '' ? (float)$_POST["qty_$i"] : 1.0;
      $wkg_in  = ($_POST["wkg_$i"] ?? '') !== '' ? (float)$_POST["wkg_$i"] : null;

      $makeId  = ($_POST["make_$i"] ?? '') !== '' ? (int)$_POST["make_$i"] : null;
      $spec    = trim((string)($_POST["spec_$i"] ?? ''));
      $rem     = trim((string)($_POST["rem_$i"] ?? ''));

      // Compute weight if not provided
      $weight_kg = $wkg_in;
      if (($weight_kg === null || $weight_kg <= 0) && $len !== null && $wid !== null && $thk !== null && $rho_gcc !== null) {
        $vol_m3 = ($len/1000.0) * ($wid/1000.0) * ($thk/1000.0);
        $rho_kgm3 = $rho_gcc * 1000.0;
        $piece_kg = $vol_m3 * $rho_kgm3;
        if ($scrap > 0) $piece_kg *= (1.0 + $scrap/100.0);
        $weight_kg = $piece_kg * max(1.0, $qty);
      }

      $ins->execute([
        $id, $sr, $spanNo, $spanId, $partId, $lineCode, $segIdx,
        $desc, $itemId, $uomId,
        $len, $wid, $thk, $rho_gcc, $scrap,
        $qty, $weight_kg, $makeId, $spec, $rem, $i
      ]);
    }

    $pdo->commit();
    header("Location: bom_form.php?id={$id}&ok=" . urlencode("BOM saved"));
    exit;
  } catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "Save failed: " . h($e->getMessage());
    exit;
  }
}

/** UI */
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center">
    <h3 class="mb-0">BOM <?= $is_edit ? 'Edit' : 'Create' ?></h3>
    <div>
      <?php if ($is_edit): ?>
        <a class="btn btn-outline-secondary" href="/bom/routing_form.php?bom_id=<?= (int)$id ?>">Routing</a>
        <a class="btn btn-outline-secondary" href="../workorders/pwo_form.php?bom_id=<?= (int)$id ?>">Generate PWOs</a>
      <?php endif; ?>
      <a class="btn btn-outline-primary" href="bom_list.php">Back to List</a>
    </div>
  </div>
  <?php if ($ok): ?>
    <div class="alert alert-success my-3"><?= h($ok) ?></div>
  <?php endif; ?>

  <form method="post" class="mt-3" autocomplete="off">
    <div class="card mb-3">
      <div class="card-body row g-3">
        <div class="col-md-3">
          <label class="form-label">BOM No</label>
          <input type="text" name="bom_no" class="form-control" value="<?= h((string)($hdr['bom_no'] ?? '')) ?>" <?= $is_edit ? 'readonly' : '' ?>>
          <?php if (!$is_edit): ?><div class="form-text">Leave blank to auto-generate.</div><?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Project</label>
          <select class="form-select" name="project_id" onchange="document.getElementById('projChanged').value='1'">
            <option value="">—</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (int)($hdr['project_id'] ?? 0)===(int)$p['id'] ? 'selected' : '' ?>><?= h($p['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="projChanged" id="projChanged" value="0">
        </div>
        <div class="col-md-2">
          <label class="form-label">Revision</label>
          <input type="text" class="form-control" name="revision" value="<?= h((string)($hdr['revision'] ?? '')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <?php
              $statuses = ['draft'=>'Draft','active'=>'Active','obsolete'=>'Obsolete'];
              foreach ($statuses as $k=>$v) {
                $sel = ((string)($hdr['status'] ?? 'draft') === $k) ? 'selected' : '';
                echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= h((string)($hdr['notes'] ?? '')) ?></textarea>
        </div>
      </div>
    </div>

    <?php if ($hasSpans && $hasBomSpan): ?>
    <div class="card mb-3">
      <div class="card-header">Project Spans</div>
      <div class="card-body">
        <?php if ($spans): ?>
          <div class="row row-cols-1 row-cols-md-3 g-2">
            <?php foreach ($spans as $sp): ?>
              <?php $ck = in_array((int)$sp['id'], $included_span_ids, true) ? 'checked' : ''; ?>
              <div class="col">
                <label class="form-check">
                  <input class="form-check-input" type="checkbox" name="span_ids[]" value="<?= (int)$sp['id'] ?>" <?= $ck ?>>
                  <span class="form-check-label"><?= h($sp['label']) ?></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-muted">No spans found for selected project.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Components</span>
        <div class="small text-muted">Rows: <span id="rowCount">0</span></div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Span No</th>
              <th>Span Id</th>
              <th>Part</th>
              <th>Line Code</th>
              <th>Seg</th>
              <th>Description</th>
              <th style="min-width:240px">Item (Raw)</th>
              <th>UOM</th>
              <th>L (mm)</th>
              <th>W (mm)</th>
              <th>T (mm)</th>
              <th>ρ (g/cc)</th>
              <th>Scrap %</th>
              <th>Qty</th>
              <th>Weight (kg)</th>
              <th>Make</th>
              <th>Spec</th>
              <th>Remark</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="tbody">
            <!-- Existing rows (when editing) -->
            <?php if ($is_edit):
              $rows = $pdo->prepare("SELECT * FROM bom_components WHERE bom_id=? ORDER BY sort_order, id");
              $rows->execute([$id]);
              $i = 0;
              foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r):
                $i++;
            ?>
            <tr>
              <td><input class="form-control form-control-sm" name="sr_<?= $i ?>" value="<?= (int)$r['sr_no'] ?>"></td>
              <td><input class="form-control form-control-sm" name="span_no_<?= $i ?>" value="<?= h((string)$r['span_no']) ?>"></td>
              <td><input class="form-control form-control-sm" name="span_id_<?= $i ?>" value="<?= (int)$r['span_id'] ?>"></td>
              <td><input class="form-control form-control-sm" name="part_<?= $i ?>" value="<?= h((string)$r['part_id']) ?>"></td>
              <td><input class="form-control form-control-sm" name="code_<?= $i ?>" value="<?= h((string)$r['line_code']) ?>"></td>
              <td><input class="form-control form-control-sm" name="seg_<?= $i ?>" value="<?= h((string)$r['segment_idx']) ?>"></td>
              <td><input class="form-control form-control-sm" name="desc_<?= $i ?>" value="<?= h((string)$r['description']) ?>"></td>
              <td>
                <!-- Silently "All Raw": AJAX loads full eligible list -->
                <select class="form-select form-select-sm li-item" name="item_<?= $i ?>">
                  <?php if (!empty($r['item_id'])): ?>
                    <option value="<?= (int)$r['item_id'] ?>" selected>Loading…</option>
                  <?php else: ?>
                    <option value="">— Select —</option>
                  <?php endif; ?>
                </select>
                <div class="form-text small text-muted d-none li-item-hint">No items found</div>
              </td>
              <td>
                <select class="form-select form-select-sm" name="uom_<?= $i ?>">
                  <option value="">—</option>
                  <?php foreach ($uoms as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id'] === (int)$r['uom_id']) ? 'selected' : '' ?>><?= h($u['label']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input class="form-control form-control-sm" name="len_<?= $i ?>" value="<?= num($r['length_mm'],3) ?>" oninput="recalcRow(this.closest('tr'))"></td>
              <td><input class="form-control form-control-sm" name="wid_<?= $i ?>" value="<?= num($r['width_mm'],3) ?>" oninput="recalcRow(this.closest('tr'))"></td>
              <td><input class="form-control form-control-sm" name="thk_<?= $i ?>" value="<?= num($r['thickness_mm'],3) ?>" oninput="recalcRow(this.closest('tr'))"></td>
              <td><input class="form-control form-control-sm" name="rho_<?= $i ?>" value="<?= num($r['density_gcc'],6) ?>" oninput="recalcRow(this.closest('tr'))"></td>
              <td><input class="form-control form-control-sm" name="scrap_<?= $i ?>" value="<?= num($r['scrap_allow_pct'],3) ?>" oninput="recalcRow(this.closest('tr'))"></td>
              <td><input class="form-control form-control-sm" name="qty_<?= $i ?>" value="<?= num($r['qty'],3) ?>" oninput="recalcRow(this.closest('tr'))"></td>
              <td><input class="form-control form-control-sm text-end wkg" name="wkg_<?= $i ?>" value="<?= num($r['weight_kg'],3) ?>"></td>
              <td>
                <select class="form-select form-select-sm" name="make_<?= $i ?>">
                  <option value="">—</option>
                  <?php foreach ($makes as $mk): ?>
                    <option value="<?= (int)$mk['id'] ?>" <?= ((int)$mk['id'] === (int)$r['make_id']) ? 'selected' : '' ?>><?= h($mk['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input class="form-control form-control-sm" name="spec_<?= $i ?>" value="<?= h((string)$r['spec_text']) ?>"></td>
              <td><input class="form-control form-control-sm" name="rem_<?= $i ?>" value="<?= h((string)$r['remark']) ?>"></td>
              <td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger" onclick="rm(this)">&times;</button></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" onclick="addRow()">Add Row</button>
        <input type="hidden" name="line_count" id="line_count" value="0">
        <button class="btn btn-primary ms-auto">Save</button>
      </div>
    </div>
  </form>
</div>

<script>
function rm(btn){
  const tr = btn.closest('tr');
  tr.parentNode.removeChild(tr);
  recount();
}
function addRow(){
  const tb = document.getElementById('tbody');
  const i = tb.querySelectorAll('tr').length + 1;
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="form-control form-control-sm" name="sr_${i}" value="${i}"></td>
    <td><input class="form-control form-control-sm" name="span_no_${i}"></td>
    <td><input class="form-control form-control-sm" name="span_id_${i}"></td>
    <td><input class="form-control form-control-sm" name="part_${i}"></td>
    <td><input class="form-control form-control-sm" name="code_${i}"></td>
    <td><input class="form-control form-control-sm" name="seg_${i}"></td>
    <td><input class="form-control form-control-sm" name="desc_${i}"></td>
    <td>
      <select class="form-select form-select-sm li-item" name="item_${i}">
        <option value="">— Select —</option>
      </select>
      <div class="form-text small text-muted d-none li-item-hint">No items found</div>
    </td>
    <td>
      <select class="form-select form-select-sm" name="uom_${i}">
        <option value="">—</option>
        <?php foreach ($uoms as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input class="form-control form-control-sm" name="len_${i}" oninput="recalcRow(this.closest('tr'))"></td>
    <td><input class="form-control form-control-sm" name="wid_${i}" oninput="recalcRow(this.closest('tr'))"></td>
    <td><input class="form-control form-control-sm" name="thk_${i}" oninput="recalcRow(this.closest('tr'))"></td>
    <td><input class="form-control form-control-sm" name="rho_${i}" oninput="recalcRow(this.closest('tr'))"></td>
    <td><input class="form-control form-control-sm" name="scrap_${i}" value="0" oninput="recalcRow(this.closest('tr'))"></td>
    <td><input class="form-control form-control-sm" name="qty_${i}" value="1" oninput="recalcRow(this.closest('tr'))"></td>
    <td><input class="form-control form-control-sm text-end wkg" name="wkg_${i}"></td>
    <td>
      <select class="form-select form-select-sm" name="make_${i}">
        <option value="">—</option>
        <?php foreach ($makes as $mk): ?>
          <option value="<?= (int)$mk['id'] ?>"><?= h($mk['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input class="form-control form-control-sm" name="spec_${i}"></td>
    <td><input class="form-control form-control-sm" name="rem_${i}"></td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-link text-danger" onclick="rm(this)">&times;</button></td>
  `;
  tb.appendChild(tr);
  recount();
  wireBomRow(tr); // auto-load All Raw into the new row
  recalcRow(tr);
}
function recount(){
  const n = document.querySelectorAll('#tbody tr').length;
  document.getElementById('line_count').value = n;
  const el = document.getElementById('rowCount'); if (el) el.innerText = n;
}
function valOr0(v){ return v===''||v===null||isNaN(v) ? 0 : parseFloat(v); }
function recalcRow(tr){
  const len = valOr0(tr.querySelector('[name^="len_"]')?.value || '');
  const wid = valOr0(tr.querySelector('[name^="wid_"]')?.value || '');
  const thk = valOr0(tr.querySelector('[name^="thk_"]')?.value || '');
  const rho = valOr0(tr.querySelector('[name^="rho_"]')?.value || '');
  const scrap = valOr0(tr.querySelector('[name^="scrap_"]')?.value || '');
  const qty = Math.max(1, valOr0(tr.querySelector('[name^="qty_"]')?.value || '1'));
  let wkg = 0;
  if (len>0 && wid>0 && thk>0 && rho>0){
    const vol_m3 = (len/1000) * (wid/1000) * (thk/1000);
    const rho_kgm3 = rho * 1000.0;
    wkg = vol_m3 * rho_kgm3;
    if (scrap>0) wkg *= (1 + scrap/100.0);
    wkg *= qty;
  }
  const wEl = tr.querySelector('.wkg'); if (wEl) wEl.value = wkg ? wkg.toFixed(3) : '';
}

/* ---------- Items Loader (silent "All Raw") ---------- */
async function fetchJSON(url){
  const res = await fetch(url, {headers: {'Accept': 'application/json'}});
  if(!res.ok) throw new Error('HTTP '+res.status);
  return await res.json();
}
async function loadBomItems(tr, selectedId){
  const itemSel = tr.querySelector('.li-item');
  const hint = tr.querySelector('.li-item-hint');
  if (!itemSel) return;
  itemSel.innerHTML = '<option value="">Loading…</option>';
  try{
    // 0 means: All Raw (server filters to Raw-Material family)
    const js = await fetchJSON('bom_items_options.php?subcategory_id=0');
    itemSel.innerHTML = '<option value="">— Select —</option>';
    if (js && js.ok && Array.isArray(js.items) && js.items.length){
      js.items.forEach(it=>{
        const op = document.createElement('option');
        op.value = it.id; op.textContent = it.label;
        if (selectedId && String(selectedId) === String(it.id)) op.selected = true;
        itemSel.appendChild(op);
      });
      if (hint) hint.classList.add('d-none');
    } else {
      if (hint){ hint.textContent = 'No items found'; hint.classList.remove('d-none'); }
    }
  }catch(e){
    itemSel.innerHTML = '<option value="">— Select —</option>';
    if (hint){ hint.textContent = 'Failed to load items'; hint.classList.remove('d-none'); }
  }
}
function wireBomRow(tr){
  const itemSel= tr.querySelector('.li-item');
  if (!itemSel) return;
  const preSel = itemSel.querySelector('option[selected]')?.value || itemSel.value || '';
  loadBomItems(tr, preSel);
}
// wire all existing rows
document.querySelectorAll('#tbody tr').forEach(wireBomRow);

recount();
document.querySelectorAll('#tbody tr').forEach(tr => recalcRow(tr));
</script>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>
