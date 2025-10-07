<?php
/** PATH: /public_html/purchase/plate_plan_form.php
 * Plate Plan workbench: header + seeded parts (now shows L/W/Thk) + plates + allocations editor
 * - No remnant required for allocations
 * - Enforces plate.item_id == part.item_id (auto-set on 1st allocation if empty)
 * - Add/Edit/Delete allocations with area guard + remaining_qty sync
 * - Delete plate (restores remaining_qty from its allocations, then deletes)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = db();

header('Content-Type: text/html; charset=utf-8');

/* ---------- helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_table(PDO $pdo, string $t): bool {
  try{$st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");$st->execute([$t]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}
}
function has_col(PDO $pdo, string $t, string $c): bool {
  try{$st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");$st->execute([$t,$c]);return(bool)$st->fetchColumn();}catch(Throwable $e){return false;}
}
function piece_kg(?float $L, ?float $W, ?float $T, ?float $rho): ?float {
  if(!$L||!$W||!$T||!$rho) return null;
  return round(($L*$W*$T*$rho)/1_000_000.0,3); // mm^3 * g/cc => kg
}
function plan_url(int $id, array $qs=[]): string {
  $q="id=".$id; foreach($qs as $k=>$v){ $q.='&'.rawurlencode((string)$k).'='.rawurlencode((string)$v); }
  return 'plate_plan_form.php?'.$q;
}

/* ---------- inputs ---------- */
$plan_id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($plan_id <= 0) { http_response_code(400); echo "Missing id"; exit; }

$flashOk = $flashErr = null;
if (isset($_GET['inq_id'])) {
  $inq_id = (int)($_GET['inq_id'] ?? 0);
  $added  = (int)($_GET['added']  ?? 0);
  $updated= (int)($_GET['updated']?? 0);
  $skipped= (int)($_GET['skipped']?? 0);
  $flashOk = "Inquiry #{$inq_id} updated: +{$added} / {$updated} (skipped {$skipped})";
}


/* ---------- load plan header ---------- */
$plan = null;
if (has_table($pdo,'plate_plans')) {
  $st = $pdo->prepare("SELECT * FROM plate_plans WHERE id=?"); $st->execute([$plan_id]); $plan = $st->fetch();
}
if (!$plan) { http_response_code(404); echo "Plate Plan not found (id=".h((string)$plan_id).")"; exit; }

$plan_no    = $plan['plan_no'] ?? ('PP-'.$plan_id);
$project_id = $plan['project_id'] ?? null;
$status     = $plan['status'] ?? 'draft';
$req_id     = $plan['req_id'] ?? null;

