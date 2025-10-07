<?php
/** PATH: /public_html/purchase/inquiries_form.php — consumables unchanged; RMI dims-aware + weight_kg recalc on save; Suppliers rendered server-side with contact auto-fill */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/inquiry_seq.php';

require_login();
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/* helpers */
function table_has_cols(PDO $pdo, string $table, array $cols): bool {
  if (!$cols) return false;
  $in = str_repeat('?,', count($cols)-1) . '?';
  $q = $pdo->prepare("
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($in)
  ");
  $q->execute(array_merge([$table], $cols));
  return (int)$q->fetchColumn() === count($cols);
}

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;
if ($is_edit) require_permission('purchase.inquiry.view'); else require_permission('purchase.inquiry.manage');

/** dropdowns */
$projects  = $pdo->query("SELECT id, code, name FROM projects ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, code, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$uoms      = $pdo->query("SELECT id, code, name FROM uom ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

/** robust material subcategories (fallback to 'All Items') */
$subcats = [];
try {
  $hasMS = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='material_subcategories'")->fetchColumn();
  $hasMC = (bool)$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='material_categories'")->fetchColumn();
  if ($hasMS) {
    $colSCstatus = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='material_subcategories' AND COLUMN_NAME='status'")->fetchColumn();
    $colSCactive = (bool)$pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='material_subcategories' AND COLUMN_NAME='active'")->fetchColumn();
    $scActiveWhere = '1=1';
    if ($colSCstatus)      $scActiveWhere = "COALESCE(s.status,'active')='active'";
    elseif ($colSCactive)  $scActiveWhere = "COALESCE(s.active,1)=1";
    if ($hasMC) {
      $sql = "SELECT s.id, CONCAT(COALESCE(c.code,''),'/',COALESCE(s.code,''),' — ',COALESCE(s.name,'')) AS label
              FROM material_subcategories s
              JOIN material_categories c ON c.id = s.category_id
              WHERE $scActiveWhere
              ORDER BY c.code, s.code, s.name";
    } else {
      $sql = "SELECT s.id, CONCAT(COALESCE(s.code,''),' — ',COALESCE(s.name,'')) AS label
              FROM material_subcategories s
              WHERE $scActiveWhere
              ORDER BY s.code, s.name";
    }
    $subcats = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) { /* ignore */ }
if (!$subcats) $subcats = [['id'=>0, 'label'=>'All Items']]; // fallback

/** header defaults */
$hdr = [
  'inquiry_no'=>'','project_id'=>'','location_id'=>'',
  'inquiry_date'=>date('Y-m-d'),'valid_till'=>'',
  'delivery_terms'=>'','payment_terms'=>'','freight_terms'=>'',
  'gst_inclusive'=>0,'notes'=>'','status'=>'draft'
];
$lines=[]; $vendors=[]; $rawLines=[];
$haveDims    = table_has_cols($pdo,'inquiry_lines',['length_mm','width_mm','thickness_mm']);
$haveDensity = table_has_cols($pdo,'inquiry_lines',['density_gcc']);

if ($is_edit) {
  $st = $pdo->prepare("SELECT * FROM inquiries WHERE id=?");
  $st->execute([$id]); if ($r=$st->fetch(PDO::FETCH_ASSOC)) $hdr=array_merge($hdr,$r);

  // Consumables (editable)
  $st=$pdo->prepare("SELECT ii.*, it.subcategory_id, it.material_code, it.name AS item_name, u.code AS uom_code
                     FROM inquiry_items ii
                     JOIN items it ON it.id=ii.item_id
                     JOIN uom u ON u.id=ii.uom_id
                     WHERE ii.inquiry_id=? ORDER BY ii.id");
  $st->execute([$id]); $lines=$st->fetchAll(PDO::FETCH_ASSOC);

  // Raw-material lines: dims from inquiry_lines, fallback to plate_plan_plates via source_line_id (RMI only)
  $dimSel = $haveDims
    ? ", COALESCE(l.length_mm, p.length_mm) AS length_mm
       , COALESCE(l.width_mm , p.width_mm ) AS width_mm
       , COALESCE(l.thickness_mm, p.thickness_mm) AS thickness_mm"
    : "";
  $densSel = $haveDensity ? ", l.density_gcc" : "";

  $st = $pdo->prepare("
    SELECT l.id, l.description, l.qty, l.qty_uom_id, l.weight_kg AS wkg, l.source_type,
           u.code AS uom_code
           $dimSel
           $densSel
    FROM inquiry_lines l
    LEFT JOIN uom u ON u.id = l.qty_uom_id
    LEFT JOIN plate_plan_plates p ON p.id = l.source_line_id AND l.source_type='RMI'
    WHERE l.inquiry_id = ?
      AND (l.source_type IN ('GI','RMI') OR l.source_type IS NULL)
    ORDER BY l.id
  ");
  $st->execute([$id]);
  $rawLines = $st->fetchAll(PDO::FETCH_ASSOC);

  // Vendors (attached to this inquiry)
  $st=$pdo->prepare("SELECT s.*, p.name AS party_name
                     FROM inquiry_suppliers s JOIN parties p ON p.id=s.party_id
                     WHERE s.inquiry_id=? ORDER BY s.id");
  $st->execute([$id]); $vendors=$st->fetchAll(PDO::FETCH_ASSOC);
}

/** SAVE (draft) */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '')==='save') {
  require_permission('purchase.inquiry.manage');

  $project_id  = $_POST['project_id']!=='' ? (int)$_POST['project_id'] : null;
  $location_id = $_POST['location_id']!=='' ? (int)$_POST['location_id'] : null;
  $inquiry_date= $_POST['inquiry_date'] ?: date('Y-m-d');
  $valid_till  = $_POST['valid_till'] ?: null;
  $delivery    = trim($_POST['delivery_terms'] ?? '');
  $payment     = trim($_POST['payment_terms'] ?? '');
  $freight     = trim($_POST['freight_terms'] ?? '');
  $gst_incl    = isset($_POST['gst_inclusive']) ? 1 : 0;
  $notes       = trim($_POST['notes'] ?? '');

  if (!$is_edit) {
    $inq_no = next_inquiry_no($pdo);
    $pdo->prepare("INSERT INTO inquiries
      (inquiry_no, project_id, location_id, inquiry_date, valid_till, delivery_terms, payment_terms, freight_terms, gst_inclusive, notes, status, created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?, 'draft', ?)")
      ->execute([$inq_no,$project_id,$location_id,$inquiry_date,$valid_till,$delivery,$payment,$freight,$gst_incl,$notes,current_user_id()]);
    $id = (int)$pdo->lastInsertId(); $is_edit = true;
    $hdr['status'] = 'draft';
  } else {
    if (($hdr['status']??'draft')==='draft') {
      $pdo->prepare("UPDATE inquiries SET project_id=?, location_id=?, inquiry_date=?, valid_till=?, delivery_terms=?, payment_terms=?, freight_terms=?, gst_inclusive=?, notes=? WHERE id=?")
          ->execute([$project_id,$location_id,$inquiry_date,$valid_till,$delivery,$payment,$freight,$gst_incl,$notes,$id]);
    }
  }

  // Payloads
  $lines_json    = $_POST['lines_json']    ?? '[]';
  $vendors_json  = $_POST['vendors_json']  ?? '[]';
  $rm_lines_json = $_POST['rm_lines_json'] ?? '[]';
  $postLines     = json_decode($lines_json, true) ?: [];
  $postVendors   = json_decode($vendors_json, true) ?: [];
  $postRMLines   = json_decode($rm_lines_json, true) ?: [];

  if (($hdr['status']??'draft')==='draft') {
    /* Consumables ONLY */
    $pdo->prepare("DELETE FROM inquiry_items WHERE inquiry_id=?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO inquiry_items (inquiry_id, indent_id, indent_item_id, item_id, make_id, qty, uom_id, needed_by, line_notes)
                          VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($postLines as $ln) {
      $item_id = (int)($ln['item_id'] ?? 0);
      $qty     = (float)($ln['qty'] ?? 0);
      $uom_id  = (int)($ln['uom_id'] ?? 0);
      $needed_by  = !empty($ln['needed_by']) ? $ln['needed_by'] : null;
      $line_notes = isset($ln['line_notes']) ? trim((string)$ln['line_notes']) : null;

      $indent_item_id = (int)($ln['indent_item_id'] ?? 0);
      if ($indent_item_id > 0) {
        $chk = $pdo->prepare("SELECT item_id, qty, uom_id, needed_by, remarks FROM indent_items WHERE id=?");
        $chk->execute([$indent_item_id]);
        if ($src = $chk->fetch(PDO::FETCH_ASSOC)) {
          $item_id   = (int)$src['item_id'];
          $qty       = (float)$src['qty'];
          $uom_id    = (int)$src['uom_id'];
          $needed_by = $src['needed_by'] ?? $needed_by;
          $line_notes= $src['remarks'] ?? $line_notes;
        }
      }
      if ($item_id<=0 || $qty<=0 || $uom_id<=0) continue;

      $ins->execute([
        $id,
        !empty($ln['indent_id']) ? (int)$ln['indent_id'] : null,
        $indent_item_id ?: null,
        $item_id,
        !empty($ln['make_id']) ? (int)$ln['make_id'] : null,
        $qty,
        $uom_id,
        $needed_by,
        $line_notes
      ]);
    }

    /* ---- RMI dims update + weight_kg recalc ---- */
    if ($postRMLines) {
      $upd = $pdo->prepare("
        UPDATE inquiry_lines
        SET length_mm = ?, width_mm = ?, thickness_mm = ?,
            qty = ?, qty_uom_id = ?, weight_kg = ?
        WHERE id = ? AND inquiry_id = ?
      ");
      $selDens = $haveDensity
        ? $pdo->prepare("SELECT COALESCE(density_gcc, 7.85) FROM inquiry_lines WHERE id=? AND inquiry_id=?")
        : null;

      foreach ($postRMLines as $rm) {
        $rid = (int)($rm['id'] ?? 0);
        if ($rid<=0) continue;

        $L = isset($rm['length_mm'])    && $rm['length_mm']    !== '' ? (float)$rm['length_mm']    : null;
        $W = isset($rm['width_mm'])     && $rm['width_mm']     !== '' ? (float)$rm['width_mm']     : null;
        $T = isset($rm['thickness_mm']) && $rm['thickness_mm'] !== '' ? (float)$rm['thickness_mm'] : null;
        $Q = isset($rm['qty'])          && $rm['qty']          !== '' ? (float)$rm['qty']          : null;
        $U = isset($rm['qty_uom_id'])   && $rm['qty_uom_id']   !== '' ? (int)$rm['qty_uom_id']     : null;

        $rho = 7.85;
        if ($selDens) { $selDens->execute([$rid, $id]); $d = $selDens->fetchColumn(); if ($d!==false && $d!==null) $rho = (float)$d; }

        $wkg = null;
        if ($L!==null && $W!==null && $T!==null && $L>0 && $W>0 && $T>0 && $Q!==null && $Q>0) {
          $ppkg = ($L/1000.0)*($W/1000.0)*($T/1000.0)*($rho*1000.0);
          $wkg  = round($ppkg * $Q, 3);
        }

        $upd->execute([
          $haveDims ? $L : null,
          $haveDims ? $W : null,
          $haveDims ? $T : null,
          $Q,
          $U,
          $wkg,
          $rid,
          $id
        ]);
      }
    }

    // Vendors
    $pdo->prepare("DELETE FROM inquiry_suppliers WHERE inquiry_id=?")->execute([$id]);
    $insv = $pdo->prepare("INSERT INTO inquiry_suppliers (inquiry_id, party_id, contact_name, contact_email, contact_phone) VALUES (?,?,?,?,?)");
    foreach ($postVendors as $v) {
      $party_id = (int)($v['party_id'] ?? 0);
      if ($party_id<=0) continue;
      $insv->execute([$id,
        $party_id,
        trim((string)($v['contact_name']  ?? '')),
        trim((string)($v['contact_email'] ?? '')),
        trim((string)($v['contact_phone'] ?? ''))
      ]);
    }
  }

  header('Location: /purchase/inquiries_list.php'); exit;
}

/** ISSUE */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action'] ?? '')==='issue') {
  require_permission('purchase.inquiry.issue');
  if ($is_edit && ($hdr['status']??'draft')==='draft') {
    if (empty($hdr['inquiry_no'])) {
      $no = next_inquiry_no($pdo);
      $pdo->prepare("UPDATE inquiries SET inquiry_no=? WHERE id=?")->execute([$no,$id]);
    }
    $pdo->prepare("UPDATE inquiries SET status='issued', issued_at=NOW(), issued_by=? WHERE id=?")->execute([current_user_id(), $id]);
  }
  header('Location: /purchase/inquiries_list.php'); exit;
}

/* Suppliers dropdown options (with contact fields for auto-fill) */
$parties = $pdo->query("
  SELECT id, code, name, contact_name, email, phone
  FROM parties 
  WHERE status=1 AND (type='supplier' OR type IS NULL)
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);
if (!$parties) {
  $parties = $pdo->query("SELECT id, code, name, contact_name, email, phone FROM parties WHERE status=1 ORDER BY name LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= $is_edit?'Edit Inquiry':'New Inquiry' ?></h1>
    <?php if($is_edit && ($hdr['status']??'draft')==='draft' && has_permission('purchase.inquiry.issue')): ?>
      <form method="post" class="ms-auto">
        <input type="hidden" name="_action" value="issue">
        <button class="btn btn-warning">Issue RFQ</button>
      </form>
    <?php elseif($is_edit && ($hdr['status']??'')==='issued'): ?>
      <a class="btn btn-outline-secondary" target="_blank" href="/purchase/inquiry_print.php?id=<?=$id?>">Print</a>
    <?php endif; ?>
  </div>

  <form method="post" class="card shadow-sm p-3" id="inqForm">
    <input type="hidden" name="_action" value="save">
    <input type="hidden" name="lines_json" id="lines_json">
    <input type="hidden" name="vendors_json" id="vendors_json">
    <input type="hidden" name="rm_lines_json" id="rm_lines_json">

    <!-- Header -->
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Inquiry No</label>
        <input class="form-control" value="<?=htmlspecialchars($hdr['inquiry_no']??'')?>" disabled>
      </div>
      <div class="col-md-3"><label class="form-label">Date</label>
        <input type="date" name="inquiry_date" class="form-control" value="<?=htmlspecialchars($hdr['inquiry_date'])?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>></div>
      <div class="col-md-3"><label class="form-label">Valid Till</label>
        <input type="date" name="valid_till" class="form-control" value="<?=htmlspecialchars((string)($hdr['valid_till']??''))?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>></div>
      <div class="col-md-3"><label class="form-label">Project</label>
        <select name="project_id" id="project_id" class="form-select" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
          <option value="">—</option>
          <?php foreach($projects as $p): ?>
            <option value="<?=$p['id']?>" <?=($hdr['project_id']??null)==$p['id']?'selected':''?>><?=htmlspecialchars($p['code'].' — '.$p['name'])?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label">Location</label>
        <select name="location_id" class="form-select" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
          <option value="">—</option>
          <?php foreach($locations as $l): ?>
            <option value="<?=$l['id']?>" <?=($hdr['location_id']??null)==$l['id']?'selected':''?>><?=htmlspecialchars($l['code'].' — '.$l['name'])?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label">Delivery Terms</label><input name="delivery_terms" class="form-control" value="<?=htmlspecialchars($hdr['delivery_terms']??'')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>></div>
      <div class="col-md-3"><label class="form-label">Payment Terms</label><input name="payment_terms" class="form-control" value="<?=htmlspecialchars($hdr['payment_terms']??'')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>></div>
      <div class="col-md-3"><label class="form-label">Freight Terms</label><input name="freight_terms" class="form-control" value="<?=htmlspecialchars($hdr['freight_terms']??'')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>></div>
      <div class="col-md-12"><div class="form-check">
        <input class="form-check-input" type="checkbox" name="gst_inclusive" value="1" <?=($hdr['gst_inclusive']??0)?'checked':''?> <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
        <label class="form-check-label">Prices expected GST-inclusive</label></div></div>
      <div class="col-md-12"><label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="2" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>><?=htmlspecialchars($hdr['notes']??'')?></textarea></div>
    </div>

    <hr class="my-4">

    <!-- RAW MATERIAL (dims-aware) -->
    <?php if($is_edit && $rawLines && count($rawLines)>0): ?>
      <h5 class="mb-2">Raw Material Lines (from Plate Plan)</h5>
      <div class="alert alert-light border small">Weight (kg) will be recalculated on save from L×W×T×ρ and Qty (ρ defaults to 7.85 g/cc).</div>
      <div class="table-responsive mb-4">
        <table class="table table-sm align-middle" id="rmTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Description</th>
              <?php if($haveDims): ?><th style="width:120px;">L (mm)</th><th style="width:120px;">W (mm)</th><th style="width:120px;">T (mm)</th><?php endif; ?>
              <th class="text-end" style="width:140px;">Qty</th>
              <th style="width:110px;">UOM</th>
              <th class="text-end" style="width:140px;">Weight (kg)</th>
              <th>Source</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rawLines as $rl): ?>
              <tr data-id="<?=$rl['id']?>">
                <td><?= (int)$rl['id'] ?></td>
                <td><?= htmlspecialchars((string)($rl['description'] ?? '')) ?></td>
                <?php if($haveDims): ?>
                  <td><?php if(($hdr['status']??'draft')==='draft'): ?><input class="form-control form-control-sm rm_L" type="number" step="0.001" value="<?=htmlspecialchars($rl['length_mm'] ?? '')?>"><?php else: ?><?=htmlspecialchars($rl['length_mm'] ?? '')?><?php endif; ?></td>
                  <td><?php if(($hdr['status']??'draft')==='draft'): ?><input class="form-control form-control-sm rm_W" type="number" step="0.001" value="<?=htmlspecialchars($rl['width_mm'] ?? '')?>"><?php else: ?><?=htmlspecialchars($rl['width_mm'] ?? '')?><?php endif; ?></td>
                  <td><?php if(($hdr['status']??'draft')==='draft'): ?><input class="form-control form-control-sm rm_T" type="number" step="0.001" value="<?=htmlspecialchars($rl['thickness_mm'] ?? '')?>"><?php else: ?><?=htmlspecialchars($rl['thickness_mm'] ?? '')?><?php endif; ?></td>
                <?php endif; ?>
                <td class="text-end">
                  <?php if(($hdr['status']??'draft')==='draft'): ?>
                    <input class="form-control form-control-sm rm_qty text-end" type="number" step="0.001" value="<?=htmlspecialchars($rl['qty'] ?? '')?>">
                  <?php else: ?>
                    <?= number_format((float)($rl['qty'] ?? 0), 3) ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if(($hdr['status']??'draft')==='draft'): ?>
                    <select class="form-select form-select-sm rm_uom">
                      <option value="">—</option>
                      <?php foreach($uoms as $u): ?>
                        <option value="<?=$u['id']?>" <?= (string)($rl['qty_uom_id']??'')===(string)$u['id'] ? 'selected':'' ?>><?=htmlspecialchars($u['code'])?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <?= htmlspecialchars($rl['uom_code'] ?? '') ?>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?= ($rl['wkg']!==null && $rl['wkg']!=='') ? number_format((float)$rl['wkg'],3) : '—' ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($rl['source_type'] ?? 'RMI') ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <!-- CONSUMABLE LINES (editable) -->
    <h5 class="mb-2">Lines</h5>
    <?php if(($hdr['status']??'draft')==='draft'): ?>
      <div class="mb-2 d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#indentModal">+ Import from Indent</button>
        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddLine">+ Add Line</button>
      </div>
    <?php endif; ?>
    <div id="linesWrap" class="mb-2"></div>

    <hr class="my-4">

    <h5 class="mb-2">Suppliers</h5>

    <!-- SERVER-SIDE rendered vendor rows -->
    <div id="vendorsWrap" class="mb-2">
      <?php if($vendors): foreach($vendors as $v): ?>
        <div class="row g-2 align-items-end mb-2 border rounded p-2">
          <div class="col-md-4">
            <label class="form-label">Supplier</label>
            <select class="form-select vn_party" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
              <option value="">—</option>
              <?php foreach($parties as $p): ?>
                <option value="<?=$p['id']?>" <?= (string)$v['party_id']===(string)$p['id'] ? 'selected':'' ?>>
                  <?= htmlspecialchars(trim(($p['code']?('['.$p['code'].'] '):'').$p['name'])) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Contact Name</label>
            <input class="form-control vn_name" value="<?=htmlspecialchars($v['contact_name']??'')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
          </div>
          <div class="col-md-3">
            <label class="form-label">Email</label>
            <input class="form-control vn_email" value="<?=htmlspecialchars($v['contact_email']??'')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
          </div>
          <div class="col-md-2">
            <label class="form-label">Phone</label>
            <input class="form-control vn_phone" value="<?=htmlspecialchars($v['contact_phone']??'')?>" <?=($hdr['status']??'draft')!=='draft'?'disabled':''?>>
          </div>
          <?php if(($hdr['status']??'draft')==='draft'): ?>
            <div class="col-12 text-end">
              <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove()">Remove</button>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; elseif(($hdr['status']??'draft')==='draft'): ?>
        <!-- If no vendors yet & draft, show one blank row -->
        <div class="row g-2 align-items-end mb-2 border rounded p-2">
          <div class="col-md-4">
            <label class="form-label">Supplier</label>
            <select class="form-select vn_party">
              <option value="">—</option>
              <?php foreach($parties as $p): ?>
                <option value="<?=$p['id']?>"><?= htmlspecialchars(trim(($p['code']?('['.$p['code'].'] '):'').$p['name'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Contact Name</label>
            <input class="form-control vn_name" value="">
          </div>
          <div class="col-md-3">
            <label class="form-label">Email</label>
            <input class="form-control vn_email" value="">
          </div>
          <div class="col-md-2">
            <label class="form-label">Phone</label>
            <input class="form-control vn_phone" value="">
          </div>
          <div class="col-12 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove()">Remove</button>
          </div>
        </div>
      <?php elseif(($hdr['status']??'')!=='draft'): ?>
        <div class="text-muted small">No suppliers added.</div>
      <?php endif; ?>
    </div>

    <?php if(($hdr['status']??'draft')==='draft'): ?>
      <button class="btn btn-sm btn-outline-primary" type="button" id="btnAddVendor">+ Add Supplier</button>
    <?php endif; ?>

    <div class="mt-3 d-flex gap-2">
      <?php if(($hdr['status']??'draft')==='draft'): ?><button class="btn btn-primary" type="submit">Save</button><?php endif; ?>
      <a class="btn btn-outline-secondary" href="/purchase/inquiries_list.php">Back</a>
    </div>
  </form>
</div>

<!-- Indent Modal -->
<div class="modal fade" id="indentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Select Indent</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="text" id="indentSearch" class="form-control mb-2" placeholder="Search indent no...">
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead><tr><th>ID</th><th>Indent No</th><th>Project</th><th>Status</th><th></th></tr></thead>
            <tbody id="indentTable"></tbody>
          </table>
        </div>
        <div id="indentLoadMsg" class="text-muted small"></div>
      </div>
    </div>
  </div>
</div>

<style>.lock-badge{font-size:.75rem;}</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const isDraft = <?= json_encode(($hdr['status']??'draft')==='draft') ?>;
  const uoms    = <?= json_encode($uoms) ?>;
  const subcats = <?= json_encode($subcats) ?>;
  const parties = <?= json_encode($parties) ?>;
  const partiesMap = Object.fromEntries(parties.map(p => [String(p.id), p]));
  const existingLines   = <?= json_encode($lines) ?>;
  const existingVendors = <?= json_encode($vendors) ?>;
  const haveDims = <?= json_encode($haveDims) ?>;

  const linesWrap   = document.getElementById('linesWrap');
  const vendorsWrap = document.getElementById('vendorsWrap');

  function opt(v,t){ const o=document.createElement('option'); o.value=v; o.textContent=t; return o; }

  /* ---- Consumable line rows (built client-side) ---- */
  function lineRow(d={}) {
    const locked = !!d.locked || false;
    const w = document.createElement('div');
    w.className = 'row g-2 align-items-end mb-2 border rounded p-2 position-relative';
    w.innerHTML = `
      ${locked ? '<div class="position-absolute top-0 end-0 p-2 text-muted"><span class="badge bg-light text-dark lock-badge">Imported (locked)</span></div>' : ''}
      <div class="col-md-3">
        <label class="form-label">Subcategory</label>
        <select class="form-select ln_subcat" ${(!isDraft || locked)?'disabled':''}></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Item</label>
        <select class="form-select ln_item" ${(!isDraft || locked)?'disabled':''}>
          <option value="">—</option>
        </select>
        <div class="form-text small text-muted d-none ln_item_hint">Pick a subcategory (or All Items)</div>
      </div>
      <div class="col-md-2">
        <label class="form-label">Qty</label>
        <input class="form-control ln_qty" type="number" step="0.000001" value="${d.qty||''}" ${(!isDraft || locked)?'disabled':''}>
      </div>
      <div class="col-md-2">
        <label class="form-label">UOM</label>
        <select class="form-select ln_uom" ${(!isDraft || locked)?'disabled':''}></select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Needed By</label>
        <input class="form-control ln_need" type="date" value="${d.needed_by||''}" ${(!isDraft || locked)?'disabled':''}>
      </div>
      <div class="col-12">
        <label class="form-label">Line Notes</label>
        <input class="form-control ln_notes" value="${d.line_notes||''}" ${(!isDraft || locked)?'disabled':''}>
      </div>
      <input type="hidden" class="ln_indent_id" value="${d.indent_id||''}">
      <input type="hidden" class="ln_indent_item_id" value="${d.indent_item_id||''}">
      ${isDraft ? '<div class="col-12 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'.row\').remove()">Remove</button></div>' : ''}
    `;

    const scSel = w.querySelector('.ln_subcat');
    subcats.forEach(sc => scSel.appendChild(opt(sc.id, sc.label)));
    if (d.subcategory_id !== undefined && d.subcategory_id !== null) scSel.value = String(d.subcategory_id);

    const itemSel = w.querySelector('.ln_item');
    const uomSel  = w.querySelector('.ln_uom');
    uoms.forEach(u => uomSel.appendChild(opt(u.id, u.code)));
    if (d.uom_id) uomSel.value = String(d.uom_id);

    if (!locked && isDraft) {
      scSel.addEventListener('change', ()=>{
        loadItemsForSubcat(w, scSel.value || '0', null);
        itemSel.value = '';
      });
    }
    const preItem = d.item_id ? String(d.item_id) : '';
    const preSc   = (d.subcategory_id!==undefined && d.subcategory_id!==null) ? String(d.subcategory_id) : (subcats.length? String(subcats[0].id) : '0');
    loadItemsForSubcat(w, preSc || '0', preItem);
    return w;
  }

  async function fetchJSON(url){
    const res = await fetch(url, {headers: {'Accept':'application/json'}});
    if(!res.ok) throw new Error('HTTP '+res.status);
    return await res.json();
  }

  async function loadItemsForSubcat(rowEl, subcatId, selectedItemId){
    const itemSel = rowEl.querySelector('.ln_item');
    const hint    = rowEl.querySelector('.ln_item_hint');
    itemSel.innerHTML = '<option value="">Loading…</option>';
    try{
      const js = await fetchJSON('/purchase/items_options.php?subcategory_id=' + encodeURIComponent(subcatId || '0'));
      itemSel.innerHTML = '<option value="">—</option>';
      if (js && js.ok && Array.isArray(js.items) && js.items.length) {
        js.items.forEach(it=>{
          const op = document.createElement('option');
          op.value = it.id;
          op.textContent = it.label;
          if (selectedItemId && String(selectedItemId) === String(it.id)) op.selected = true;
          itemSel.appendChild(op);
        });
        if(hint) hint.classList.add('d-none');
      } else {
        if(hint){ hint.textContent = 'No items found'; hint.classList.remove('d-none'); }
      }
    } catch(e){
      itemSel.innerHTML = '<option value="">—</option>';
      if(hint){ hint.textContent = 'Failed to load items'; hint.classList.remove('d-none'); }
    }
  }

  function addLine(d={}){ linesWrap.appendChild(lineRow(d)); }

  /* ---- Vendors: auto-fill contacts when supplier changes ---- */
  function attachVendorRowBehavior(row){
    const sel   = row.querySelector('.vn_party');
    const nameI = row.querySelector('.vn_name');
    const mailI = row.querySelector('.vn_email');
    const phI   = row.querySelector('.vn_phone');
    if (!sel || !nameI || !mailI || !phI) return;

    function fill(){
      const p = partiesMap[String(sel.value)] || null;
      if (!p) { nameI.value=''; mailI.value=''; phI.value=''; return; }
      nameI.value = p.contact_name || '';
      mailI.value = p.email || '';
      phI.value   = p.phone || '';
    }
    sel.addEventListener('change', fill);

    // Initial fill if a supplier is already selected and fields are empty
    if (sel.value && !(nameI.value || mailI.value || phI.value)) fill();
  }

  function addVendorRowBlank(){
    const w = document.createElement('div');
    w.className = 'row g-2 align-items-end mb-2 border rounded p-2';
    w.innerHTML = `
      <div class="col-md-4">
        <label class="form-label">Supplier</label>
        <select class="form-select vn_party">
          <option value="">—</option>
          ${parties.map(p=>`<option value="${p.id}">${((p.code?('['+p.code+'] '):'')+p.name).replace(/</g,'&lt;')}</option>`).join('')}
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Contact Name</label>
        <input class="form-control vn_name" value="">
      </div>
      <div class="col-md-3">
        <label class="form-label">Email</label>
        <input class="form-control vn_email" value="">
      </div>
      <div class="col-md-2">
        <label class="form-label">Phone</label>
        <input class="form-control vn_phone" value="">
      </div>
      <div class="col-12 text-end">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove()">Remove</button>
      </div>
    `;
    vendorsWrap.appendChild(w);
    attachVendorRowBehavior(w);
  }

  const btnAddVendor = document.getElementById('btnAddVendor');
  if (btnAddVendor) btnAddVendor.addEventListener('click', addVendorRowBlank);

  // Attach behavior to server-rendered vendor rows
  vendorsWrap.querySelectorAll('.row.g-2').forEach(attachVendorRowBehavior);

  /* Preload consumables client-side */
  existingLines.forEach(ln => addLine({
    subcategory_id: ln.subcategory_id ?? 0,
    item_id: ln.item_id,
    qty: ln.qty,
    uom_id: ln.uom_id,
    needed_by: ln.needed_by,
    line_notes: ln.line_notes,
    indent_id: ln.indent_id ?? null,
    indent_item_id: ln.indent_item_id ?? null,
    locked: !!(ln.indent_id || ln.indent_item_id)
  }));

  /* Serialize before submit */
  const form = document.getElementById('inqForm');
  form.addEventListener('submit', () => {
    // Consumables
    const lineRows = Array.from(linesWrap.querySelectorAll('.row.g-2'));
    const lines = lineRows.map(r => ({
      item_id: Number(r.querySelector('.ln_item')?.value || 0),
      qty: parseFloat(r.querySelector('.ln_qty')?.value || '0'),
      uom_id: Number(r.querySelector('.ln_uom')?.value || 0),
      needed_by: r.querySelector('.ln_need')?.value || null,
      line_notes: r.querySelector('.ln_notes')?.value || null,
      indent_id: Number(r.querySelector('.ln_indent_id')?.value || 0) || null,
      indent_item_id: Number(r.querySelector('.ln_indent_item_id')?.value || 0) || null
    })).filter(x => x.item_id>0 && x.qty>0 && x.uom_id>0);

    // Vendors (server-rendered + any added client-side)
    const vendorRows = Array.from(vendorsWrap.querySelectorAll('.row.g-2'));
    const vendors = vendorRows.map(r => ({
      party_id: Number(r.querySelector('.vn_party')?.value || 0),
      contact_name: r.querySelector('.vn_name')?.value || '',
      contact_email: r.querySelector('.vn_email')?.value || '',
      contact_phone: r.querySelector('.vn_phone')?.value || ''
    })).filter(v => v.party_id>0);

    // RMI dims (draft)
    let rmLines = [];
    const rmTable = document.getElementById('rmTable');
    if (rmTable && isDraft) {
      rmLines = Array.from(rmTable.querySelectorAll('tbody tr')).map(tr => ({
        id: Number(tr.getAttribute('data-id')),
        length_mm: tr.querySelector('.rm_L')?.value ?? null,
        width_mm: tr.querySelector('.rm_W')?.value ?? null,
        thickness_mm: tr.querySelector('.rm_T')?.value ?? null,
        qty: tr.querySelector('.rm_qty')?.value ?? null,
        qty_uom_id: tr.querySelector('.rm_uom')?.value ?? null
      }));
    }

    document.getElementById('lines_json').value    = JSON.stringify(lines);
    document.getElementById('vendors_json').value  = JSON.stringify(vendors);
    document.getElementById('rm_lines_json').value = JSON.stringify(rmLines);
  });

  /* ----- Indent modal logic ----- */
  const indentTable   = document.getElementById('indentTable');
  const indentSearch  = document.getElementById('indentSearch');
  const indentLoadMsg = document.getElementById('indentLoadMsg');
  const indentModalEl = document.getElementById('indentModal');

  async function safeJson(res) {
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    if (!ct.includes('application/json')) {
      const text = await res.text();
      throw new Error('NON_JSON:' + text.slice(0, 500));
    }
    return res.json();
  }

  async function loadIndents(q=''){
    indentLoadMsg.textContent = 'Loading...';
    indentTable.innerHTML = '';
    try {
      const res = await fetch('/purchase/inquiry_indent_picker.php?q='+encodeURIComponent(q||'')); 
      if (!res.ok) { const t = await res.text(); throw new Error('HTTP '+res.status+': '+t.slice(0,500)); }
      const rows = await safeJson(res);
      indentLoadMsg.textContent = rows.length ? '' : 'No indents found.';
      rows.forEach(r=>{
        const tr=document.createElement('tr');
        tr.innerHTML = `
          <td>${r.id}</td>
          <td>${r.indent_no}</td>
          <td>${r.project_name||''}</td>
          <td>${r.status}</td>
          <td><button type="button" class="btn btn-sm btn-primary" data-id="${r.id}">Import</button></td>`;
        indentTable.appendChild(tr);
      });
    } catch(e){
      indentLoadMsg.textContent = 'Error: ' + e.message.substring(0,120);
      alert('Indent load error: ' + e.message.substring(0,500));
      console.error(e);
    }
  }

  if (indentModalEl) indentModalEl.addEventListener('shown.bs.modal', ()=>loadIndents(''));
  if (indentSearch)  indentSearch.addEventListener('input', (e)=>loadIndents(e.target.value||''));

  indentTable.addEventListener('click', async (ev)=>{
    const btn = ev.target.closest('button[data-id]'); if (!btn) return;
    const id = btn.getAttribute('data-id');
    try {
      const res = await fetch('/purchase/inquiry_import_indent.php?indent_id='+encodeURIComponent(id));
      if (!res.ok) { const t = await res.text(); throw new Error('HTTP '+res.status+': '+t.slice(0,500)); }
      const rows = await safeJson(res);
      if (!rows.length) { alert('No items in this indent'); return; }
      const projId = rows[0]?.project_id || null;
      const projectSel = document.getElementById('project_id');
      if (projId && projectSel) projectSel.value = String(projId);
      rows.forEach(ln=>{
        linesWrap.appendChild(lineRow({
          subcategory_id: ln.subcategory_id ?? 0,
          indent_id: ln.indent_id,
          indent_item_id: ln.indent_item_id,
          item_id: ln.item_id,
          qty: ln.qty,
          uom_id: ln.uom_id,
          needed_by: ln.needed_by,
          line_notes: ln.line_notes,
          locked: true
        }));
      });
      const modal = bootstrap.Modal.getInstance(indentModalEl);
      if (modal) modal.hide();
    } catch(e){
      alert('Import error: ' + e.message.substring(0,500));
      console.error(e);
    }
  });

});
</script>
<?php include __DIR__ . '/../ui/layout_end.php';