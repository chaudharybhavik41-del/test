<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
require_permission('purchase.po.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$rows = $pdo->query("SELECT po.id, po.po_no, po.po_date, po.status, po.total_after_tax, p.name AS supplier_name
                     FROM purchase_orders po
                     LEFT JOIN parties p ON p.id=po.supplier_id
                     ORDER BY po.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__.'/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Purchase Orders</h1>
  </div>
  <div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
      <thead><tr>
        <th>PO No</th><th>Date</th><th>Supplier</th><th class="text-end">Amount</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?=htmlspecialchars((string)$r['po_no'])?></td>
            <td><?=htmlspecialchars((string)$r['po_date'])?></td>
            <td><?=htmlspecialchars((string)($r['supplier_name']??''))?></td>
            <td class="text-end"><?= number_format((float)($r['total_after_tax']??0), 2) ?></td>
            <td><span class="badge bg-<?= $r['status']==='issued'?'success':'secondary' ?>"><?=htmlspecialchars((string)$r['status'])?></span></td>
            <td><a class="btn btn-sm btn-outline-primary" href="/purchase/po_form.php?id=<?=$r['id']?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted">No purchase orders.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__.'/../ui/layout_end.php';
