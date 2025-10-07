<?php
declare(strict_types=1);
/** PATH: /public_html/bom/routing_print.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('bom.routing.view');

$pdo = db();
$bom_id = (int)($_GET['bom_id'] ?? 0);
if ($bom_id <= 0) { http_response_code(400); exit('Missing bom_id'); }

$hdr = $pdo->prepare("SELECT bom_no, revision, status, created_at FROM bom WHERE id=?");
$hdr->execute([$bom_id]);
$h = $hdr->fetch(PDO::FETCH_ASSOC);
if (!$h) { http_response_code(404); exit('BOM not found'); }

$sql = "SELECT
          bc.id AS bom_component_id,
          IFNULL(bc.sort_order, 999999) AS comp_sort,
          bc.description AS comp_desc,
          ro.seq_no, p.code AS pcode, p.name AS pname,
          wc.code AS wccode, wc.name AS wcname,
          ro.setup_min, ro.run_min, ro.inspection_gate, ro.notes
        FROM bom_components bc
        LEFT JOIN routing_ops ro ON ro.bom_component_id = bc.id
        LEFT JOIN processes p ON p.id = ro.process_id
        LEFT JOIN work_centers wc ON wc.id = ro.work_center_id
        WHERE bc.bom_id = ?
        ORDER BY comp_sort, bc.id, ro.seq_no";
$stmt = $pdo->prepare($sql);
$stmt->execute([$bom_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byComp = [];
foreach ($rows as $r) $byComp[$r['bom_component_id']][] = $r;

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?=htmlspecialchars(($h['bom_no'] ?? ('BOM-'.$bom_id)).' — Routing')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="/assets/bootstrap.min.css" rel="stylesheet">
<style>
  body { padding: 16px; }
  .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom: 12px; }
  .print-meta { font-size:.9rem; color:#666; }
  @media print { .no-print { display:none!important; } .card { border:0; } .table { font-size: .9rem; } }
</style>
</head>
<body>
  <div class="page-header">
    <div>
      <h1 class="h4 mb-0">Routing — <?=htmlspecialchars($h['bom_no'])?></h1>
      <div class="print-meta">Status: <?=htmlspecialchars($h['status'])?><?= $h['revision'] ? ' · Rev: '.htmlspecialchars($h['revision']) : '' ?> · Created: <?=htmlspecialchars($h['created_at'])?></div>
    </div>
    <button class="btn btn-sm btn-primary no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>

  <?php foreach ($byComp as $cid => $rows): ?>
    <div class="card mb-3">
      <div class="card-header"><strong>Component #<?=$cid?></strong> — <?=htmlspecialchars($rows[0]['comp_desc'] ?? '')?></div>
      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:80px;">Seq</th>
              <th>Process</th>
              <th>Work Center</th>
              <th class="text-end" style="width:120px;">Setup (min)</th>
              <th class="text-end" style="width:120px;">Run (min)</th>
              <th style="width:70px;">IG</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows || !$rows[0]['pcode']): ?>
            <tr><td colspan="7" class="text-muted text-center py-3">No steps.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?=htmlspecialchars((string)$r['seq_no'])?></td>
              <td><?=htmlspecialchars(($r['pcode'] ?? '').' — '.($r['pname'] ?? ''))?></td>
              <td><?=htmlspecialchars(($r['wccode'] ?? '').' — '.($r['wcname'] ?? ''))?></td>
              <td class="text-end"><?= $r['setup_min']!==null ? number_format((float)$r['setup_min'],2) : '—' ?></td>
              <td class="text-end"><?= $r['run_min']!==null ? number_format((float)$r['run_min'],2) : '—' ?></td>
              <td class="text-center"><?= (int)$r['inspection_gate'] ? 'Yes' : '—' ?></td>
              <td><?=htmlspecialchars($r['notes'] ?? '')?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
</body>
</html>