/* ---------- schema presence ---------- */
$hasPartsTbl  = has_table($pdo,'plate_plan_parts');
$hasPlatesTbl = has_table($pdo,'plate_plan_plates');
$hasAllocTbl  = has_table($pdo,'plate_plan_allocations');

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $act = $_POST['__action'] ?? '';

  /* Add Plate */
  if ($act==='add_plate' && $hasPlatesTbl) {
    $needCols = ['plan_id','length_mm','width_mm','thickness_mm','trim_allow_mm','kerf_mm','qty_nos','per_piece_kg','total_plate_kg','note','item_id','source_type','source_lot_id'];
    $ok=true; foreach($needCols as $c){ if(!has_col($pdo,'plate_plan_plates',$c)){ $ok=false; break; } }
    if(!$ok){ $flashErr="plate_plan_plates is missing columns; cannot add plate."; }
    else{
      $L=max(0.0,(float)($_POST['length_mm']??0)); $W=max(0.0,(float)($_POST['width_mm']??0)); $T=max(0.0,(float)($_POST['thickness_mm']??0));
      $trim=(int)($_POST['trim_allow_mm']??0); $kerf=(int)($_POST['kerf_mm']??0); $note=trim($_POST['note']??'');
      $itemId=!empty($_POST['item_id'])?(int)$_POST['item_id']:null;
      $ppkg=piece_kg($L,$W,$T,7.85); if($ppkg===null)$ppkg=0.000; $totkg=$ppkg;
      $ins=$pdo->prepare("INSERT INTO plate_plan_plates (plan_id,item_id,source_type,source_lot_id,length_mm,width_mm,thickness_mm,trim_allow_mm,kerf_mm,qty_nos,per_piece_kg,total_plate_kg,note)
                          VALUES (?,?, 'new', NULL, ?,?,?,?,?, 1.000, ?, ?, ?)");
      $ins->execute([$plan_id,$itemId,$L,$W,$T,$trim,$kerf,$ppkg,$totkg,$note]);
      header('Location: '.plan_url($plan_id,['ok'=>1])); exit;
    }
  }

  /* Add Allocation */
  if ($act==='add_alloc' && $hasAllocTbl && $hasPartsTbl && $hasPlatesTbl) {
    $needACols=['plan_id','plate_id','part_id','alloc_qty','allocated_area_mm2','rotation_allowed'];
    $ok=true; foreach($needACols as $c){ if(!has_col($pdo,'plate_plan_allocations',$c)){ $ok=false; break; } }
    if(!$ok){ $flashErr="plate_plan_allocations missing columns."; }
    else{
      $plate_id=(int)($_POST['plate_id']??0); $part_id=(int)($_POST['part_id']??0);
      $qty=max(0.0,(float)($_POST['alloc_qty']??0)); $rot=isset($_POST['rotation_allowed'])?1:0;
      if($plate_id<=0||$part_id<=0||$qty<=0){ $flashErr="Choose a part and enter a positive quantity."; }
      else{
        // Load plate
        $pl=$pdo->prepare("SELECT id,item_id,length_mm,width_mm,trim_allow_mm FROM plate_plan_plates WHERE id=? AND plan_id=?");
        $pl->execute([$plate_id,$plan_id]); $P=$pl->fetch();
        if(!$P){ $flashErr="Plate not found."; }
        else{
          // Load part + req_line for dims
          $pt=$pdo->prepare("SELECT id,item_id,req_line_id,remaining_qty FROM plate_plan_parts WHERE id=? AND plan_id=?");
          $pt->execute([$part_id,$plan_id]); $PR=$pt->fetch();
          if(!$PR){ $flashErr="Part not found."; }
          else{
            if((float)$PR['remaining_qty']<$qty){ $flashErr="Not enough remaining qty for this part."; }
            else{
              // Enforce item/grade match (auto-assign plate.item_id if null)
              $plate_item = $P['item_id']!==null ? (int)$P['item_id'] : null;
              $part_item  = (int)$PR['item_id'];
              if($plate_item===null){
                $pdo->prepare("UPDATE plate_plan_plates SET item_id=? WHERE id=?")->execute([$part_item,$plate_id]);
              } elseif($plate_item!==$part_item){
                $flashErr="Grade mismatch: plate item {$plate_item} ≠ part item {$part_item}.";
              }

              if(!$flashErr){
                // dims from requirement
                $L=$W=null;
                if((int)$PR['req_line_id']>0 && has_table($pdo,'rm_requirement_lines') && has_col($pdo,'rm_requirement_lines','calc_detail')){
                  $cj=$pdo->prepare("SELECT calc_detail FROM rm_requirement_lines WHERE id=?");
                  $cj->execute([(int)$PR['req_line_id']]); $calc=$cj->fetchColumn();
                  if($calc){ $j=json_decode($calc,true); if(is_array($j)){ $L=isset($j['Lmm'])&&is_numeric($j['Lmm'])?(float)$j['Lmm']:null; $W=isset($j['Wmm'])&&is_numeric($j['Wmm'])?(float)$j['Wmm']:null; } }
                }
                if(!$L||!$W){ $flashErr="Part dimensions missing in requirement (Lmm/Wmm)."; }
                else{
                  $usable=max(0,(int)floor(($P['length_mm']-2*(int)$P['trim_allow_mm'])) * (int)floor(($P['width_mm']-2*(int)$P['trim_allow_mm'])));
                  $areaPer=(int)floor($L)*(int)floor($W);
                  $allocArea=$areaPer*(int)floor($qty);

                  // current used on this plate
                  $s=$pdo->prepare("SELECT COALESCE(SUM(allocated_area_mm2),0) FROM plate_plan_allocations WHERE plan_id=? AND plate_id=?");
                  $s->execute([$plan_id,$plate_id]); $sum=(int)$s->fetchColumn();

                  if($sum+$allocArea>$usable){
                    $flashErr="Allocation exceeds usable plate area. Usable: ".number_format($usable)." mm², existing: ".number_format($sum)." mm², new: ".number_format($allocArea)." mm².";
                  }else{
                    $pdo->beginTransaction();
                    try{
                      $ins=$pdo->prepare("INSERT INTO plate_plan_allocations (plan_id,plate_id,part_id,alloc_qty,allocated_area_mm2,rotation_allowed)
                                          VALUES (?,?,?,?,?,?)");
                      $ins->execute([$plan_id,$plate_id,$part_id,$qty,$allocArea,$rot]);

                      // reduce remaining
                      $pdo->prepare("UPDATE plate_plan_parts SET remaining_qty = GREATEST(0, remaining_qty - ?) WHERE id=? AND plan_id=?")
                          ->execute([$qty,$part_id,$plan_id]);

                      $pdo->commit();
                      header('Location: '.plan_url($plan_id,['ok'=>1])); exit;
                    }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $flashErr="Could not add allocation: ".$e->getMessage(); }
                  }
                }
              }
            }
          }
        }
      }
    }
  }

  /* Edit Allocation qty */
  if ($act==='edit_alloc' && $hasAllocTbl && $hasPartsTbl && $hasPlatesTbl) {
    $alloc_id=(int)($_POST['alloc_id']??0); $new_qty=max(0.0,(float)($_POST['alloc_qty']??0));
    if($alloc_id<=0||$new_qty<=0){ $flashErr="Enter a positive quantity."; }
    else{
      // load allocation + plate + part (for dims and remaining adjust)
      $a=$pdo->prepare("SELECT * FROM plate_plan_allocations WHERE id=? AND plan_id=?"); $a->execute([$alloc_id,$plan_id]); $AL=$a->fetch();
      if(!$AL){ $flashErr="Allocation not found."; }
      else{
        $plate_id=(int)$AL['plate_id']; $part_id=(int)$AL['part_id']; $old_qty=(float)$AL['alloc_qty'];
        $pl=$pdo->prepare("SELECT length_mm,width_mm,trim_allow_mm FROM plate_plan_plates WHERE id=? AND plan_id=?"); $pl->execute([$plate_id,$plan_id]); $P=$pl->fetch();
        $pt=$pdo->prepare("SELECT req_line_id FROM plate_plan_parts WHERE id=? AND plan_id=?"); $pt->execute([$part_id,$plan_id]); $PR=$pt->fetch();
        if(!$P||!$PR){ $flashErr="Plate/Part not found."; }
        else{
          $L=$W=null;
          if((int)$PR['req_line_id']>0 && has_table($pdo,'rm_requirement_lines') && has_col($pdo,'rm_requirement_lines','calc_detail')){
            $cj=$pdo->prepare("SELECT calc_detail FROM rm_requirement_lines WHERE id=?"); $cj->execute([(int)$PR['req_line_id']]); $calc=$cj->fetchColumn();
            if($calc){ $j=json_decode($calc,true); if(is_array($j)){ $L=isset($j['Lmm'])?(float)$j['Lmm']:null; $W=isset($j['Wmm'])?(float)$j['Wmm']:null; } }
          }
          if(!$L||!$W){ $flashErr="Part dimensions missing."; }
          else{
            $usable=max(0,(int)floor(($P['length_mm']-2*(int)$P['trim_allow_mm'])) * (int)floor(($P['width_mm']-2*(int)$P['trim_allow_mm'])));
            $areaPer=(int)floor($L)*(int)floor($W);
            $newArea=$areaPer*(int)floor($new_qty);

            // current used excluding this allocation
            $s=$pdo->prepare("SELECT COALESCE(SUM(allocated_area_mm2),0) FROM plate_plan_allocations WHERE plan_id=? AND plate_id=? AND id<>?");
            $s->execute([$plan_id,$plate_id,$alloc_id]); $sum=(int)$s->fetchColumn();

            if($sum+$newArea>$usable){ $flashErr="Update exceeds usable area."; }
            else{
              $delta=$new_qty-$old_qty; // +/- qty to adjust remaining
              $pdo->beginTransaction();
              try{
                $pdo->prepare("UPDATE plate_plan_allocations SET alloc_qty=?, allocated_area_mm2=? WHERE id=? AND plan_id=?")
                    ->execute([$new_qty,$newArea,$alloc_id,$plan_id]);

                // adjust remaining (reverse sign)
                $pdo->prepare("UPDATE plate_plan_parts SET remaining_qty = GREATEST(0, remaining_qty - ?) WHERE id=? AND plan_id=?")
                    ->execute([$delta,$part_id,$plan_id]); // if delta negative, this increases remaining

                $pdo->commit();
                header('Location: '.plan_url($plan_id,['ok'=>1])); exit;
              }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $flashErr="Could not update allocation: ".$e->getMessage(); }
            }
          }
        }
      }
    }
  }

  /* Delete Allocation */
  if ($act==='delete_alloc' && $hasAllocTbl && $hasPartsTbl) {
    $alloc_id=(int)($_POST['alloc_id']??0);
    if($alloc_id<=0){ $flashErr="Missing allocation id."; }
    else{
      $a=$pdo->prepare("SELECT * FROM plate_plan_allocations WHERE id=? AND plan_id=?"); $a->execute([$alloc_id,$plan_id]); $AL=$a->fetch();
      if(!$AL){ $flashErr="Allocation not found."; }
      else{
        $pdo->beginTransaction();
        try{
          // restore remaining
          $pdo->prepare("UPDATE plate_plan_parts SET remaining_qty = remaining_qty + ? WHERE id=? AND plan_id=?")
              ->execute([(float)$AL['alloc_qty'], (int)$AL['part_id'], $plan_id]);
          // delete
          $pdo->prepare("DELETE FROM plate_plan_allocations WHERE id=? AND plan_id=?")->execute([$alloc_id,$plan_id]);
          $pdo->commit();
          header('Location: '.plan_url($plan_id,['ok'=>1])); exit;
        }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $flashErr="Could not delete allocation: ".$e->getMessage(); }
      }
    }
  }

  /* Delete Plate (restores all its allocations first) */
  if ($act==='delete_plate' && $hasPlatesTbl) {
    $plate_id=(int)($_POST['plate_id']??0);
    if($plate_id<=0){ $flashErr="Missing plate id."; }
    else{
      $pdo->beginTransaction();
      try{
        if($hasAllocTbl){
          // restore remaining for all allocations on this plate
          $al=$pdo->prepare("SELECT part_id, alloc_qty FROM plate_plan_allocations WHERE plan_id=? AND plate_id=?");
          $al->execute([$plan_id,$plate_id]);
          $rows=$al->fetchAll();
          foreach($rows as $r){
            $pdo->prepare("UPDATE plate_plan_parts SET remaining_qty = remaining_qty + ? WHERE id=? AND plan_id=?")
                ->execute([(float)$r['alloc_qty'], (int)$r['part_id'], $plan_id]);
          }
          // delete allocs
          $pdo->prepare("DELETE FROM plate_plan_allocations WHERE plan_id=? AND plate_id=?")->execute([$plan_id,$plate_id]);
        }
        // delete plate
        $pdo->prepare("DELETE FROM plate_plan_plates WHERE id=? AND plan_id=?")->execute([$plate_id,$plan_id]);
        $pdo->commit();
        header('Location: '.plan_url($plan_id,['ok'=>1])); exit;
      }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $flashErr="Could not delete plate: ".$e->getMessage(); }
    }
  }
}

