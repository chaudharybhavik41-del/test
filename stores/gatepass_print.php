<?php
/** PATH: /public_html/stores/gatepass_print.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('stores.gatepass.view');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$hdr = $pdo->prepare("
  SELECT gp.*, w1.code src_code, w1.name src_name, w2.code dst_code, w2.name dst_name, p.name party_name
  FROM gatepasses gp
  JOIN warehouses w1 ON w1.id = gp.source_warehouse_id
  LEFT JOIN warehouses w2 ON w2.id = gp.dest_warehouse_id
  LEFT JOIN parties p ON p.id = gp.party_id
  WHERE gp.id=?");
$hdr->execute([$id]);
$gp = $hdr->fetch(PDO::FETCH_ASSOC);

$lines = $pdo->prepare("
  SELECT gi.*, it.material_code, it.name item_name, u.code uom_code,
         m.machine_id mach_code, m.name mach_name
  FROM gatepass_items gi
  LEFT JOIN items it ON it.id=gi.item_id
  LEFT JOIN uom u ON u.id=gi.uom_id
  LEFT JOIN machines m ON m.id=gi.machine_id
  WHERE gi.gp_id=?
  ORDER BY item_name, mach_name");
$lines->execute([$id]);
$rows = $lines->fetchAll(PDO::FETCH_ASSOC);

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Gate Pass <?= htmlspecialchars($gp['gp_no'] ?? '') ?></title>
  <link href="/assets/bootstrap.min.css" rel="stylesheet">
  <style> @media print { .noprint{display:none} } </style>
</head>
<body class="p-3">
  <div class="d-flex justify-content-between noprint">
    <a href="gatepass_list.php" class="btn btn-sm btn-outline-secondary">Back</a>
    <button class="btn btn-sm btn-primary" onclick="window.print()">Print</button>
  </div>
  <h4 class="mt-2">Gate Pass — <?= htmlspecialchars($gp['gp_no'] ?? '') ?></h4>
  <div class="row g-1 mb-2">
    <div class="col-6"><strong>Date:</strong> <?= htmlspecialchars($gp['gp_date'] ?? '') ?></div>
    <div class="col-6"><strong>Type:</strong> <?= htmlspecialchars($gp['gp_type'] ?? '') ?> (<?= !empty($gp['returnable'])?'Returnable':'Non-returnable' ?>)</div>
    <div class="col-6"><strong>From:</strong> <?= htmlspecialchars(($gp['src_code']??'').' — '.($gp['src_name']??'')) ?></div>
    <div class="col-6"><strong>To/Party:</strong>
      <?= ($gp['gp_type']==='site' && !$gp['returnable'])
          ? htmlspecialchars(($gp['dst_code']??'').' — '.($gp['dst_name']??''))
          : htmlspecialchars($gp['party_name'] ?? '—') ?>
    </div>
    <div class="col-6"><strong>Vehicle No:</strong> <?= htmlspecialchars($gp['vehicle_no'] ?? '—') ?></div>
    <div class="col-6"><strong>Contact:</strong> <?= htmlspecialchars($gp['contact_person'] ?? '—') ?> (<?= htmlspecialchars($gp['contact_phone'] ?? '') ?>)</div>
    <div class="col-6"><strong>Expected Return:</strong> <?= htmlspecialchars($gp['expected_return_date'] ?? '—') ?></div>
    <div class="col-12"><strong>Remarks:</strong> <?= htmlspecialchars($gp['remarks'] ?? '') ?></div>
  </div>

  <table class="table table-sm table-bordered align-middle">
    <thead class="table-light">
      <tr>
        <th style="width:50px">#</th>
        <th>Description</th>
        <th class="text-center" style="width:90px">UOM</th>
        <th class="text-end" style="width:120px">Qty</th>
        <th>Note</th>
      </tr>
    </thead>
    <tbody>
      <?php $i=1; foreach ($rows as $r): ?>
        <?php if ((int)$r['is_asset']===1): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td>Machine: <?= htmlspecialchars(($r['mach_code']?($r['mach_code'].' — '):'').($r['mach_name']??'')) ?></td>
            <td class="text-center">—</td>
            <td class="text-end">—</td>
            <td><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
          </tr>
        <?php else: ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars(($r['material_code']?($r['material_code'].' — '):'').($r['item_name']??'')) ?></td>
            <td class="text-center"><?= htmlspecialchars($r['uom_code'] ?? '') ?></td>
            <td class="text-end"><?= number_format((float)$r['qty'],3) ?></td>
            <td><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
          </tr>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="mt-4">
    <div><strong>Storekeeper Signature:</strong> ______________________</div>
    <div><strong>Receiver Signature:</strong> ________________________</div>
    <div class="small text-muted mt-2">Status: <?= htmlspecialchars($gp['status'] ?? '') ?></div>
  </div>
</body>
</html>
