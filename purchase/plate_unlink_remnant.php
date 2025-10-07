<?php
/** PATH: /public_html/purchase/plate_unlink_remnant.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login(); $pdo = db();

$plate_id = (int)($_GET['plate_id'] ?? $_POST['plate_id'] ?? 0);
if ($plate_id<=0) { http_response_code(400); echo "Missing plate_id"; exit; }

$pl = $pdo->prepare("SELECT id, plan_id FROM plate_plan_plates WHERE id=?");
$pl->execute([$plate_id]); $P = $pl->fetch();
if (!$P) { http_response_code(404); echo "Plate not found"; exit; }

$pdo->prepare("UPDATE plate_plan_plates SET source_type='new', source_lot_id=NULL WHERE id=?")->execute([$plate_id]);
header('Location: plate_plan_form.php?id='.(int)$P['plan_id'].'&ok=1');