/* ---------- load parts + (NEW) pull L/W from rm_requirement_lines.calc_detail ---------- */
$parts=[]; $partsErr=null; $reqLineIds=[];
if($hasPartsTbl){
  $cols=['id','plan_id','req_line_id','item_id','desc_text','thickness_mm','density_gcc','need_qty','per_piece_kg','total_need_kg','remaining_qty','sort_order','created_at'];
  $sel=[]; foreach($cols as $c){ if(has_col($pdo,'plate_plan_parts',$c)) $sel[]=$c; }
  if($sel){
    $st=$pdo->prepare("SELECT ".implode(',',$sel)." FROM plate_plan_parts WHERE plan_id=? ORDER BY COALESCE(sort_order,id)");
    $st->execute([$plan_id]); $parts=$st->fetchAll();
    foreach($parts as $p){ if(!empty($p['req_line_id'])) $reqLineIds[]=(int)$p['req_line_id']; }
  } else $partsErr="plate_plan_parts exists but lacks expected columns.";
} else $partsErr="plate_plan_parts table not found.";

/* Map req_line_id -> ['Lmm'=>..., 'Wmm'=>...] */
$dimByReqLine=[];
if ($reqLineIds && has_table($pdo,'rm_requirement_lines') && has_col($pdo,'rm_requirement_lines','calc_detail')) {
  $in = implode(',', array_fill(0,count($reqLineIds),'?'));
  $st = $pdo->prepare("SELECT id, calc_detail FROM rm_requirement_lines WHERE id IN ($in)");
  $st->execute($reqLineIds);
  while($row = $st->fetch()){
    $L = $W = null;
    $j = json_decode($row['calc_detail'] ?? '[]', true);
    if (is_array($j)) {
      $L = isset($j['Lmm']) && is_numeric($j['Lmm']) ? (float)$j['Lmm'] : null;
      $W = isset($j['Wmm']) && is_numeric($j['Wmm']) ? (float)$j['Wmm'] : null;
    }
    $dimByReqLine[(int)$row['id']] = ['Lmm'=>$L, 'Wmm'=>$W];
  }
}

