<?php
/** PATH: /public_html/maintenance/x_machine_type.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_permission('machines.manage');

$pdo = db();
$machine_id = (int)($_GET['machine_id'] ?? 0);
if(!$machine_id){ echo json_encode(['ok'=>false,'msg'=>'machine_id required']); exit; }

$st = $pdo->prepare("SELECT type_id FROM machines WHERE id=?");
$st->execute([$machine_id]); // FK exists (machines→machine_types). :contentReference[oaicite:8]{index=8}
$type_id = $st->fetchColumn();

header('Content-Type: application/json');
echo json_encode(['ok'=>(bool)$type_id,'type_id'=>(int)$type_id]);
