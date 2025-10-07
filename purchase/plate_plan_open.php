<?php
/** PATH: /public_html/purchase/plate_plan_open.php
 * PURPOSE:
 *  1) Ensure a plate plan exists for ?req_id=...
 *  2) Seed plate_plan_parts from rm_requirement_lines (missing only, idempotent).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = db();

header('Content-Type: text/html; charset=utf-8');

/* ---------------- helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** robust column check (avoids LIKE/charset quirks) */
function has_col(PDO $pdo, string $table, string $col): bool {
  try {
    $sql = "SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/** pick first non-empty existing field */
function pick(array $row, array $cands, $default=null) {
  foreach ($cands as $c) { if (array_key_exists($c, $row) && $row[$c] !== null && $row[$c] !== '') return $row[$c]; }
  return $default;
}

/** compute per-piece kg from L/W/T (mm) & density (g/cc) */
function piece_kg(?float $L, ?float $W, ?float $T, ?float $rho): ?float {
  if (!$L || !$W || !$T || !$rho) return null;
  $kg = ($L * $W * $T * $rho) / 1_000_000.0; // mm^3 * g/cc => kg
  return round($kg, 3);
}

/* --------------- inputs & quick validations --------------- */
$req_id = isset($_GET['req_id']) && ctype_digit((string)$_GET['req_id']) ? (int)$_GET['req_id'] : 0;
if ($req_id <= 0) { http_response_code(400); echo "Missing req_id"; exit; }

/* 0) If a plan already exists, capture its id; else create one safely */
$st = $pdo->prepare("SELECT id FROM plate_plans WHERE req_id=? ORDER BY id DESC LIMIT 1");
$st->execute([$req_id]);
$plan_id = (int)($st->fetchColumn() ?: 0);

if ($plan_id <= 0) {
  // Detect optional columns on plate_plans
  $has_plan_no   = has_col($pdo, 'plate_plans', 'plan_no');
  $has_project   = has_col($pdo, 'plate_plans', 'project_id');
  $has_status    = has_col($pdo, 'plate_plans', 'status');
  $has_createdat = has_col($pdo, 'plate_plans', 'created_at');

  // Try to fetch project_id from rm_requirements if present
  $project_id = null;
  try {
    if (has_col($pdo, 'rm_requirements', 'project_id')) {
      $r = $pdo->prepare("SELECT project_id FROM rm_requirements WHERE id=?");
      $r->execute([$req_id]);
      $pid = $r->fetchColumn();
      if ($pid !== false && $pid !== null) $project_id = (int)$pid;
    }
  } catch (Throwable $e) {}

  // Insert minimal plan
  $cols = ['req_id']; $vals = ['?']; $bind = [$req_id];
  if ($has_project && $project_id !== null) { $cols[]='project_id'; $vals[]='?';    $bind[]=$project_id; }
  if ($has_status)                            { $cols[]='status';     $vals[]='?';    $bind[]='draft'; }
  if ($has_createdat)                         { $cols[]='created_at'; $vals[]='NOW()'; } // literal NOW()

  $sql = "INSERT INTO plate_plans (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare($sql); $ins->execute($bind);
    $plan_id = (int)$pdo->lastInsertId();

    if ($has_plan_no) {
      $yy = date('Y');
      $seq = str_pad((string)$plan_id, 4, '0', STR_PAD_LEFT);
      $plan_no = "PP-{$yy}-{$seq}";
      $upd = $pdo->prepare("UPDATE plate_plans SET plan_no=? WHERE id=?");
      $upd->execute([$plan_no, $plan_id]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Could not create plate plan for req_id=".$req_id.". Error: ".h($e->getMessage());
    exit;
  }
}

/* 1) Seed plate_plan_parts from rm_requirement_lines (insert missing only) */

/* Verify required columns really exist (your table has them) */
$required = ['plan_id','req_line_id','item_id','desc_text','thickness_mm','density_gcc',
             'need_qty','per_piece_kg','total_need_kg','remaining_qty','sort_order'];
$missing = [];
foreach ($required as $c) {
  if (!has_col($pdo, 'plate_plan_parts', $c)) $missing[] = $c;
}
if ($missing) {
  // We’ll still open the plan, but report which columns are missing.
  header('Location: plate_plan_form.php?id='.$plan_id.'&seed_skipped=1&reason='.rawurlencode('Missing cols: '.implode(', ',$missing)));
  exit;
}

// gather requirement lines
$rlCols = [];
try { $get = $pdo->query("SHOW COLUMNS FROM rm_requirement_lines"); $rlCols = $get->fetchAll(PDO::FETCH_COLUMN, 0) ?: []; } catch (Throwable $e) {}

$has_item_id   = in_array('item_id', $rlCols, true);
$has_desc_text = in_array('desc_text', $rlCols, true) || in_array('description', $rlCols, true);
$has_qty       = in_array('need_qty', $rlCols, true) || in_array('qty', $rlCols, true) || in_array('required_qty', $rlCols, true);
$has_density   = in_array('density_gcc', $rlCols, true);
$has_thk       = in_array('thickness_mm', $rlCols, true);
$has_calcjson  = in_array('calc_detail', $rlCols, true);

// fetch lines, join items for name if available
$itemsHasName = has_col($pdo, 'items', 'name');
if ($itemsHasName && $has_item_id) {
  $st = $pdo->prepare("SELECT rl.*, it.name AS item_name
                       FROM rm_requirement_lines rl
                       LEFT JOIN items it ON it.id=rl.item_id
                       WHERE rl.req_id=?
                       ORDER BY rl.id");
} else {
  $st = $pdo->prepare("SELECT rl.* FROM rm_requirement_lines rl WHERE rl.req_id=? ORDER BY rl.id");
}
$st->execute([$req_id]);
$lines = $st->fetchAll(PDO::FETCH_ASSOC);

// existing req_line_ids in this plan
$existing = [];
$st2 = $pdo->prepare("SELECT req_line_id FROM plate_plan_parts WHERE plan_id=? AND req_line_id IS NOT NULL");
$st2->execute([$plan_id]);
foreach ($st2->fetchAll(PDO::FETCH_COLUMN, 0) as $rid) $existing[(int)$rid] = true;

$seeded = 0;
if ($lines) {
  $ins = $pdo->prepare("INSERT INTO plate_plan_parts
    (plan_id, req_line_id, item_id, desc_text, thickness_mm, density_gcc,
     need_qty, per_piece_kg, total_need_kg, remaining_qty, sort_order)
    VALUES (?,?,?,?, ?,?,?, ?,?, ?,?)");

  $sort = 0;
  if (!$pdo->inTransaction()) $pdo->beginTransaction();
  try {
    foreach ($lines as $rl) {
      $req_line_id = (int)$rl['id'];
      if (isset($existing[$req_line_id])) continue;

      $item_id = $has_item_id ? (int)$rl['item_id'] : 0;
      if ($item_id <= 0) continue;

      $desc = pick($rl, ['desc_text','description'], null);
      if (!$desc) $desc = isset($rl['item_name']) ? (string)$rl['item_name'] : 'Part '.$req_line_id;

      // qty
      $need_qty = (float)pick($rl, ['need_qty','required_qty','qty'], 0.0);
      if ($need_qty <= 0 && $has_calcjson) {
        $j = json_decode($rl['calc_detail'] ?? '[]', true);
        if (is_array($j) && isset($j['qty']) && is_numeric($j['qty'])) $need_qty = (float)$j['qty'];
      }
      if ($need_qty <= 0) $need_qty = 1.0;

      // thickness & density
      $thk = $has_thk ? ($rl['thickness_mm'] !== null ? (float)$rl['thickness_mm'] : null) : null;
      $rho = $has_density && $rl['density_gcc'] !== null ? (float)$rl['density_gcc'] : 7.85;

      // per piece kg from JSON (if dims present)
      $per_piece_kg = null;
      if ($has_calcjson) {
        $j = json_decode($rl['calc_detail'] ?? '[]', true);
        if (is_array($j)) {
          $L = isset($j['Lmm']) && is_numeric($j['Lmm']) ? (float)$j['Lmm'] : null;
          $W = isset($j['Wmm']) && is_numeric($j['Wmm']) ? (float)$j['Wmm'] : null;
          $T = $thk !== null ? $thk : (isset($j['Tmm']) && is_numeric($j['Tmm']) ? (float)$j['Tmm'] : null);
          $per_piece_kg = piece_kg($L, $W, $T, $rho);
        }
      }

      // Use thickness from rm_requirement_lines if present, else fall back to JSON Tmm
      $thk_eff = $thk;
      if ($thk_eff === null && isset($T) && $T !== null) { $thk_eff = (float)$T; }

            $total_need_kg = $per_piece_kg !== null ? round($per_piece_kg * $need_qty, 3) : null;

      $sort += 10;
      $ins->execute([
        $plan_id,
        $req_line_id,
        $item_id,
        $desc,
        $thk_eff,
        $rho,
        $need_qty,
        $per_piece_kg,
        $total_need_kg,
        $need_qty,   // remaining starts equal to need
        $sort
      ]);
      $seeded++;
    }
    
    // Backfill thickness_mm for any seeded parts where thickness is still NULL using calc_detail->Tmm
    try {
      $bf = $pdo->prepare("
        UPDATE plate_plan_parts p
        JOIN rm_requirement_lines rl ON rl.id = p.req_line_id
        SET p.thickness_mm = CAST(JSON_UNQUOTE(JSON_EXTRACT(rl.calc_detail, '$.Tmm')) AS DECIMAL(10,3))
        WHERE p.plan_id = ?
          AND p.thickness_mm IS NULL
          AND JSON_EXTRACT(rl.calc_detail, '$.Tmm') IS NOT NULL
      ");
      $bf->execute([$plan_id]);
    } catch (Throwable $e) {
      // non-fatal
    }

  if ($pdo->inTransaction()) $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Seeding is optional; still open the plan with a reason
    header('Location: plate_plan_form.php?id='.$plan_id.'&seed_skipped=1&reason='.rawurlencode($e->getMessage()));
    exit;
  }
}

/* 2) Done → open the plan (show how many we seeded, if any) */
$qs = 'id='.$plan_id;
if ($seeded > 0) $qs .= '&seeded='.$seeded;
header('Location: plate_plan_form.php?'.$qs);
exit;
