<?php
/** PATH: /public_html/purchase/plate_link_remnant.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login(); $pdo = db();

$plate_id = (int)($_GET['plate_id'] ?? $_POST['plate_id'] ?? 0);
$lot_id   = (int)($_GET['lot_id']   ?? $_POST['lot_id']   ?? 0);
if ($plate_id<=0 || $lot_id<=0) { http_response_code(400); echo "Missing plate_id or lot_id"; exit; }

// basic checks
$pl = $pdo->prepare("SELECT id, plan_id, thickness_mm, item_id FROM plate_plan_plates WHERE id=?");
$pl->execute([$plate_id]); $P = $pl->fetch();
if (!$P) { http_response_code(404); echo "Plate not found"; exit; }

// optional: verify stock lot thickness close and item match if columns exist
$ok = true;
if ($lot_id>0) {
  try {
    $s = $pdo->prepare("SELECT item_id, thickness_mm FROM stock_lots WHERE id=?");
    $s->execute([$lot_id]); $L = $s->fetch();
    if ($L) {
      if (!empty($P['item_id']) && (int)$P['item_id'] !== (int)$L['item_id']) $ok=false;
      if (!empty($P['thickness_mm']) && !empty($L['thickness_mm']) && abs((float)$P['thickness_mm'] - (float)$L['thickness_mm']) > 0.5) $ok=false;
    }
  } catch (Throwable $e) { /* ignore */ }
}
if (!$ok) { http_response_code(400); echo "Item/Thickness mismatch"; exit; }

$pdo->prepare("UPDATE plate_plan_plates SET source_type='remnant', source_lot_id=? WHERE id=?")->execute([$lot_id, $plate_id]);
header('Location: plate_plan_form.php?id='.(int)$P['plan_id'].'&ok=1');
