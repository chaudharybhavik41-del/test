<?php
/** PATH: /public_html/purchase/req_wizard.php
 * PURPOSE: Create or reuse an rm_requirement from a BOM, explode components to lines,
 *          then open (or create) the Plate Plan for that requirement.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/numbering.php';

require_login();
$pdo = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- load recent BOMs for the picker ---------------- */
$boms = $pdo->query("
  SELECT b.id,
         CONCAT(COALESCE(b.bom_no, CONCAT('BOM-', b.id)),' — P:',COALESCE(pr.code,'')) AS label
  FROM bom b
  LEFT JOIN projects pr ON pr.id=b.project_id
  ORDER BY b.id DESC
  LIMIT 300
")->fetchAll();

/* ---------------- POST: create or reuse requirement ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $bom_id    = isset($_POST['bom_id']) ? (int)$_POST['bom_id'] : 0;
  $needed_by = !empty($_POST['needed_by']) ? $_POST['needed_by'] : null;
  $force_new = isset($_POST['force_new']) && $_POST['force_new'] === '1';

  // Validate BOM
  $bom = $pdo->prepare("SELECT * FROM bom WHERE id=?");
  $bom->execute([$bom_id]);
  $B = $bom->fetch();
  if (!$B) {
    http_response_code(400);
    exit('BOM not found');
  }

  // 1) Reuse existing requirement for this BOM (unless force_new)
  if (!$force_new) {
    $ex = $pdo->prepare("
      SELECT id FROM rm_requirements
      WHERE bom_id=? AND status IN ('draft','issued')
      ORDER BY id DESC
      LIMIT 1
    ");
    $ex->execute([$bom_id]);
    $existing_req_id = (int)($ex->fetchColumn() ?: 0);
    if ($existing_req_id) {
      header("Location: plate_plan_open.php?req_id=" . $existing_req_id);
      exit;
    }
  }

  // 2) Create a NEW requirement + explode components → lines (transaction, guarded)
  try {
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); }

    // New requirement number (uses your numbering.php)
    $req_no = next_no('REQ');

    // Insert requirement header (keeping your columns & defaults)
    $insReq = $pdo->prepare("
      INSERT INTO rm_requirements (req_no, project_id, bom_id, status, lot_qty, needed_by, created_at)
      VALUES (?, ?, ?, 'issued', 1, ?, NOW())
    ");
    $insReq->execute([
      $req_no,
      (int)($B['project_id'] ?? 0) ?: null,
      $bom_id,
      $needed_by
    ]);
    $req_id = (int)$pdo->lastInsertId();

    // Fetch BOM components (use your existing columns)
    $comp = $pdo->prepare("
      SELECT id, item_id, span_id, uom_id, qty,
             length_mm, width_mm, thickness_mm, density_gcc, scrap_allow_pct,
             span_no, part_id, line_code, segment_idx,
             sort_order
      FROM bom_components
      WHERE bom_id=?
      ORDER BY COALESCE(sort_order, id)
    ");
    $comp->execute([$bom_id]);

    // Prepare line insert (keeps your structure)
    $insLine = $pdo->prepare("
      INSERT INTO rm_requirement_lines
        (req_id, bom_component_id, item_id, proj_span_id,
         need_qty, need_uom_id, need_weight_kg, calc_detail, needed_by)
      VALUES (?,?,?,?, ?,?,?, ?,?)
    ");

    // Explode each component → line
    while ($c = $comp->fetch()) {
      $qty = (float)($c['qty'] ?? 0);
      if ($qty <= 0) { $qty = 1.0; }

      // per-piece kg if dims are known; using density_gcc (g/cc)
      $ppkg = (isset($c['length_mm'], $c['width_mm'], $c['thickness_mm']) &&
               $c['length_mm'] && $c['width_mm'] && $c['thickness_mm'])
        ? (($c['length_mm']/1000.0) * ($c['width_mm']/1000.0) * ($c['thickness_mm']/1000.0)
           * ((($c['density_gcc'] ?: 7.85) * 1000.0)))
        : null;

      $scr = !empty($c['scrap_allow_pct']) ? (1.0 + ((float)$c['scrap_allow_pct'] / 100.0)) : 1.0;
      $line_kg = $ppkg ? round($ppkg * $qty * $scr, 3) : null;

      // calc_detail JSON (Plate Plan will read Lmm/Wmm/Tmm/qty from here)
      $calc = [
        'Lmm'         => $c['length_mm'],
        'Wmm'         => $c['width_mm'],
        'Tmm'         => $c['thickness_mm'],
        'rho'         => $c['density_gcc'],
        'qty'         => $qty,
        'scrap_pct'   => $c['scrap_allow_pct'],
        'per_piece_kg'=> $ppkg,
        'line_kg'     => $line_kg,
        'span_no'     => $c['span_no'],
        'part_id'     => $c['part_id'],
        'line_code'   => $c['line_code'],
        'segment'     => $c['segment_idx']
      ];

      $insLine->execute([
        $req_id,
        (int)$c['id'],
        (int)($c['item_id'] ?: 1),                              // keep your fallback
        (int)($c['span_id'] ?: 0) ?: null,                      // proj_span_id
        $qty,
        (int)($c['uom_id'] ?: 0) ?: null,
        $line_kg,
        json_encode($calc, JSON_UNESCAPED_UNICODE),
        $needed_by
      ]);
    }

    if ($pdo->inTransaction()) { $pdo->commit(); }

    header("Location: plate_plan_open.php?req_id=" . $req_id);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo "Requirement creation failed: " . h($e->getMessage());
    exit;
  }
}

/* ---------------- GET: show form + recent requirements ---------------- */
$recent = $pdo->query("
  SELECT r.id, r.req_no, r.created_at, b.bom_no, pr.code AS pcode
  FROM rm_requirements r
  JOIN bom b ON b.id=r.bom_id
  LEFT JOIN projects pr ON pr.id=b.project_id
  WHERE r.status IN ('draft','issued')
  ORDER BY r.id DESC
  LIMIT 50
")->fetchAll();

include __DIR__ . '/../ui/layout_start.php'; ?>
<div class="container py-4">
  <h3 class="mb-3">Requirements Wizard</h3>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">BOM</label>
      <select name="bom_id" class="form-select" required>
        <option value="">— Select —</option>
        <?php foreach ($boms as $b): ?>
          <option value="<?= (int)$b['id'] ?>"><?= h($b['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">
        If a requirement already exists for this BOM, we’ll reuse it (unless you force a new one).
      </div>
    </div>

    <div class="col-md-3">
      <label class="form-label">Needed By</label>
      <input type="date" name="needed_by" class="form-control">
    </div>

    <div class="col-md-3 d-flex align-items-end">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="force_new" id="force_new" value="1">
        <label class="form-check-label" for="force_new">Create a new requirement (don’t reuse)</label>
      </div>
    </div>

    <div class="col-12">
      <button class="btn btn-primary">Proceed</button>
    </div>
  </form>

  <hr class="my-4">

  <h6>Recent open requirements</h6>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Req No</th>
          <th>BOM</th>
          <th>Project</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="5" class="text-muted">None yet.</td></tr>
        <?php else: foreach ($recent as $r): ?>
          <tr>
            <td><?= h($r['req_no']) ?></td>
            <td><?= h($r['bom_no']) ?></td>
            <td><?= h((string)$r['pcode']) ?></td>
            <td><?= h((string)$r['created_at']) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="plate_plan_open.php?req_id=<?= (int)$r['id'] ?>">Open Plate Plan</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../ui/layout_end.php';
