<?php
/** PATH: /public_html/sales_quotes/sales_quotes_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_permission('sales.quote.view');

$pdo = db();

// GET filters
$status   = trim((string)($_GET['status'] ?? ''));
$client   = trim((string)($_GET['client'] ?? ''));
$q        = trim((string)($_GET['q'] ?? ''));
$from     = trim((string)($_GET['from'] ?? ''));
$to       = trim((string)($_GET['to'] ?? ''));
$minAmt   = (float)($_GET['min'] ?? 0);
$maxAmt   = (float)($_GET['max'] ?? 0);

$where = ["q.deleted_at IS NULL"];
$params = [];

if ($status !== '') { $where[] = "q.status = :status"; $params[':status'] = $status; }
if ($client !== '') { 
    // allow numeric id or name search
    if (ctype_digit($client)) { $where[] = "q.party_id = :party_id"; $params[':party_id'] = (int)$client; }
    else { $where[] = "p.name LIKE :pname"; $params[':pname'] = "%{$client}%"; }
}
if ($from !== '')   { $where[] = "q.quote_date >= :from"; $params[':from'] = $from; }
if ($to !== '')     { $where[] = "q.quote_date <= :to";   $params[':to']   = $to; }
if ($minAmt > 0)    { $where[] = "q.grand_total >= :minamt"; $params[':minamt'] = $minAmt; }
if ($maxAmt > 0)    { $where[] = "q.grand_total <= :maxamt"; $params[':maxamt'] = $maxAmt; }
if ($q !== '') {
    $where[] = "(q.code LIKE :kw OR q.title LIKE :kw OR p.name LIKE :kw)";
    $params[':kw'] = "%{$q}%";
}

$sql = "
SELECT q.id, q.code, q.title, q.quote_date, q.status, q.grand_total, p.name AS party_name
FROM sales_quotes q
LEFT JOIN parties p ON p.id = q.party_id
WHERE " . implode(' AND ', $where) . "
ORDER BY q.quote_date DESC, q.id DESC
LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function status_badge(string $s): string {
    $map = [
        'Draft'     => 'secondary',
        'Pending'   => 'info',
        'Sent'      => 'primary',
        'Accepted'  => 'success',
        'Lost'      => 'danger',
        'Converted' => 'warning',
    ];
    $cls = $map[$s] ?? 'light';
    return '<span class="badge bg-' . $cls . '">' . h($s) . '</span>';
}

$canCreate = rbac_can('sales.quote.create');
$canEdit   = rbac_can('sales.quote.edit');
$canDelete = rbac_can('sales.quote.delete');

$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = 'Sales Quotes';
$ACTIVE_MENU = 'sales.quotes';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Sales Quotes</h3>
  <?php if ($canCreate): ?>
    <a class="btn btn-sm btn-primary" href="/sales_quotes/sales_quotes_edit.php">New Quote</a>
  <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto">
    <input name="q" class="form-control" placeholder="Search code/title/client" value="<?=h($q)?>">
  </div>
  <div class="col-auto">
    <select name="status" class="form-select">
      <option value="">All Status</option>
      <?php foreach (['Draft','Pending','Sent','Accepted','Lost','Converted'] as $s): ?>
        <option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=$s?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto"><input name="client" class="form-control" placeholder="Client name or ID" value="<?=h($client)?>"></div>
  <div class="col-auto"><input type="date" name="from" class="form-control" value="<?=h($from)?>"></div>
  <div class="col-auto"><input type="date" name="to" class="form-control" value="<?=h($to)?>"></div>
  <div class="col-auto"><input type="number" name="min" step="0.01" class="form-control" placeholder="Min ₹" value="<?=h((string)$minAmt)?>"></div>
  <div class="col-auto"><input type="number" name="max" step="0.01" class="form-control" placeholder="Max ₹" value="<?=h((string)$maxAmt)?>"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
</form>

<table class="table table-sm table-striped align-middle">
  <thead>
    <tr>
      <th>Code</th><th>Date</th><th>Client</th><th>Title</th><th class="text-end">Amount</th><th>Status</th><th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="/sales_quotes/sales_quotes_view.php?id=<?=$r['id']?>"><?=h($r['code'])?></a></td>
      <td><?=h($r['quote_date'])?></td>
      <td><?=h($r['party_name'] ?? '')?></td>
      <td><?=h($r['title'] ?? '')?></td>
      <td class="text-end"><?=number_format((float)$r['grand_total'], 2)?></td>
      <td><?=status_badge($r['status'] ?? 'Draft')?></td>
      <td>
        <?php if ($canEdit): ?>
          <a class="btn btn-sm btn-outline-primary" href="/sales_quotes/sales_quotes_edit.php?id=<?=$r['id']?>">Edit</a>
        <?php endif; ?>
        <?php if ($canDelete): ?>
          <form action="/sales_quotes/sales_quotes_delete.php" method="post" class="d-inline" onsubmit="return confirm('Soft-delete this quote?');">
            <?=csrf_hidden_input()?>
            <input type="hidden" name="id" value="<?=$r['id']?>">
            <button class="btn btn-sm btn-outline-danger">Delete</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php require_once $UI_PATH . '/layout_end.php';
