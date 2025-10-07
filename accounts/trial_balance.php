<?php
declare(strict_types=1);
/** PATH: /public_html/accounts/trial_balance.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('accounts.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

function h(?string $v){ return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8'); }

$asof = $_GET['asof'] ?? date('Y-m-d');

// fetch balances by account as of date
$sql = "
SELECT ac.id, ac.code, ac.name, ac.type,
       COALESCE(SUM(CASE WHEN j.voucher_date <= :asof THEN jl.debit  ELSE 0 END),0) AS dr,
       COALESCE(SUM(CASE WHEN j.voucher_date <= :asof THEN jl.credit ELSE 0 END),0) AS cr
FROM accounts_chart ac
LEFT JOIN journal_lines jl ON jl.account_id = ac.id
LEFT JOIN journals j ON j.id = jl.journal_id
GROUP BY ac.id, ac.code, ac.name, ac.type
ORDER BY ac.code
";
$st = $pdo->prepare($sql);
$st->execute([':asof'=>$asof]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// compute balances DR/CR side
$totalDR = 0.00; $totalCR = 0.00;
foreach ($rows as &$r) {
  $bal = (float)$r['dr'] - (float)$r['cr'];
  if ($bal >= 0) { $r['dr_bal'] = $bal; $r['cr_bal'] = 0.00; $totalDR += $bal; }
  else           { $r['dr_bal'] = 0.00; $r['cr_bal'] = -$bal; $totalCR += -$bal; }
}
unset($r);
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Trial Balance</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-body-tertiary">
<div class="container py-4">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Trial Balance</h1>
    <form class="ms-auto d-flex gap-2" method="get">
      <input type="date" class="form-control" name="asof" value="<?=h($asof)?>">
      <button class="btn btn-primary">Update</button>
    </form>
  </div>

  <div class="table-responsive bg-white rounded shadow-sm">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:120px">Code</th>
          <th>Account</th>
          <th style="width:120px">Type</th>
          <th class="text-end" style="width:140px">Debit</th>
          <th class="text-end" style="width:140px">Credit</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($rows as $r): if($r['dr']==0 && $r['cr']==0) continue; ?>
        <tr>
          <td><?=h($r['code'])?></td>
          <td><?=h($r['name'])?></td>
          <td><?=h($r['type'])?></td>
          <td class="text-end"><?=number_format((float)$r['dr_bal'],2)?></td>
          <td class="text-end"><?=number_format((float)$r['cr_bal'],2)?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light">
        <tr>
          <th colspan="3" class="text-end">Totals</th>
          <th class="text-end"><?=number_format($totalDR,2)?></th>
          <th class="text-end"><?=number_format($totalCR,2)?></th>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
</body>
</html>
