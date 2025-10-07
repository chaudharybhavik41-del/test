<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login(); require_permission('stores.settlement.view');
$pdo = db();
$st  = $pdo->query("SELECT sh.*, c.name AS customer_name FROM settlement_headers sh LEFT JOIN customers c ON c.id = sh.customer_id ORDER BY sh.id DESC LIMIT 200");
$rows= $st->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"/><title>Settlements</title>
<style>table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:6px}</style></head><body>
<h2>Material Settlements (Party → Company)</h2>
<table><thead><tr><th>ID</th><th>Customer</th><th>Mode</th><th>Kind</th><th>Bucket</th><th>Status</th><th>Qty</th><th>Amount</th><th>Created</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr>
<td><?= (int)$r['id'] ?></td>
<td><?= htmlspecialchars($r['customer_name'] ?? ('ID '.$r['customer_id'])) ?></td>
<td><?= htmlspecialchars($r['mode']) ?></td>
<td><?= htmlspecialchars($r['kind']) ?></td>
<td><?= htmlspecialchars($r['bucket']) ?></td>
<td><?= htmlspecialchars($r['status']) ?></td>
<td style="text-align:right"><?= number_format((float)$r['total_qty_base'],3) ?></td>
<td style="text-align:right"><?= number_format((float)$r['total_amount'],2) ?></td>
<td><?= htmlspecialchars($r['created_at']) ?></td>
</tr><?php endforeach; ?></tbody></table>
</body></html>