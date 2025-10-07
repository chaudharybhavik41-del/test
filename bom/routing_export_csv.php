<?php
declare(strict_types=1);
/** PATH: /public_html/bom/routing_export_csv.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('bom.routing.view');

$pdo = db();
$bom_id = (int)($_GET['bom_id'] ?? 0);
if ($bom_id <= 0) { http_response_code(400); exit('Missing bom_id'); }

// BOM no for filename
$hdr = $pdo->prepare("SELECT bom_no FROM bom WHERE id=?");
$hdr->execute([$bom_id]);
$bom_no = (string)($hdr->fetchColumn() ?: ('BOM-'.$bom_id));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_\-]/','_', $bom_no).'-routing.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['BOM','ComponentID','Component','Seq','ProcessCode','ProcessName','WorkCenterCode','WorkCenterName','SetupMin','RunMin','InspectionGate','Notes']);

$sql = "SELECT
          b.bom_no,
          bc.id AS bom_component_id,
          bc.description AS comp_desc,
          ro.seq_no, p.code AS process_code, p.name AS process_name,
          wc.code AS wc_code, wc.name AS wc_name,
          ro.setup_min, ro.run_min, ro.inspection_gate, ro.notes
        FROM bom_components bc
        JOIN bom b ON b.id = bc.bom_id
        LEFT JOIN routing_ops ro ON ro.bom_component_id = bc.id
        LEFT JOIN processes p ON p.id = ro.process_id
        LEFT JOIN work_centers wc ON wc.id = ro.work_center_id
        WHERE bc.bom_id = ?
        ORDER BY IFNULL(bc.sort_order, 999999), bc.id, ro.seq_no, ro.id";
$stmt = $pdo->prepare($sql);
$stmt->execute([$bom_id]);

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  fputcsv($out, [
    $r['bom_no'],
    $r['bom_component_id'],
    $r['comp_desc'],
    $r['seq_no'],
    $r['process_code'],
    $r['process_name'],
    $r['wc_code'],
    $r['wc_name'],
    $r['setup_min'],
    $r['run_min'],
    (int)$r['inspection_gate'] ? 'Yes' : 'No',
    $r['notes'],
  ]);
}
fclose($out);