/* map: part_id => label */
$partOptions=[]; foreach($parts as $p){ $partOptions[(int)$p['id']] = '#'.$p['id'].' · Item '.$p['item_id'].' · '.(string)($p['desc_text']??''); }

/* ---------- load plates ---------- */
$plates=[]; $platesCapable=$hasPlatesTbl;
if($platesCapable){
  $pcols=['id','plan_id','item_id','source_type','source_lot_id','length_mm','width_mm','thickness_mm','trim_allow_mm','kerf_mm','qty_nos','per_piece_kg','total_plate_kg','note'];
  $psel=[]; foreach($pcols as $c){ if(has_col($pdo,'plate_plan_plates',$c)) $psel[]=$c; }
  if($psel){
    $st=$pdo->prepare("SELECT ".implode(',',$psel)." FROM plate_plan_plates WHERE plan_id=? ORDER BY id");
    $st->execute([$plan_id]); $plates=$st->fetchAll();
  }
}

/* ---------- load allocations ---------- */
$allocsByPlate=[]; $allocsTableExists=$hasAllocTbl;
if($allocsTableExists){
  $acol=[]; foreach(['id','plan_id','plate_id','part_id','alloc_qty','allocated_area_mm2','rotation_allowed'] as $c){ if(has_col($pdo,'plate_plan_allocations',$c)) $acol[]="a.$c"; }
  if($acol){
    $sql="SELECT ".implode(',',$acol)." FROM plate_plan_allocations a WHERE a.plan_id=?";
    $st=$pdo->prepare($sql); $st->execute([$plan_id]); foreach($st->fetchAll() as $row){ $allocsByPlate[(int)$row['plate_id']][]=$row; }
  }
}

