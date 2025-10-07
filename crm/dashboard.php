<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login(); require_permission('crm.dashboard.view');
$pdo = db();

$from  = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
$to    = (string)($_GET['to']   ?? date('Y-m-d'));
$owner = (int)($_GET['owner']  ?? 0);
$params = [':from'=>$from, ':to'=>$to.' 23:59:59'];
if ($owner>0) $params[':owner']=$owner;

/** Leads by status (your crm_leads has "status") */
$leadsBy = $pdo->prepare("
  SELECT status AS bucket, COUNT(*) cnt
  FROM crm_leads
  WHERE created_at BETWEEN :from AND :to
  ".($owner>0?" AND owner_id=:owner ":"")."
  GROUP BY status
");
$leadsBy->execute($params);
$ls=[]; $leadsTotal=0;
foreach($leadsBy as $r){ $ls[$r['bucket']??'']=(int)$r['cnt']; $leadsTotal+=(int)$r['cnt']; }

/** Activities: overdue/today/upcoming */
$st=$pdo->prepare("
  SELECT
    SUM(CASE WHEN due_at IS NOT NULL AND due_at < NOW() THEN 1 ELSE 0 END) overdue,
    SUM(CASE WHEN due_at IS NOT NULL AND DATE(due_at)=CURRENT_DATE THEN 1 ELSE 0 END) today,
    SUM(CASE WHEN due_at IS NOT NULL AND due_at > NOW() THEN 1 ELSE 0 END) upcoming
  FROM crm_activities
  WHERE created_at BETWEEN :from AND :to
  ".($owner>0?" AND owner_id=:owner ":"")
);
$st->execute($params);
$acts=$st->fetch(PDO::FETCH_ASSOC) ?: ['overdue'=>0,'today'=>0,'upcoming'=>0];

/** Quotes by status + totals (value) */
$st=$pdo->prepare("
  SELECT status, COUNT(*) cnt, SUM(grand_total) amt
  FROM sales_quotes
  WHERE deleted_at IS NULL AND quote_date BETWEEN :from AND :to
  ".($owner>0?" AND created_by=:owner ":"")."
  GROUP BY status
");
$st->execute($params);
$quotes=[]; $quotesAmtTotal=0; $quotesCntTotal=0;
foreach($st as $r){
  $k=(string)($r['status']??'');
  $quotes[$k]=['cnt'=>(int)$r['cnt'],'amt'=>(float)($r['amt']??0)];
  $quotesCntTotal+=(int)$r['cnt']; $quotesAmtTotal+=(float)($r['amt']??0);
}

/** Orders summary (your sales_orders has amount, not subtotal/grand_total) */
$st=$pdo->prepare("
  SELECT COUNT(*) so_cnt, COALESCE(SUM(amount),0) so_amt
  FROM sales_orders
  WHERE order_date BETWEEN :from AND :to
  ".($owner>0?" AND created_by=:owner ":"")
);
$st->execute($params);
$so=$st->fetch(PDO::FETCH_ASSOC) ?: ['so_cnt'=>0,'so_amt'=>0];

$funnel=['leads'=>$leadsTotal,'quotes'=>$quotesCntTotal,'orders'=>(int)$so['so_cnt']];
$conv1=$funnel['leads']>0?round($funnel['quotes']*100/$funnel['leads'],1):0;
$conv2=$funnel['quotes']>0?round($funnel['orders']*100/$funnel['quotes'],1):0;

/** UI helpers */
function money($n){ return number_format((float)$n,2); }
function with_range(string $base, string $from, string $to, array $extra=[]): string {
  $q = array_merge(['from'=>$from,'to'=>$to], $extra);
  return $base.'?'.http_build_query($q);
}

$UI_PATH=dirname(__DIR__).'/ui'; $PAGE_TITLE='CRM Dashboard'; $ACTIVE_MENU='crm.dashboard';
require_once $UI_PATH.'/init.php'; require_once $UI_PATH.'/layout_start.php';
?>
<style>
a.card-link { text-decoration:none; color:inherit; display:block }
a.card-link .card { transition: transform .05s ease-in }
a.card-link:hover .card { transform: translateY(-2px) }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">CRM Dashboard</h3>
  <form class="d-flex gap-2" method="get">
    <input type="date" name="from" class="form-control" value="<?=h($from)?>">
    <input type="date" name="to" class="form-control" value="<?=h($to)?>">
    <input type="number" name="owner" class="form-control" placeholder="Owner ID" value="<?=$owner?:''?>">
    <button class="btn btn-outline-secondary">Apply</button>
    <a class="btn btn-outline-dark" href="/crm/dashboard.php">Reset</a>
  </form>
</div>

<!-- Quick Actions -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm btn-primary" href="/crm_leads/crm_leads_form.php">+ New Lead</a>
  <a class="btn btn-sm btn-primary" href="/crm/activities_edit.php">+ New Activity</a>
  <a class="btn btn-sm btn-primary" href="/sales_quotes/sales_quotes_form.php">+ New Quote</a>
</div>

<!-- Go To (Navigation) -->
<div class="d-flex flex-wrap gap-2 mb-4">
  <a class="btn btn-outline-secondary" href="<?=h(with_range('/crm_leads/crm_leads_list.php',$from,$to))?>">Go to Leads</a>
  <a class="btn btn-outline-secondary" href="<?=h(with_range('/crm/activities_list.php',$from,$to))?>">Go to Activities</a>
  <a class="btn btn-outline-secondary" href="<?=h(with_range('/sales_quotes/sales_quotes_list.php',$from,$to))?>">Go to Quotes</a>
  <!-- If you have a Sales Orders list page, keep this; otherwise remove -->
  <a class="btn btn-outline-secondary" href="<?=h(with_range('/sales_orders/sales_orders_list.php',$from,$to))?>">Go to Sales Orders</a>
</div>

<div class="row g-3">
  <!-- Clickable KPI cards -->
  <div class="col-md-3">
    <a class="card-link" href="<?=h(with_range('/crm_leads/crm_leads_list.php',$from,$to))?>">
      <div class="card"><div class="card-body">
        <div class="text-muted">New Leads</div>
        <div class="h3 mb-0"><?=$leadsTotal?></div>
      </div></div>
    </a>
  </div>

  <div class="col-md-3">
    <a class="card-link" href="<?=h(with_range('/sales_quotes/sales_quotes_list.php',$from,$to))?>">
      <div class="card"><div class="card-body">
        <div class="text-muted">Quotes (₹)</div>
        <div class="h3 mb-0">₹<?=money($quotesAmtTotal)?></div>
        <div class="small text-muted"><?=$quotesCntTotal?> quotes</div>
      </div></div>
    </a>
  </div>

  <div class="col-md-3">
    <!-- If no Orders list page, send them to Quotes filtered to Converted -->
    <a class="card-link" href="<?=h(with_range('/sales_quotes/sales_quotes_list.php',$from,$to, ['status'=>'Converted']))?>">
      <div class="card"><div class="card-body">
        <div class="text-muted">Orders (₹)</div>
        <div class="h3 mb-0">₹<?=money($so['so_amt'])?></div>
        <div class="small text-muted"><?=$so['so_cnt']?> orders</div>
      </div></div>
    </a>
  </div>

  <div class="col-md-3">
    <a class="card-link" href="<?=h(with_range('/crm/activities_list.php',$from,$to, ['due'=>'today']))?>">
      <div class="card"><div class="card-body">
        <div class="text-muted">Follow-ups</div>
        <div class="h3 mb-0"><?=$acts['overdue']?> / <?=$acts['today']?> / <?=$acts['upcoming']?></div>
        <div class="small text-muted">Overdue / Today / Upcoming</div>
      </div></div>
    </a>
  </div>
</div>

<hr>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header">Leads by Status</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Open</th></tr></thead>
          <tbody>
          <?php foreach (['New','In Progress','Won','Lost'] as $s): $cnt=(int)($ls[$s] ?? 0); ?>
            <tr>
              <td><a href="<?=h(with_range('/crm_leads/crm_leads_list.php',$from,$to, ['status'=>$s]))?>"><?=h($s)?></a></td>
              <td class="text-end"><?=$cnt?></td>
              <td class="text-end">
                <?php if($cnt>0): ?>
                  <a class="small" href="<?=h(with_range('/crm_leads/crm_leads_list.php',$from,$to, ['status'=>$s]))?>">view</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card">
      <div class="card-header">Quotes by Status (₹)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Status</th><th class="text-end">Count</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php 
            $statuses = array_unique(array_merge(
              ['Draft','Pending','Approved','Sent','Accepted','Lost','Converted','Expired','Canceled'],
              array_keys($quotes)
            ));
            foreach ($statuses as $stq):
              $cnt = (int)($quotes[$stq]['cnt'] ?? 0);
              $amt = (float)($quotes[$stq]['amt'] ?? 0);
          ?>
            <tr>
              <td><a href="<?=h(with_range('/sales_quotes/sales_quotes_list.php',$from,$to, ['status'=>$stq]))?>"><?=h($stq)?></a></td>
              <td class="text-end"><?=$cnt?></td>
              <td class="text-end">₹<?=money($amt)?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="table-light"><th>Total</th><th class="text-end"><?=$quotesCntTotal?></th><th class="text-end">₹<?=money($quotesAmtTotal)?></th></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<hr>

<div class="card">
  <div class="card-header">Funnel</div>
  <div class="card-body d-flex align-items-center justify-content-around">
    <a class="card-link" href="<?=h(with_range('/crm_leads/crm_leads_list.php',$from,$to))?>">
      <div class="text-center"><div class="h4 mb-0"><?=$funnel['leads']?></div><div class="text-muted">Leads</div></div>
    </a>
    <div class="display-6">→</div>
    <a class="card-link" href="<?=h(with_range('/sales_quotes/sales_quotes_list.php',$from,$to))?>">
      <div class="text-center"><div class="h4 mb-0"><?=$funnel['quotes']?></div><div class="text-muted">Quotes</div></div>
    </a>
    <div class="display-6">→</div>
    <!-- Order link goes to Converted quotes if you don’t have an orders list page -->
    <a class="card-link" href="<?=h(with_range('/sales_quotes/sales_quotes_list.php',$from,$to, ['status'=>'Converted']))?>">
      <div class="text-center"><div class="h4 mb-0"><?=$funnel['orders']?></div><div class="text-muted">Orders</div></div>
    </a>
  </div>
</div>

<?php require_once $UI_PATH.'/layout_end.php';