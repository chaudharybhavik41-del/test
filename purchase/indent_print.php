<?php
/** PATH: /public_html/purchase/indent_print.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('purchase.indent.view');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo "Not found"; exit; }

/* Header + joins for labels */
$h = $pdo->prepare("
  SELECT i.*, pr.code AS project_code, pr.name AS project_name,
         loc.name AS delivery_location_name
  FROM indents i
  LEFT JOIN projects pr ON pr.id = i.project_id
  LEFT JOIN locations loc ON loc.id = i.delivery_location_id
  WHERE i.id = ?
");
$h->execute([$id]);
$indent = $h->fetch(PDO::FETCH_ASSOC);
if (!$indent) { http_response_code(404); echo "Indent not found"; exit; }

/* Lines with item & UOM labels */
$d = $pdo->prepare("
  SELECT li.*, it.material_code, it.name AS item_name, u.code AS uom_code
  FROM indent_items li
  JOIN items it ON it.id = li.item_id
  LEFT JOIN uom u ON u.id = li.uom_id
  WHERE li.indent_id = ?
  ORDER BY li.sort_order, li.id
");
$d->execute([$id]);
$lines = $d->fetchAll(PDO::FETCH_ASSOC);

/* status badge */
$status = $indent['status'] ?? 'draft';
$badge = [
  'draft'     => 'secondary',
  'raised'    => 'warning',
  'approved'  => 'success',
  'closed'    => 'dark',
  'cancelled' => 'danger'
][$status] ?? 'secondary';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Indent <?= htmlspecialchars($indent['indent_no']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    @media print { .no-print { display: none !important; } .table th, .table td { border-color: #000 !important; } }
  </style>
</head>
<body class="bg-white">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1">Purchase Indent</h3>
      <div class="text-muted">Indent No: <strong><?= htmlspecialchars($indent['indent_no']) ?></strong></div>
      <div class="text-muted">Status: <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($status) ?></span></div>
    </div>
    <div class="no-print">
      <a href="javascript:window.print()" class="btn btn-outline-secondary">Print</a>
      <a href="indents_list.php" class="btn btn-secondary">Back to List</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="border rounded p-3">
        <div><strong>Project</strong></div>
        <div>
          <?php if (!empty($indent['project_code'])): ?>
            <?= htmlspecialchars($indent['project_code'].' — '.$indent['project_name']) ?>
          <?php else: ?>
            <span class="text-muted">General (no project)</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="border rounded p-3">
        <div><strong>Delivery To</strong></div>
        <div><?= $indent['delivery_location_name'] ? htmlspecialchars($indent['delivery_location_name']) : '—' ?></div>
      </div>
    </div>
    <div class="col-12">
      <div class="border rounded p-3">
        <div><strong>Notes</strong></div>
        <div><?= $indent['remarks'] ? nl2br(htmlspecialchars($indent['remarks'])) : '—' ?></div>
      </div>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead>
        <tr>
          <th style="width:18%;">Item Code</th>
          <th>Item Name / Description</th>
          <th style="width:10%;">Qty</th>
          <th style="width:10%;">UOM</th>
          <th style="width:14%;">Needed By</th>
          <th style="width:18%;">Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$lines): ?>
          <tr><td colspan="6" class="text-muted">No lines.</td></tr>
        <?php else: foreach ($lines as $ln): ?>
          <tr>
            <td><?= htmlspecialchars($ln['material_code']) ?></td>
            <td>
              <div><strong><?= htmlspecialchars($ln['item_name']) ?></strong></div>
              <?php if (!empty($ln['description'])): ?>
                <div class="text-muted small"><?= htmlspecialchars($ln['description']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)$ln['qty']) ?></td>
            <td><?= htmlspecialchars($ln['uom_code'] ?? '') ?></td>
            <td><?= htmlspecialchars((string)$ln['needed_by']) ?></td>
            <td><?= htmlspecialchars($ln['remarks'] ?? '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-3">
    <small class="text-muted">Printed on <?= date('Y-m-d H:i') ?></small>
  </div>
</div>
</body>
</html>