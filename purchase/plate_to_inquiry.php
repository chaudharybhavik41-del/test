<?php
/** PATH: /public_html/purchase/plate_to_inquiry.php
 * BUILD: 2025-10-03T09:35:52 IST
 * Purpose: Convert a plate plan's plates into a Purchase Inquiry (idempotent).
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = db();
header('Content-Type: text/html; charset=utf-8');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function has_table(PDO $pdo, string $t): bool {
  try { $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $st->execute([$t]); return (bool)$st->fetchColumn(); }
  catch (Throwable $e) { return false; }
}

/* input */
$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
if ($plan_id<=0) { http_response_code(400); echo "Missing plan_id"; exit; }

/* load plan */
$st = $pdo->prepare("SELECT pl.*, prj.id AS project_id_real
                     FROM plate_plans pl
                     LEFT JOIN projects prj ON prj.id = pl.project_id
                     WHERE pl.id=?");
$st->execute([$plan_id]); $plan = $st->fetch(PDO::FETCH_ASSOC);
if (!$plan) { http_response_code(404); echo "Plan not found"; exit; }
$project_id = (int)($plan['project_id'] ?? 0);

/* find or create inquiry header */
$marker = "[AUTO:plate_plan_id=".$plan_id."]";
$inq_id = null;
$chk = $pdo->prepare("SELECT id FROM inquiries WHERE status='draft' AND project_id <=> ? AND notes LIKE ? ORDER BY id DESC LIMIT 1");
$chk->execute([$project_id, "%".$marker."%"]);
$inq_id = (int)($chk->fetchColumn() ?: 0);

if ($inq_id<=0) {
  /* generate inquiry_no */
  $inquiry_no = null;
  if (has_table($pdo, 'inquiry_sequences')) {
    $yr = (int)date('Y');
    $pdo->beginTransaction();
    try {
      $sel = $pdo->prepare("SELECT seq FROM inquiry_sequences WHERE yr=? FOR UPDATE");
      $sel->execute([$yr]);
      $seq = $sel->fetchColumn();
      if ($seq === false) {
        $seq = 1;
        $ins = $pdo->prepare("INSERT INTO inquiry_sequences (yr, seq) VALUES (?, ?)");
        $ins->execute([$yr, $seq]);
      } else {
        $seq = (int)$seq + 1;
        $upd = $pdo->prepare("UPDATE inquiry_sequences SET seq=? WHERE yr=?");
        $upd->execute([$seq, $yr]);
      }
      $pdo->commit();
      $inquiry_no = sprintf("INQ-%d-%04d", $yr, $seq);
    } catch (Throwable $e) {
      $pdo->rollBack();
      $inquiry_no = "INQ-".date('Ymd-His');
    }
  } else {
    $inquiry_no = "INQ-".date('Ymd-His');
  }

  $ins = $pdo->prepare("INSERT INTO inquiries
    (inquiry_no, project_id, inquiry_date, status, notes, created_by, created_at)
    VALUES (?, ?, CURDATE(), 'draft', ?, ?, NOW())");
  $ins->execute([$inquiry_no, $project_id, "Auto-created from Plate Plan #".$plan_id." ".$marker, (int)($_SESSION['user_id'] ?? 0)]);
  $inq_id = (int)$pdo->lastInsertId();
}

/* upsert inquiry lines from plates (only NEW plates) */
$plates = $pdo->prepare("SELECT * FROM plate_plan_plates WHERE plan_id=? AND source_type='new' ORDER BY id");
$plates->execute([$plan_id]);

$selLine = $pdo->prepare("SELECT id FROM inquiry_lines WHERE inquiry_id=? AND source_type='RMI' AND source_line_id=? LIMIT 1");
$insLine = $pdo->prepare("INSERT INTO inquiry_lines
  (inquiry_id, source_type, source_line_id, item_id, description, length_mm, width_mm, thickness_mm, qty, qty_uom_id, weight_kg, needed_by, supplier_hint_id, project_id)
  VALUES (?, 'RMI', ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, NULL, ?)");
$updLine = $pdo->prepare("UPDATE inquiry_lines
  SET item_id=?, description=?, length_mm=?, width_mm=?, thickness_mm=?, qty=?, weight_kg=?, project_id=?
  WHERE id=?");

$added = 0; $updated = 0; $skipped = 0;
while ($pl = $plates->fetch(PDO::FETCH_ASSOC)) {
  $plateId = (int)$pl['id'];
  $itemId  = (int)($pl['item_id'] ?? 0);
  $L = (float)$pl['length_mm']; $W = (float)$pl['width_mm']; $T = (float)$pl['thickness_mm'];
  $qty = (float)$pl['qty_nos']; $kg = (float)($pl['total_plate_kg'] ?? 0);
  $desc = sprintf("PLATE %s×%s×%s mm",
    rtrim(rtrim(number_format($L,3,'.',''), '0'),'.'),
    rtrim(rtrim(number_format($W,3,'.',''), '0'),'.'),
    rtrim(rtrim(number_format($T,3,'.',''), '0'),'.')
  );

  if ($itemId<=0) { $skipped++; continue; }

  $selLine->execute([$inq_id, $plateId]);
  $lineId = (int)($selLine->fetchColumn() ?: 0);
  if ($lineId>0) {
    $updLine->execute([$itemId, $desc, $L, $W, $T, $qty, $kg, $project_id, $lineId]);
    $updated++;
  } else {
    $insLine->execute([$inq_id, $plateId, $itemId, $desc, $L, $W, $T, $qty, $kg, $project_id]);
    $added++;
  }
}

/* final */
$goto = "plate_plan_form.php?id=".$plan_id."&inq_id=".$inq_id."&added=".$added."&updated=".$updated."&skipped=".$skipped."&ok=1";
header("Location: ".$goto);
exit;
