<?php
/** PATH: /public_html/dpr/dpr_entry.php
 * PURPOSE: Create & view DPR entries for a Process Work Order (PWO)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';   // also pulls in db.php and rbac.php via require_once
require_login();
if (function_exists('require_permission')) { @require_permission('dpr.entry'); }

header('Content-Type: text/html; charset=utf-8');

$pdo = db();

/* ---------- helpers ---------- */
function int_param(string $k, ?array $src = null): ?int {
  $src = $src ?? $_REQUEST;
  if (!isset($src[$k]) || $src[$k] === '') return null;
  return ctype_digit((string)$src[$k]) ? (int)$src[$k] : null;
}
function num_param(string $k, ?array $src = null): float {
  $src = $src ?? $_POST;
  if (!isset($src[$k]) || trim((string)$src[$k]) === '') return 0.0;
  $v = str_replace([',',' '], ['.',''], (string)$src[$k]);
  return is_numeric($v) ? (float)$v : 0.0;
}
function safe_shift(string $v): string {
  $v = strtoupper(trim($v));
  return in_array($v, ['A','B','C','GEN'], true) ? $v : 'GEN';
}

/* ---------- inputs ---------- */
$pwo_id = int_param('pwo_id');

/* ---------- POST: save DPR row ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
  $pwo_id = int_param('pwo_id', $_POST);
  if (!$pwo_id) {
    http_response_code(400);
    echo "<p style='color:#b00'>Missing or invalid PWO.</p>";
    exit;
  }

  $work_date     = $_POST['work_date'] ?? date('Y-m-d');
  $shift         = safe_shift($_POST['shift'] ?? 'GEN');
  $qty_done      = num_param('qty_done');
  $rejected_qty  = num_param('rejected_qty');
  $rework_qty    = num_param('rework_qty');
  $man_hours     = num_param('man_hours');
  $machine_hours = num_param('machine_hours');
  $machine_metric= num_param('machine_metric');
  $comm_qty      = num_param('comm_qty');
  $notes         = trim((string)($_POST['notes'] ?? ''));
  $entered_by    = current_user_id();

  // ensure the PWO exists
  $chk = $pdo->prepare("SELECT id FROM process_work_orders WHERE id = ?");
  $chk->execute([$pwo_id]);
  if (!$chk->fetch()) {
    http_response_code(404);
    echo "<p style='color:#b00'>PWO not found.</p>";
    exit;
  }

  // Insert into dpr_process_logs (all columns exist in your schema, including notes)
  $ins = $pdo->prepare("
    INSERT INTO dpr_process_logs
      (pwo_id, work_date, shift, qty_done, rejected_qty, rework_qty,
       man_hours, machine_hours, rate_applied, amount, entered_by, approved_by,
       notes, machine_metric, comm_qty)
    VALUES
      (:pwo_id, :work_date, :shift, :qty_done, :rejected_qty, :rework_qty,
       :man_hours, :machine_hours, NULL, NULL, :entered_by, NULL,
       :notes, :machine_metric, :comm_qty)
  ");
  $ins->execute([
    ':pwo_id'         => $pwo_id,
    ':work_date'      => $work_date,
    ':shift'          => $shift,
    ':qty_done'       => $qty_done,
    ':rejected_qty'   => $rejected_qty,
    ':rework_qty'     => $rework_qty,
    ':man_hours'      => $man_hours,
    ':machine_hours'  => $machine_hours,
    ':entered_by'     => $entered_by,
    ':notes'          => $notes,
    ':machine_metric' => $machine_metric,
    ':comm_qty'       => $comm_qty,
  ]);

  // redirect to avoid resubmit (PRG)
  $id = (int)$pdo->lastInsertId();
  header('Location: dpr_entry.php?pwo_id=' . $pwo_id . '&ok=1&new_id=' . $id);
  exit;
}

/* ---------- If no PWO chosen: show chooser ---------- */
if (!$pwo_id) {
  $rows = $pdo->query("
    SELECT
      pwo.id,
      pwo.status,
      pwo.plan_start_date,
      pwo.plan_end_date,
      wc.code  AS wc_code,
      wc.name  AS wc_name,
      p.code   AS proc_code,
      p.name   AS proc_name,
      bc.description AS comp_desc
    FROM process_work_orders AS pwo
    LEFT JOIN work_centers AS wc ON wc.id = pwo.work_center_id
    LEFT JOIN processes    AS p  ON p.id  = pwo.process_id
    LEFT JOIN bom_components AS bc ON bc.id = pwo.bom_component_id
    ORDER BY pwo.created_at DESC
    LIMIT 25
  ")->fetchAll();

  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <title>Select Work Order - DPR Entry</title>
    <style>
      body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 18px; }
      table { border-collapse: collapse; width: 100%; }
      th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; }
      th { background: #fafafa; }
      a.btn { display:inline-block; padding:6px 10px; border:1px solid #ccc; border-radius:6px; text-decoration:none; }
    </style>
  </head>
  <body>
    <h2>DPR Entry: Choose a Work Order</h2>
    <table>
      <thead>
        <tr>
          <th>PWO ID</th>
          <th>Process</th>
          <th>Work Center</th>
          <th>Component</th>
          <th>Status</th>
          <th>Plan Dates</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= htmlspecialchars(trim(($r['proc_code']??'').' '.$r['proc_name']??'')) ?></td>
          <td><?= htmlspecialchars(trim(($r['wc_code']??'').' '.$r['wc_name']??'')) ?></td>
          <td><?= htmlspecialchars($r['comp_desc'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['status'] ?? '') ?></td>
          <td>
            <?php
              $ps = $r['plan_start_date'] ? htmlspecialchars($r['plan_start_date']) : '';
              $pe = $r['plan_end_date']   ? htmlspecialchars($r['plan_end_date'])   : '';
              echo trim($ps . ($pe ? ' → ' . $pe : ''));
            ?>
          </td>
          <td><a class="btn" href="dpr_entry.php?pwo_id=<?= (int)$r['id'] ?>">Enter DPR</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </body>
  </html>
  <?php
  exit;
}

/* ---------- Load selected PWO details ---------- */
$info = $pdo->prepare("
  SELECT
    pwo.*,
    wc.code  AS wc_code, wc.name AS wc_name,
    p.code   AS proc_code, p.name AS proc_name,
    bc.description AS comp_desc, bc.id AS bom_component_id
  FROM process_work_orders AS pwo
  LEFT JOIN work_centers AS wc ON wc.id = pwo.work_center_id
  LEFT JOIN processes    AS p  ON p.id  = pwo.process_id
  LEFT JOIN bom_components AS bc ON bc.id = pwo.bom_component_id
  WHERE pwo.id = ?
");
$info->execute([$pwo_id]);
$pwo = $info->fetch();
if (!$pwo) {
  http_response_code(404);
  echo "<p style='color:#b00'>PWO not found.</p>";
  exit;
}

/* ---------- Existing logs ---------- */
$logs = $pdo->prepare("
  SELECT id, work_date, shift, qty_done, rejected_qty, rework_qty, man_hours, machine_hours, machine_metric, comm_qty, notes, created_at
  FROM dpr_process_logs
  WHERE pwo_id = ?
  ORDER BY work_date DESC, id DESC
");
$logs->execute([$pwo_id]);
$logRows = $logs->fetchAll();

$ok = isset($_GET['ok']);
$new_id = isset($_GET['new_id']) ? (int)$_GET['new_id'] : null;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>DPR Entry - PWO #<?= (int)$pwo_id ?></title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 18px; }
    .wrap { max-width: 1080px; margin: 0 auto; }
    .card { border:1px solid #eee; border-radius:10px; padding:16px; margin-bottom:18px; }
    h2,h3 { margin: 0 0 10px; }
    label { display:block; margin: 8px 0 4px; font-weight:600; }
    input[type="text"], input[type="date"], input[type="number"], select, textarea {
      width: 100%; padding: 8px; border:1px solid #ccc; border-radius:8px; box-sizing:border-box;
    }
    .row { display:flex; gap:12px; }
    .col { flex:1; }
    .actions { margin-top: 12px; }
    button { padding:8px 14px; border-radius:8px; border:1px solid #ccc; background:#f7f7f7; cursor:pointer; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
    th { background: #fafafa; }
    .ok { background:#e9f9ef; border:1px solid #b7e5c6; padding:10px; border-radius:8px; margin-bottom:12px; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h2>DPR Entry — PWO #<?= (int)$pwo['id'] ?></h2>
    <div><strong>Process:</strong> <?= htmlspecialchars(trim(($pwo['proc_code']??'').' '.$pwo['proc_name']??'')) ?></div>
    <div><strong>Work Center:</strong> <?= htmlspecialchars(trim(($pwo['wc_code']??'').' '.$pwo['wc_name']??'')) ?></div>
    <div><strong>Component:</strong> <?= htmlspecialchars($pwo['comp_desc'] ?? '') ?></div>
    <div><strong>Status:</strong> <?= htmlspecialchars($pwo['status'] ?? '') ?></div>
  </div>

  <?php if ($ok): ?>
    <div class="ok">Saved DPR entry <?= $new_id ? ('#'.(int)$new_id) : '' ?> successfully.</div>
  <?php endif; ?>

  <div class="card">
    <h3>Add DPR Entry</h3>
    <form method="post" action="dpr_entry.php">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="pwo_id" value="<?= (int)$pwo['id'] ?>">

      <div class="row">
        <div class="col">
          <label for="work_date">Work Date</label>
          <input id="work_date" name="work_date" type="date" value="<?= htmlspecialchars($_POST['work_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col">
          <label for="shift">Shift</label>
          <select id="shift" name="shift">
            <?php
              $cur = safe_shift($_POST['shift'] ?? 'GEN');
              foreach (['A','B','C','GEN'] as $s) {
                $sel = $cur === $s ? ' selected' : '';
                echo "<option value=\"$s\"$sel>$s</option>";
              }
            ?>
          </select>
        </div>
      </div>

      <div class="row">
        <div class="col">
          <label for="qty_done">Prod. Qty</label>
          <input id="qty_done" name="qty_done" type="number" step="0.001" value="<?= htmlspecialchars((string)($_POST['qty_done'] ?? '0')) ?>">
        </div>
        <div class="col">
          <label for="comm_qty">Billing/Comm Qty</label>
          <input id="comm_qty" name="comm_qty" type="number" step="0.001" value="<?= htmlspecialchars((string)($_POST['comm_qty'] ?? '0')) ?>">
        </div>
        <div class="col">
          <label for="machine_metric">Machine Metric</label>
          <input id="machine_metric" name="machine_metric" type="number" step="0.001" value="<?= htmlspecialchars((string)($_POST['machine_metric'] ?? '0')) ?>">
        </div>
      </div>

      <div class="row">
        <div class="col">
          <label for="rejected_qty">Rejected Qty</label>
          <input id="rejected_qty" name="rejected_qty" type="number" step="0.001" value="<?= htmlspecialchars((string)($_POST['rejected_qty'] ?? '0')) ?>">
        </div>
        <div class="col">
          <label for="rework_qty">Rework Qty</label>
          <input id="rework_qty" name="rework_qty" type="number" step="0.001" value="<?= htmlspecialchars((string)($_POST['rework_qty'] ?? '0')) ?>">
        </div>
      </div>

      <div class="row">
        <div class="col">
          <label for="man_hours">Man Hours</label>
          <input id="man_hours" name="man_hours" type="number" step="0.01" value="<?= htmlspecialchars((string)($_POST['man_hours'] ?? '0')) ?>">
        </div>
        <div class="col">
          <label for="machine_hours">Machine Hours</label>
          <input id="machine_hours" name="machine_hours" type="number" step="0.01" value="<?= htmlspecialchars((string)($_POST['machine_hours'] ?? '0')) ?>">
        </div>
      </div>

      <label for="notes">Notes</label>
      <textarea id="notes" name="notes" rows="3" maxlength="512"><?= htmlspecialchars((string)($_POST['notes'] ?? '')) ?></textarea>

      <div class="actions">
        <button type="submit">Save DPR</button>
        <a href="dpr_entry.php" style="margin-left:8px;">Choose another PWO</a>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Existing Entries</h3>
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Date</th>
          <th>Shift</th>
          <th>Qty</th>
          <th>Rejected</th>
          <th>Rework</th>
          <th>Man Hrs</th>
          <th>Machine Hrs</th>
          <th>Metric</th>
          <th>Comm Qty</th>
          <th>Notes</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logRows as $lr): ?>
        <tr>
          <td><?= (int)$lr['id'] ?></td>
          <td><?= htmlspecialchars($lr['work_date']) ?></td>
          <td><?= htmlspecialchars($lr['shift']) ?></td>
          <td><?= htmlspecialchars($lr['qty_done']) ?></td>
          <td><?= htmlspecialchars($lr['rejected_qty']) ?></td>
          <td><?= htmlspecialchars($lr['rework_qty']) ?></td>
          <td><?= htmlspecialchars($lr['man_hours']) ?></td>
          <td><?= htmlspecialchars($lr['machine_hours']) ?></td>
          <td><?= htmlspecialchars($lr['machine_metric']) ?></td>
          <td><?= htmlspecialchars($lr['comm_qty']) ?></td>
          <td><?= nl2br(htmlspecialchars($lr['notes'] ?? '')) ?></td>
          <td><?= htmlspecialchars($lr['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$logRows): ?>
        <tr><td colspan="12">No DPR entries yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