/* ---------- page ---------- */
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Plate Plan <?= h($plan_no) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#fafafa}
    .wrap{max-width:1200px;margin:0 auto;padding:18px}
    .grid{display:grid;grid-template-columns:1fr;gap:16px}
    @media(min-width:980px){.grid{grid-template-columns:1fr 1fr}}
    .card{background:#fff;border:1px solid #eee;border-radius:12px;padding:16px}
    table{border-collapse:collapse;width:100%}
    th,td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}
    .row{display:flex;gap:10px;flex-wrap:wrap}
    .btn{display:inline-block;padding:7px 12px;border:1px solid #ccc;border-radius:8px;background:#fff;text-decoration:none;cursor:pointer}
    .danger{border-color:#b00;color:#b00}
    .muted{color:#666}
    .pill{border:1px solid #ddd;border-radius:999px;padding:2px 8px;font-size:12px}
    input,select{padding:7px 10px;border:1px solid #ccc;border-radius:8px}
    .small{font-size:12px}
    ::placeholder{color:#999;opacity:1}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between;align-items:center">
      <div>
        <h3 style="margin:0">Plate Plan: <?= h($plan_no) ?></h3>
        <div class="muted">
          ID <?= (int)$plan_id ?>
          <?php if ($req_id): ?> · Req <?= (int)$req_id ?><?php endif; ?>
          <?php if ($project_id): ?> · Project <?= (int)$project_id ?><?php endif; ?>
          · <span class="pill"><?= h($status) ?></span>
        </div>
      </div>
      <div class="row">
        <a class="btn" href="req_wizard.php">← Requirements</a>
        <?php if ($req_id): ?><a class="btn" href="plate_plan_open.php?req_id=<?= (int)$req_id ?>">Re-open/Seed</a><?php endif; ?>
        <a class="btn" href="remnant_list.php">Remnants</a>
        <a class="btn btn-primary" href="plate_to_inquiry.php?plan_id=<?= (int)$plan_id ?>">Convert to Inquiry</a>
      </div>
    </div>
    <?php if ($flashOk): ?><div style="margin-top:8px;color:#070"><b><?= h($flashOk) ?></b></div><?php endif; ?>
    <?php if ($flashErr): ?><div style="margin-top:8px;color:#b00"><b><?= h($flashErr) ?></b></div><?php endif; ?>
    <?php if (isset($_GET['ok'])): ?><div style="margin-top:8px;color:#070"><b>Saved.</b></div><?php endif; ?>
    <?php if (isset($_GET['seeded'])): ?><div style="margin-top:8px;color:#070"><b>Seeded parts:</b> <?= (int)$_GET['seeded'] ?></div><?php endif; ?>
  </div>

  <div class="grid">
    <!-- PARTS -->
    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:center">
        <h4 style="margin:0">Parts to Cut</h4>
        <?php if ($req_id): ?><a class="btn" href="plate_plan_open.php?req_id=<?= (int)$req_id ?>">Re-seed missing lines</a><?php endif; ?>
      </div>

      <?php if ($partsErr): ?><div class="muted" style="margin:8px 0"><?= h($partsErr) ?></div><?php endif; ?>

      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Item</th>
            <th>Description</th>
            <th>L (mm)</th>
            <th>W (mm)</th>
            <th>Thk (mm)</th>
            <th>Need</th>
            <th>Remain</th>
            <th>kg/pc</th>
            <th>Total kg</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($parts): $n=0; foreach ($parts as $p): $n++;
          $dims = $dimByReqLine[(int)($p['req_line_id'] ?? 0)] ?? ['Lmm'=>null,'Wmm'=>null];
          $Lmm = $dims['Lmm']; $Wmm = $dims['Wmm'];
        ?>
          <tr>
            <td><?= $n ?></td>
            <td><?= (int)($p['item_id'] ?? 0) ?></td>
            <td><?= h($p['desc_text'] ?? '') ?></td>
            <td><?= $Lmm !== null ? (float)$Lmm : '—' ?></td>
            <td><?= $Wmm !== null ? (float)$Wmm : '—' ?></td>
            <td><?= isset($p['thickness_mm']) && $p['thickness_mm']!==null ? (float)$p['thickness_mm'] : '—' ?></td>
            <td><?= isset($p['need_qty']) ? (float)$p['need_qty'] : '' ?></td>
            <td><?= isset($p['remaining_qty']) ? (float)$p['remaining_qty'] : '' ?></td>
            <td><?= isset($p['per_piece_kg']) ? (float)$p['per_piece_kg'] : '' ?></td>
            <td><?= isset($p['total_need_kg']) ? (float)$p['total_need_kg'] : '' ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="10" class="muted">No parts seeded yet. Click “Re-seed missing lines”.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PLATES + ALLOCATIONS -->
    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:center">
        <h4 style="margin:0">Plates</h4>
        <?php if ($platesCapable): ?>
        <form method="post" class="row small" style="gap:8px">
          <input type="hidden" name="__action" value="add_plate">
          <input name="length_mm" type="number" step="0.001" placeholder="Length (mm)" required title="Length (mm)">
          <input name="width_mm"  type="number" step="0.001" placeholder="Width (mm)" required title="Width (mm)">
          <input name="thickness_mm" type="number" step="0.001" placeholder="Thk (mm)" required title="Thickness (mm)">
          <input name="trim_allow_mm" type="number" placeholder="Trim (mm)" value="0" title="Trim allowance on each side">
          <input name="kerf_mm" type="number" placeholder="Kerf (mm)" value="0" title="Kerf per cut (for reference)">
          <input name="item_id" type="number" placeholder="Item (grade)" title="Item/grade for this plate">
          <input name="note" placeholder="Note">
          <button class="btn">+ Add Plate</button>
        </form>
        <div class="muted small" style="margin-top:6px">
          Tip: <b>Trim (mm)</b> and <b>Kerf (mm)</b> fields have descriptive background text like the length/width fields to avoid confusion.
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$platesCapable): ?>
        <div class="muted">plate_plan_plates table not found — plate UI disabled.</div>
      <?php endif; ?>

      <?php if ($plates): foreach ($plates as $pl): ?>
        <?php
          $usable=max(0,(int)floor(($pl['length_mm']-2*(int)$pl['trim_allow_mm'])) * (int)floor(($pl['width_mm']-2*(int)$pl['trim_allow_mm'])));
          $allocs=$allocsByPlate[(int)$pl['id']] ?? [];
          $used=0; foreach($allocs as $a){ $used+=(int)($a['allocated_area_mm2'] ?? 0); }
        ?>
        <div style="border:1px solid #eee;border-radius:10px;margin:10px 0;padding:10px">
          <div class="row" style="justify-content:space-between">
            <div>
              <b>Plate #<?= (int)$pl['id'] ?></b>
              <?php if (!empty($pl['source_type'])): ?><span class="pill"><?= h($pl['source_type']) ?></span><?php endif; ?>
              <?php if (!empty($pl['item_id'])): ?><span class="pill">Item <?= (int)$pl['item_id'] ?></span><?php endif; ?>
              <div class="muted">
                <?= (float)$pl['length_mm'] ?> × <?= (float)$pl['width_mm'] ?> × <?= (float)$pl['thickness_mm'] ?> mm
                · Trim <?= (int)$pl['trim_allow_mm'] ?> · Kerf <?= (int)$pl['kerf_mm'] ?>
                · Used <?= number_format($used) ?> / <?= number_format($usable) ?> mm²
              </div>
            </div>
            <div class="row">
              <a class="btn" href="plate_lot_picker.php?plate_id=<?= (int)$pl['id'] ?>">Link Remnant</a>
              <a class="btn" href="plate_lot_finalize.php?plate_id=<?= (int)$pl['id'] ?>">Finalize Cut</a>
              <form method="post" onsubmit="return confirm('Delete this plate? Allocations (if any) will be removed and part remaining restored.');">
                <input type="hidden" name="__action" value="delete_plate">
                <input type="hidden" name="plate_id" value="<?= (int)$pl['id'] ?>">
                <button class="btn danger">Delete Plate</button>
              </form>
            </div>
          </div>

          <!-- Allocations list -->
          <?php if ($allocs): ?>
            <table style="margin-top:6px">
              <thead><tr><th>Part</th><th>Qty</th><th>Area (mm²)</th><th>Rot</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($allocs as $a): ?>
                  <tr>
                    <td>#<?= (int)$a['part_id'] ?> — <?= h($partOptions[(int)$a['part_id']] ?? '') ?></td>
                    <td><?= isset($a['alloc_qty']) ? (float)$a['alloc_qty'] : '' ?></td>
                    <td><?= isset($a['allocated_area_mm2']) ? number_format((int)$a['allocated_area_mm2']) : '' ?></td>
                    <td><?= !empty($a['rotation_allowed']) ? '✓' : '—' ?></td>
                    <td class="row">
                      <!-- Edit qty -->
                      <form method="post" class="row small" style="gap:6px">
                        <input type="hidden" name="__action" value="edit_alloc">
                        <input type="hidden" name="alloc_id" value="<?= (int)$a['id'] ?>">
                        <input type="number" step="1" min="1" name="alloc_qty" value="<?= (float)$a['alloc_qty'] ?>" title="New allocation quantity">
                        <button class="btn">Update</button>
                      </form>
                      <!-- Delete -->
                      <form method="post" onsubmit="return confirm('Delete this allocation? Remaining qty will be restored.');">
                        <input type="hidden" name="__action" value="delete_alloc">
                        <input type="hidden" name="alloc_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn danger">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="muted" style="margin-top:6px">No allocations yet for this plate.</div>
          <?php endif; ?>

          <!-- Allocation editor -->
          <?php if ($allocsTableExists): ?>
            <form method="post" class="row small" style="margin-top:10px;gap:8px">
              <input type="hidden" name="__action" value="add_alloc">
              <input type="hidden" name="plate_id" value="<?= (int)$pl['id'] ?>">
              <select name="part_id" required title="Pick a part to place on this plate">
                <option value="">— pick part —</option>
                <?php foreach ($partOptions as $pid=>$label): ?>
                  <option value="<?= (int)$pid ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <input name="alloc_qty" type="number" step="1" min="1" placeholder="Qty" required title="Quantity to allocate">
              <label style="display:flex;align-items:center;gap:6px" title="Allow rotating part 90° if needed">
                <input type="checkbox" name="rotation_allowed"> Allow rotate 90°
              </label>
              <button class="btn">+ Add Allocation</button>
            </form>
          <?php else: ?>
            <div class="muted" style="margin-top:10px">
              Allocations table not found. Create it with:
              <pre style="white-space:pre-wrap;border:1px solid #eee;border-radius:8px;padding:8px;background:#fcfcfc">
CREATE TABLE IF NOT EXISTS plate_plan_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  plate_id BIGINT UNSIGNED NOT NULL,
  part_id BIGINT UNSIGNED NOT NULL,
  alloc_qty DECIMAL(14,3) NOT NULL,
  allocated_area_mm2 BIGINT NOT NULL DEFAULT 0,
  rotation_allowed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_plan_plate (plan_id, plate_id),
  KEY idx_part (part_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;</pre>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; else: ?>
        <div class="muted">No plates yet. Add one above, then link a remnant if needed (optional).</div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
