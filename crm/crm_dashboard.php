<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login();
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

function fmt(?string $s): string { return htmlspecialchars((string)$s ?? '', ENT_QUOTES, 'UTF-8'); }
function safeCount(PDO $pdo, string $sql, array $params = []): int {
  try { $st = $pdo->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
  catch(Throwable $e){ return 0; }
}
function safeRows(PDO $pdo, string $sql, array $params = [], int $limit = 10): array {
  try { if ($limit>0 && stripos($sql,'limit')===false) $sql .= " LIMIT ".(int)$limit;
       $st=$pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
  } catch(Throwable $e){ return []; }
}

$today = (new DateTime('now'))->format('Y-m-d');
$in7   = (new DateTime('now'))->modify('+7 days')->format('Y-m-d');

// KPIs
$kpi = [
  'accounts'   => safeCount($pdo, "SELECT COUNT(*) FROM crm_accounts WHERE deleted_at IS NULL"),
  'contacts'   => safeCount($pdo, "SELECT COUNT(*) FROM crm_contacts WHERE deleted_at IS NULL"),
  'leads_open' => safeCount($pdo, "SELECT COUNT(*) FROM crm_leads WHERE deleted_at IS NULL AND status IN ('New','In Progress')"),
  'quotes_dr'  => safeCount($pdo, "SELECT COUNT(*) FROM sales_quotes WHERE deleted_at IS NULL AND status='Draft'"),
  'quotes_sn'  => safeCount($pdo, "SELECT COUNT(*) FROM sales_quotes WHERE deleted_at IS NULL AND status='Sent'"),
  'orders_wip' => safeCount($pdo, "SELECT COUNT(*) FROM sales_orders WHERE deleted_at IS NULL AND status IN ('Confirmed','In Progress')"),
  'acts_due'   => safeCount($pdo, "SELECT COUNT(*) FROM crm_activities WHERE deleted_at IS NULL AND status IN ('Open','In Progress') AND due_at<=?", [$in7.' 23:59:59']),
];

// Lists
$leads  = safeRows($pdo, "SELECT id, code, title, status, amount, follow_up_on FROM crm_leads WHERE deleted_at IS NULL ORDER BY id DESC", [], 10);
$quotes = safeRows($pdo, "SELECT id, code, quote_date, status, grand_total FROM sales_quotes WHERE deleted_at IS NULL ORDER BY id DESC", [], 10);
$orders = safeRows($pdo, "SELECT id, code, order_date, status, amount FROM sales_orders WHERE deleted_at IS NULL ORDER BY id DESC", [], 10);
$acts   = safeRows($pdo, "SELECT id, code, type, subject, due_at, status FROM crm_activities WHERE deleted_at IS NULL AND status IN ('Open','In Progress') ORDER BY due_at ASC", [], 12);

$UI_PATH = __DIR__ . '/../ui';
$PAGE_TITLE = 'CRM Dashboard';
$ACTIVE_MENU = 'crm_dashboard';
if (is_file($UI_PATH.'/init.php')) require_once $UI_PATH.'/init.php';
if (is_file($UI_PATH.'/layout_start.php')) require_once $UI_PATH.'/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center mb-3">
    <h4 class="mb-0">CRM Dashboard</h4>
    <a class="btn btn-outline-secondary btn-sm ms-auto" href="/dashboard.php?tab=crm">Open as main tab</a>
  </div>

  <div class="row g-3">
    <div class="col-sm-6 col-md-4 col-lg-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Accounts</div><div class="fs-4 fw-semibold"><?= (int)$kpi['accounts'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Contacts</div><div class="fs-4 fw-semibold"><?= (int)$kpi['contacts'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Leads (Open)</div><div class="fs-4 fw-semibold"><?= (int)$kpi['leads_open'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Quotes (Draft)</div><div class="fs-4 fw-semibold"><?= (int)$kpi['quotes_dr'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Quotes (Sent)</div><div class="fs-4 fw-semibold"><?= (int)$kpi['quotes_sn'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-2"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Orders (WIP)</div><div class="fs-4 fw-semibold"><?= (int)$kpi['orders_wip'] ?></div></div></div></div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Recent Leads</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($leads): foreach ($leads as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt(($r['code'] ?? ('#'.$r['id'])) . ' · ' . ($r['title'] ?? '')) ?></span>
              <span class="text-muted small"><?= fmt($r['status'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No leads</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/crm/leads_list.php">Open leads →</a></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Recent Sales Quotes</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($quotes): foreach ($quotes as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($r['code'] ?? ('#'.$r['id'])) ?></span>
              <span class="text-muted small"><?= fmt($r['status'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No quotes</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/sales_quotes/sales_quotes_list.php">Open quotes →</a></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Upcoming Activities (≤ 7 days)</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($acts): foreach ($acts as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt(($r['type'] ?? '').' · '.($r['subject'] ?? '')) ?></span>
              <span class="text-muted small"><?= fmt($r['due_at'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No activities due</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/crm/activities_list.php">Open activities →</a></div>
      </div>
    </div>
  </div>
</div>
<?php if (is_file($UI_PATH.'/layout_end.php')) require_once $UI_PATH.'/layout_end.php'; ?>
