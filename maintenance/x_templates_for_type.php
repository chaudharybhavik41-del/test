<?php
/** PATH: /public_html/maintenance/x_templates_for_type.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_login();
require_permission('machines.manage');

$pdo = db();
$type_id = (int)($_GET['type_id'] ?? 0);
$maintenance_type_id = (int)($_GET['maintenance_type_id'] ?? 0);

$st = $pdo->prepare("SELECT id, title FROM maintenance_program_templates
                      WHERE type_id=? AND maintenance_type_id=?
                      ORDER BY title");
$st->execute([$type_id, $maintenance_type_id]); // FKs on both columns. :contentReference[oaicite:9]{index=9}
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'rows'=>$rows]);
