<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('kpi.view');
$pdo=db();
$grn=$pdo->query("SELECT * FROM v_kpi_grn_30d")->fetchAll(PDO::FETCH_ASSOC);
$ap =$pdo->query("SELECT * FROM v_kpi_ap_30d")->fetchAll(PDO::FETCH_ASSOC);
$oh =$pdo->query("SELECT SUM(amount) AS onhand_value FROM v_kpi_onhand_value")->fetch(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>KPI Dashboard</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}</style></head><body>
<h1>KPI Dashboard</h1>
<h3>Last 30d GRN Value (daily)</h3>
<table><thead><tr><th>Date</th><th>Value</th></tr></thead><tbody>
<?php foreach($grn as $r): ?><tr><td><?= htmlspecialchars($r['d']) ?></td><td><?= number_format((float)$r['grn_value'],2) ?></td></tr><?php endforeach; ?>
</tbody></table>
<h3>Last 30d AP Invoice Value (daily)</h3>
<table><thead><tr><th>Date</th><th>Value</th></tr></thead><tbody>
<?php foreach($ap as $r): ?><tr><td><?= htmlspecialchars($r['d']) ?></td><td><?= number_format((float)$r['ap_value'],2) ?></td></tr><?php endforeach; ?>
</tbody></table>
<h3>Current On-hand Inventory Value</h3>
<p><b><?= number_format((float)($oh['onhand_value'] ?? 0),2) ?></b></p>
</body></html>
