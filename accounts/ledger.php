<?php
declare(strict_types=1);
/** PATH: /public_html/accounts/ledger.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('accounts.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

function h(?string $v){ return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8'); }

$acc = (int)($_GET['account_id'] ?? 0);
$df  = $_GET['df'] ?? date('Y-m-01');
$dt  = $_GET['dt'] ?? date('Y-m-d');

// accounts dropdown
$accs = $pdo->query("SELECT id, code, name FROM accounts_chart WHERE is_posting=1 AND active=1 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

// opening balance (till day before df)
$open = 0.00;
if ($acc>0) {
  $o = $pdo->prepare("
    SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS bal
    FROM journal_lines jl
    JOIN journals j ON j.id=jl.journal_id
    WHERE jl.account_id=? AND j.voucher_date < ?
  ");
  $o->execute([$acc, $df]);
  $open = (float)$o->fetchColumn();
}

// ledger rows in range
$rows=[];
if ($acc>0) {
  $q = $pdo->prepare("
    SELECT j.voucher_date, j.voucher_no, j.voucher_type, j.ref_doc_type, j.ref_doc_id,
           jl.debit, jl.credit, jl.line_memo
    FROM journal_lines jl
    JOIN journals j ON j.id=jl.journal_id
    WHERE jl.account_id=? AND j.voucher_date BETWEEN ? AND ?
    ORDER BY j.voucher_date, j.id, jl.line_no
  ");
  $q->execute([$acc, $df, $dt]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ledger</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-body-tertiary">
<div class="container py-4">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Ledger</h1>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-5">
      <label class="form-label">Account</label>
      <select class="form-select" name="account_id" required>
        <option value="">Select account...</option>
        <?php foreach($accs as $a): ?>
          <option value="<?=$a['id']?>" <?=$acc===(int)$a['id']?'selected':''?>>
            <?=h($a['code'].' - '.$a['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">From</label>
      <input type="date" class="form-control" name="df" value="<?=h($df)?>" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">To</label>
      <input type="date" class="form-control" name="dt" value="<?=h($dt)?>" required>
    </div>
    <div class="col-md-3">
      <button class="btn btn-primary w-100">Show</button>
    </div>
  </form>

  <?php if($acc>0): ?>
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between">
      <span>Opening Balance as of <?=h(date('d-M-Y', strtotime("$df -1 day")))?>:</span>
      <strong><?=number_format($open,2)?></strong>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:120px">Date</th>
            <th style="width:160px">Voucher</th>
            <th style="width:90px">Type</th>
            <th>Memo</th>
            <th class="text-end" style="width:120px">Debit</th>
            <th class="text-end" style="width:120px">Credit</th>
            <th class="text-end" style="width:140px">Running</th>
          </tr>
        </thead>
        <tbody>
        <?php $run=$open;
          if(!$rows): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No entries</td></tr>
        <?php else:
          foreach($rows as $r):
            $run += (float)$r['debit'] - (float)$r['credit']; ?>
          <tr>
            <td><?=h($r['voucher_date'])?></td>
            <td><?=h($r['voucher_no'])?></td>
            <td><?=h($r['voucher_type'])?></td>
            <td><?=h($r['line_memo'] ?? ($r['ref_doc_type'].' #'.$r['ref_doc_id']))?></td>
            <td class="text-end"><?=number_format((float)$r['debit'],2)?></td>
            <td class="text-end"><?=number_format((float)$r['credit'],2)?></td>
            <td class="text-end fw-semibold"><?=number_format($run,2)?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
