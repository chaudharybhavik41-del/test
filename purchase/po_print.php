<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';

require_login();

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ http_response_code(400); echo "Missing id"; exit; }

$po=$pdo->prepare("
  SELECT po.*, p.name AS supplier_name, pr.code AS project_code, pr.name AS project_name
  FROM purchase_orders po
  LEFT JOIN parties  p  ON p.id=po.supplier_id
  LEFT JOIN projects pr ON pr.id=po.project_id
  WHERE po.id=?");
$po->execute([$id]);
$po=$po->fetch(PDO::FETCH_ASSOC);
if(!$po){ http_response_code(404); echo "PO not found"; exit; }

$lines=$pdo->prepare("SELECT li.*, it.material_code, it.name AS item_name, u.code AS uom_code
                      FROM purchase_order_items li
                      LEFT JOIN items it ON it.id=li.item_id
                      LEFT JOIN uom   u  ON u.id=li.uom_id
                      WHERE li.po_id=? ORDER BY li.id");
$lines->execute([$id]);
$lines=$lines->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>PO <?=htmlspecialchars((string)$po['po_no'])?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  @media print {
    .noprint { display:none !important; }
    .card, .table { box-shadow: none !important; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body class="p-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h4 mb-1">Purchase Order</h1>
      <div class="text-muted">PO No: <?=htmlspecialchars((string)$po['po_no'])?></div>
    </div>
    <button class="btn btn-dark noprint" onclick="window.print()">Print</button>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-6">
      <div class="border rounded p-3">
        <div><strong>Supplier</strong></div>
        <div><?=htmlspecialchars((string)($po['supplier_name']??''))?></div>
      </div>
    </div>
    <div class="col-6">
      <div class="border rounded p-3">
        <div><strong>Project</strong></div>
        <div><?=htmlspecialchars((string)($po['project_code'] ?? ''))?>
          <?php if (!empty($po['project_name'])): ?>
            — <span class="text-muted"><?=htmlspecialchars((string)$po['project_name'])?></span>
          <?php endif; ?>
        </div>
        <div class="mt-2"><strong>Date:</strong> <?=htmlspecialchars((string)$po['po_date'])?></div>
        <div><strong>Currency:</strong> <?=htmlspecialchars((string)$po['currency'])?></div>
      </div>
    </div>
  </div>

  <table class="table table-bordered table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th style="width:40%">Item</th>
        <th class="text-end" style="width:10%">Qty</th>
        <th style="width:10%">UOM</th>
        <th class="text-end" style="width:10%">Unit</th>
        <th class="text-end" style="width:10%">Disc %</th>
        <th class="text-end" style="width:10%">Tax %</th>
        <th class="text-end" style="width:10%">Line Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($lines as $ln): ?>
        <tr>
          <td><?=htmlspecialchars((string)(($ln['material_code'] ?? '').' — '.($ln['item_name'] ?? '')))?></td>
          <td class="text-end"><?= number_format((float)($ln['qty'] ?? 0), 3) ?></td>
          <td><?=htmlspecialchars((string)($ln['uom_code'] ?? ''))?></td>
          <td class="text-end"><?= number_format((float)($ln['unit_price'] ?? 0), 2) ?></td>
          <td class="text-end"><?= number_format((float)($ln['discount_percent'] ?? 0), 2) ?></td>
          <td class="text-end"><?= number_format((float)($ln['tax_percent'] ?? 0), 2) ?></td>
          <td class="text-end"><?= number_format((float)($ln['line_total_after_tax'] ?? 0), 2) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lines): ?>
        <tr><td colspan="7" class="text-center text-muted">No lines</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr><th colspan="6" class="text-end">Subtotal</th><th class="text-end"><?= number_format((float)($po['total_before_tax'] ?? 0), 2) ?></th></tr>
      <tr><th colspan="6" class="text-end">Tax</th><th class="text-end"><?= number_format((float)($po['total_tax'] ?? 0), 2) ?></th></tr>
      <tr><th colspan="6" class="text-end">Total</th><th class="text-end"><?= number_format((float)($po['total_after_tax'] ?? 0), 2) ?></th></tr>
    </tfoot>
  </table>

  <?php if (!empty($po['payment_terms']) || !empty($po['freight_terms']) || !empty($po['delivery_terms'])): ?>
    <div class="mt-3">
      <h2 class="h6">Commercial Terms</h2>
      <ul class="mb-0">
        <?php if (!empty($po['payment_terms'])): ?><li><strong>Payment:</strong> <?=htmlspecialchars((string)$po['payment_terms'])?></li><?php endif; ?>
        <?php if (!empty($po['freight_terms'])): ?><li><strong>Transport/Freight:</strong> <?=htmlspecialchars((string)$po['freight_terms'])?></li><?php endif; ?>
        <?php if (!empty($po['delivery_terms'])): ?><li><strong>Delivery:</strong> <?=htmlspecialchars((string)$po['delivery_terms'])?></li><?php endif; ?>
      </ul>
    </div>
  <?php endif; ?>
</body>
</html>
