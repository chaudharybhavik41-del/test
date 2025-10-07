<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_permission('stocks.adjust.manage');
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Not found'); }

/* Header */
$hdr = $pdo->prepare("
  SELECT sa.*, COALESCE(w.name, CONCAT('WH-',sa.warehouse_id)) AS warehouse_name
  FROM stock_adjustments sa
  LEFT JOIN warehouses w ON w.id = sa.warehouse_id
  WHERE sa.id = :id
");
$hdr->execute([':id'=>$id]);
$h = $hdr->fetch(PDO::FETCH_ASSOC);
if (!$h) { http_response_code(404); exit('Not found'); }

/* Lines with item & uom labels */
$lines = $pdo->prepare("
  SELECT l.*, 
         i.material_code, i.name AS item_name,
         u.code AS uom_code, u.name AS uom_name
  FROM stock_adjustment_items l
  LEFT JOIN items i ON i.id = l.item_id
  LEFT JOIN uom   u ON u.id = l.uom_id
  WHERE l.adj_id = :id
  ORDER BY l.id
");
$lines->execute([':id'=>$id]);
$ls = $lines->fetchAll(PDO::FETCH_ASSOC);

/* Movements tied to this adjustment (stock_moves uses ref_entity/ref_id) */
$moves = $pdo->prepare("
  SELECT id, txn_date, txn_type, txn_no, item_id, warehouse_id, qty, uom_id, unit_cost
  FROM stock_moves
  WHERE ref_entity = 'stock_adjustments' AND ref_id = :id
  ORDER BY id
");
$moves->execute([':id'=>$id]);
$mv = $moves->fetchAll(PDO::FETCH_ASSOC);

/* Ledger mirror (has ref_table/ref_id) */
$ledger = $pdo->prepare("
  SELECT id, txn_date, txn_type, txn_no, item_id, warehouse_id, qty, rate
  FROM stock_ledger
  WHERE ref_table = 'stock_adjustments' AND ref_id = :id
  ORDER BY id
");
$ledger->execute([':id'=>$id]);
$lg = $ledger->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Adjustment <?=htmlspecialchars($h['adj_no'])?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px}
    .card{border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px}
    table{width:100%;border-collapse:collapse;font-size:14px}
    th,td{border-bottom:1px solid #eee;padding:8px;text-align:left}
    th{background:#fafafa}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
    .muted{opacity:.75}
  </style>
</head>
<body>

<div class="card">
  <h2>Stock Adjustment — <?=htmlspecialchars($h['adj_no'])?></h2>
  <div class="grid">
    <div><b>Date:</b> <?=htmlspecialchars($h['adj_date'])?></div>
    <div><b>Mode:</b> <?=htmlspecialchars($h['mode'])?></div>
    <div><b>Warehouse:</b> <?=htmlspecialchars($h['warehouse_name'])?></div>
    <div><b>Status:</b> <?=htmlspecialchars(strtoupper($h['status']))?></div>
    <?php if (!empty($h['reason_id'])): ?>
      <div><b>Reason ID:</b> <?= (int)$h['reason_id'] ?></div>
    <?php endif; ?>
    <?php if (!empty($h['remarks'])): ?>
      <div class="muted"><b>Remarks:</b> <?=htmlspecialchars($h['remarks'])?></div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Lines</h3>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Item</th>
        <th>UoM</th>
        <th>Qty</th>
        <th>Remarks</th>
      </tr>
    </thead>
    <tbody>
      <?php $i=0; foreach($ls as $r): $i++; ?>
        <tr>
          <td><?=$i?></td>
          <td><?=htmlspecialchars(($r['material_code'] ?? 'ITEM-'.$r['item_id']).' — '.($r['item_name'] ?? ''))?></td>
          <td><?=htmlspecialchars(($r['uom_code'] ?? 'ID '.$r['uom_id']).' — '.($r['uom_name'] ?? ''))?></td>
          <td><?=number_format((float)$r['qty'],3)?></td>
          <td><?=htmlspecialchars($r['remarks'] ?? '')?></td>
        </tr>
      <?php endforeach; if (!$ls): ?>
        <tr><td colspan="5" class="muted">No lines found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Movements</h3>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Date</th>
        <th>Type</th>
        <th>Item</th>
        <th>Warehouse</th>
        <th>Qty</th>
        <th>UoM</th>
        <th>Rate</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($mv as $j=>$m): ?>
        <tr>
          <td><?=($j+1)?></td>
          <td><?=htmlspecialchars($m['txn_date'])?></td>
          <td><?=htmlspecialchars($m['txn_type'])?></td>
          <td><?= (int)$m['item_id'] ?></td>
          <td><?= (int)$m['warehouse_id'] ?></td>
          <td><?= number_format((float)$m['qty'],3) ?></td>
          <td><?= (int)$m['uom_id'] ?></td>
          <td><?= number_format((float)$m['unit_cost'],4) ?></td>
        </tr>
      <?php endforeach; if (!$mv): ?>
        <tr><td colspan="8" class="muted">No movements found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Ledger</h3>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Date</th>
        <th>Type</th>
        <th>Item</th>
        <th>Warehouse</th>
        <th>Qty</th>
        <th>Rate</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($lg as $k=>$g): ?>
        <tr>
          <td><?=($k+1)?></td>
          <td><?=htmlspecialchars($g['txn_date'])?></td>
          <td><?=htmlspecialchars($g['txn_type'])?></td>
          <td><?= (int)$g['item_id'] ?></td>
          <td><?= (int)$g['warehouse_id'] ?></td>
          <td><?= number_format((float)$g['qty'],3) ?></td>
          <td><?= number_format((float)$g['rate'],4) ?></td>
        </tr>
      <?php endforeach; if (!$lg): ?>
        <tr><td colspan="7" class="muted">No ledger rows found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>
