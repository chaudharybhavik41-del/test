<?php
/** PATH: /public_html/purchase/plate_lot_finalize.php
 * BUILD: 2025-10-03T09:27:07 IST (Idempotent autolot; unique child remnant IDs; safe available area)
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = db();
header('Content-Type: text/html; charset=utf-8');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (!function_exists('has_table')) {
  function has_table(PDO $pdo, string $t): bool {
    try { $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $st->execute([$t]); return (bool)$st->fetchColumn(); }
    catch(Throwable $e){ return false; }
  }
}
function dims_from_calc(?string $json): array {
  if (!$json) return [null,null,null];
  $j = json_decode($json, true);
  if (!is_array($j)) return [null,null,null];
  $L = null; foreach (['Lmm','L','length_mm','len','Length'] as $k) if (isset($j[$k]) && is_numeric($j[$k])) { $L=(float)$j[$k]; break; }
  $W = null; foreach (['Wmm','W','width_mm','wid','Width']  as $k) if (isset($j[$k]) && is_numeric($j[$k])) { $W=(float)$j[$k]; break; }
  $T = null; foreach (['Tmm','T','thickness_mm','Thk']      as $k) if (isset($j[$k]) && is_numeric($j[$k])) { $T=(float)$j[$k]; break; }
  return [$L,$W,$T];
}

$plate_id = (int)($_GET['plate_id'] ?? 0);
if ($plate_id<=0) { http_response_code(400); echo "Missing plate_id"; exit; }

/* Load plate + plan */
$st = $pdo->prepare("SELECT p.*, pl.plan_no, pl.project_id, pl.id AS plan_id_real
                     FROM plate_plan_plates p
                     LEFT JOIN plate_plans pl ON pl.id = p.plan_id
                     WHERE p.id=?");
$st->execute([$plate_id]); $plate = $st->fetch();
if (!$plate) { http_response_code(404); echo "Plate not found."; exit; }
$plan_id = (int)$plate['plan_id_real'];
$lot_id  = (int)($plate['source_lot_id'] ?? 0);
$stockTables = has_table($pdo,'stock_lots');

/* Load allocations & compute used area */
$allocs = $pdo->prepare("
  SELECT a.id AS alloc_id, a.part_id, a.alloc_qty, a.allocated_area_mm2,
         rl.calc_detail, pp.thickness_mm
  FROM plate_plan_allocations a
  LEFT JOIN plate_plan_parts pp ON pp.id = a.part_id
  LEFT JOIN rm_requirement_lines rl ON rl.id = pp.req_line_id
  WHERE a.plan_id=? AND a.plate_id=?
");
$allocs->execute([$plan_id,$plate_id]);
$used = 0; $missing=[];
while ($r=$allocs->fetch(PDO::FETCH_ASSOC)) {
  $area = (int)$r['allocated_area_mm2'];
  if (!$area) {
    [$L,$W,$T] = dims_from_calc($r['calc_detail'] ?? null);
    if ($L && $W) { $area = (int)round(floor($L)*floor($W)*(int)$r['alloc_qty']); }
    else $missing[] = (int)$r['part_id'];
  }
  $used += (int)$area;
}
if (!$used) { http_response_code(400); echo "Used area = 0. Nothing to finalize."; exit; }
if ($missing) { http_response_code(400); echo "Missing L/W for parts: ".h(implode(',',$missing)); exit; }

/* Plate usable area (trim-aware) */
$uL = max(0.0, (float)$plate['length_mm'] - 2*(int)$plate['trim_allow_mm']);
$uW = max(0.0, (float)$plate['width_mm']  - 2*(int)$plate['trim_allow_mm']);
$usable = (int)round($uL*$uW);

if (!$stockTables) {
  $pdo->prepare("UPDATE plate_plans SET status='balanced' WHERE id=?")->execute([$plan_id]);
  header('Location: plate_plan_form.php?id='.$plan_id.'&ok=1'); exit;
}

/* IDP: Get-or-create parent autolot for this plate when no lot is linked */
$parent = null;
if ($lot_id<=0) {
  $internal = 'PLATE-'.$plate_id;
  $q = $pdo->prepare("SELECT * FROM stock_lots WHERE internal_lot_no=? AND (parent_lot_id IS NULL OR parent_lot_id=0) ORDER BY id DESC LIMIT 1");
  $q->execute([$internal]); $parent = $q->fetch();
  if (!$parent) {
    $owner = 'company'; $itemCode=''; $itemName='';
    $ins = $pdo->prepare("INSERT INTO stock_lots
      (item_id,item_code,item_name,owner,origin_plan_id,origin_plan_no,origin_project_id,
       thickness_mm,length_mm,width_mm,area_mm2,available_area_mm2,weight_kg,
       plate_no,heat_no,internal_lot_no,source,parent_lot_id,chain_root_lot_id,status,received_at,remarks)
      VALUES (?,?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?, 'available', NOW(), 'auto parent (idempotent)')");
    $ins->execute([ (int)$plate['item_id'], $itemCode, $itemName, $owner,
      $plan_id, $plate['plan_no'], $plate['project_id'],
      (float)$plate['thickness_mm'], (float)$plate['length_mm'], (float)$plate['width_mm'], $usable, $usable, (float)($plate['total_plate_kg'] ?? 0),
      null, null, $internal, 'new', null, null
    ]);
    $lot_id = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE stock_lots SET chain_root_lot_id=? WHERE id=?")->execute([$lot_id,$lot_id]);
    $q->execute([$internal]); $parent = $q->fetch();
  } else {
    $lot_id = (int)$parent['id'];
  }
}
/* If a lot was linked, fetch it as parent */
if (!$parent) {
  $s=$pdo->prepare("SELECT * FROM stock_lots WHERE id=?"); $s->execute([$lot_id]); $parent=$s->fetch();
  if (!$parent) { http_response_code(400); echo "Lot not found."; exit; }
}

/* Stock consumption + optional child creation */
$pdo->beginTransaction();
try {
  $st=$pdo->prepare("SELECT * FROM stock_lots WHERE id=? FOR UPDATE");
  $st->execute([(int)$parent['id']]); $parent=$st->fetch();
  if (!$parent) throw new RuntimeException("Lot not found.");
  if (!in_array($parent['status'], ['available','partial'], true)) throw new RuntimeException("Lot not available (status: ".$parent['status'].").");

  $avail = (int)$parent['available_area_mm2'];
  if ($avail <= 0) $avail = (int)$parent['area_mm2']; // safety for old rows
  if ($used > $avail) throw new RuntimeException("Used area exceeds lot available area.");

  /* trace (if table exists) */
  $hasTrace = false;
  try { $chk = $pdo->query("SELECT 1 FROM part_traceability LIMIT 1"); $hasTrace = (bool)$chk; } catch(Throwable $e) { $hasTrace=false; }
  if ($hasTrace) {
    $ins=$pdo->prepare("INSERT INTO part_traceability (plan_id,plate_id,allocation_id,part_id,lot_id,plate_no,heat_no,process_code,recorded_at) VALUES (?,?,?,?,?,?,?,? ,NOW())");
    $allocs->execute([$plan_id,$plate_id]);
    while ($r=$allocs->fetch(PDO::FETCH_ASSOC)) { $ins->execute([$plan_id,$plate_id,(int)$r['alloc_id'],(int)$r['part_id'], (int)$parent['id'], $parent['plate_no'], $parent['heat_no'], 'CUT']); }
  }

  /* leftover and child remnant if > 10,000 mm² */
  $left = max(0, $usable - $used);
  if ($left > 10000) {
    // generate unique child internal_lot_no: PARENT-R, PARENT-R2, PARENT-R3...
    $base = (($parent['internal_lot_no'] ?? '') !== '' ? $parent['internal_lot_no'] : ('P-'.$parent['id'])) . '-R';
    $suffix = '';
    $getC = $pdo->prepare("SELECT internal_lot_no FROM stock_lots WHERE parent_lot_id=? AND internal_lot_no LIKE CONCAT(?, '%')");
    $getC->execute([(int)$parent['id'], $base]);
    $maxN = 0;
    while ($row = $getC->fetch(PDO::FETCH_ASSOC)) {
      $lotno = (string)$row['internal_lot_no'];
      if (preg_match('/-R(\d+)$/', $lotno, $m)) { $n = (int)$m[1]; if ($n>$maxN) $maxN = $n; }
      elseif ($lotno === $base) { if ($maxN==0) $maxN = 1; } // '-R' exists, next will be -R2
    }
    if ($maxN >= 1) $suffix = (string)($maxN+1);
    $childLotNo = $base . $suffix;

    $insC=$pdo->prepare("INSERT INTO stock_lots
      (item_id,item_code,item_name,owner,origin_plan_id,origin_plan_no,origin_project_id,
       thickness_mm,length_mm,width_mm,area_mm2,available_area_mm2,weight_kg,
       plate_no,heat_no,internal_lot_no,source,parent_lot_id,chain_root_lot_id,status,received_at,remarks)
      VALUES (?,?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?, 'available', NOW(), 'auto remnant (finalize)')");
    $insC->execute([
      (int)$parent['item_id'], $parent['item_code'], $parent['item_name'], $parent['owner'],
      $plan_id, $plate['plan_no'], $plate['project_id'],
      (float)$plate['thickness_mm'], (float)$plate['length_mm'], (float)$plate['width_mm'], $left, $left, (float)$parent['weight_kg'],
      $parent['plate_no'], $parent['heat_no'], $childLotNo, 'remnant', (int)$parent['id'], (int)($parent['chain_root_lot_id'] ?: $parent['id'])
    ]);
  }
  /* consume/partial parent */
  $newAvail = max(0, $avail - $used);
  $newStatus = $newAvail>0 ? 'partial' : 'consumed';
  $pdo->prepare("UPDATE stock_lots SET available_area_mm2=?, status=? WHERE id=?")->execute([$newAvail,$newStatus,(int)$parent['id']]);

  $pdo->prepare("UPDATE plate_plans SET status='balanced' WHERE id=?")->execute([$plan_id]);
  $pdo->commit();
  header('Location: plate_plan_form.php?id='.$plan_id.'&ok=1'); exit;

} catch(Throwable $e) {
  $pdo->rollBack();
  http_response_code(400);
  echo "Finalize failed: ".h($e->getMessage());
}
