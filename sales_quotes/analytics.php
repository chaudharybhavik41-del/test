<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login(); require_permission('sales.quote.view');

$pdo=db();

// Win/Loss by month
$wl = $pdo->query("
SELECT DATE_FORMAT(quote_date,'%Y-%m') AS ym,
SUM(CASE WHEN status IN ('Accepted','Converted') THEN 1 ELSE 0 END) win,
SUM(CASE WHEN status='Lost' THEN 1 ELSE 0 END) loss,
COUNT(*) total
FROM sales_quotes WHERE deleted_at IS NULL
GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);

// Cycle time (lead->quote->order)
$ct = $pdo->query("
SELECT DATE_FORMAT(q.quote_date,'%Y-%m') ym,
AVG(TIMESTAMPDIFF(DAY, l.created_at, q.quote_date)) AS lead_to_quote_days
FROM sales_quotes q
LEFT JOIN crm_leads l ON l.id=q.lead_id
GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);

$UI_PATH= dirname(__DIR__).'/ui';
$PAGE_TITLE='Sales Analytics';
$ACTIVE_MENU='sales.analytics';
require_once $UI_PATH.'/init.php';
require_once $UI_PATH.'/layout_start.php';
?>
<h3>Sales Analytics</h3>
<h5>Win/Loss (last 12 months)</h5>
<table class="table table-sm table-striped">
  <thead><tr><th>Month</th><th class="text-end">Wins</th><th class="text-end">Losses</th><th class="text-end">Total</th></tr></thead>
  <tbody>
  <?php foreach($wl as $r): ?>
    <tr><td><?=$r['ym']?></td><td class="text-end"><?=$r['win']?></td><td class="text-end"><?=$r['loss']?></td><td class="text-end"><?=$r['total']?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h5>Avg Lead → Quote days</h5>
<table class="table table-sm table-striped">
  <thead><tr><th>Month</th><th class="text-end">Days</th></tr></thead>
  <tbody>
  <?php foreach($ct as $r): ?>
    <tr><td><?=$r['ym']?></td><td class="text-end"><?=number_format((float)$r['lead_to_quote_days'],1)?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require_once $UI_PATH.'/layout_end.php';
