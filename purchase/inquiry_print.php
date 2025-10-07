<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login(); require_permission('purchase.inquiry.view');

$pdo=db(); $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$id=(int)($_GET['id']??0);

$h = $pdo->prepare("SELECT i.*, p.code AS project_code, p.name AS project_name, l.code AS loc_code, l.name AS loc_name
                    FROM inquiries i
                    LEFT JOIN projects p ON p.id=i.project_id
                    LEFT JOIN locations l ON l.id=i.location_id
                    WHERE i.id=?");
$h->execute([$id]); $hdr=$h->fetch(PDO::FETCH_ASSOC);

$ls = $pdo->prepare("SELECT ii.*, it.material_code, it.name AS item_name, u.code AS uom_code, m.name AS make_name
                     FROM inquiry_items ii JOIN items it ON it.id=ii.item_id
                     JOIN uom u ON u.id=ii.uom_id
                     LEFT JOIN makes m ON m.id=ii.make_id
                     WHERE ii.inquiry_id=? ORDER BY ii.id");
$ls->execute([$id]); $lines=$ls->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html><head>
<meta charset="utf-8">
<title>Inquiry <?=htmlspecialchars($hdr['inquiry_no']??'')?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>@media print {.no-print{display:none}}</style>
</head><body class="p-4">
<div class="container">
  <div class="d-flex justify-content-between align-items-start">
    <div>
      <h2 class="h4 mb-1">Purchase Inquiry</h2>
      <div><strong>No:</strong> <?=htmlspecialchars($hdr['inquiry_no']??'')?></div>
      <div><strong>Date:</strong> <?=htmlspecialchars($hdr['inquiry_date']??'')?></div>
      <?php if(!empty($hdr['project_name'])): ?><div><strong>Project:</strong> <?=htmlspecialchars(($hdr['project_code']??'').' — '.$hdr['project_name'])?></div><?php endif; ?>
      <?php if(!empty($hdr['loc_name'])): ?><div><strong>Location:</strong> <?=htmlspecialchars(($hdr['loc_code']??'').' — '.$hdr['loc_name'])?></div><?php endif; ?>
    </div>
    <div class="no-print"><button class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button></div>
  </div>

  <hr>
  <table class="table table-sm">
    <thead class="table-light"><tr>
      <th>#</th><th>Item</th><th>Preferred Make</th><th class="text-end">Qty</th><th>UOM</th><th>Needed By</th><th>Notes</th>
    </tr></thead>
    <tbody>
    <?php foreach($lines as $i=>$r): ?>
      <tr>
        <td><?=$i+1?></td>
        <td><div><strong><?=htmlspecialchars($r['material_code'])?></strong> — <?=htmlspecialchars($r['item_name'])?></div></td>
        <td><?=htmlspecialchars($r['make_name']??'')?></td>
        <td class="text-end"><?=htmlspecialchars($r['qty'])?></td>
        <td><?=htmlspecialchars($r['uom_code'])?></td>
        <td><?=htmlspecialchars($r['needed_by']??'')?></td>
        <td><?=htmlspecialchars($r['line_notes']??'')?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h6 class="mt-4">Commercial Terms</h6>
  <ul>
    <?php if(!empty($hdr['delivery_terms'])): ?><li><strong>Delivery:</strong> <?=htmlspecialchars($hdr['delivery_terms'])?></li><?php endif; ?>
    <?php if(!empty($hdr['payment_terms'])): ?><li><strong>Payment:</strong> <?=htmlspecialchars($hdr['payment_terms'])?></li><?php endif; ?>
    <?php if(!empty($hdr['freight_terms'])): ?><li><strong>Freight:</strong> <?=htmlspecialchars($hdr['freight_terms'])?></li><?php endif; ?>
    <li><strong>GST Inclusive?</strong> <?= ($hdr['gst_inclusive']??0)?'Yes':'No' ?></li>
    <?php if(!empty($hdr['valid_till'])): ?><li><strong>Valid till:</strong> <?=htmlspecialchars($hdr['valid_till'])?></li><?php endif; ?>
  </ul>

  <?php if(!empty($hdr['notes'])): ?>
    <div class="mt-3"><strong>Notes:</strong><br><?=nl2br(htmlspecialchars($hdr['notes']))?></div>
  <?php endif; ?>
</div>
</body></html>