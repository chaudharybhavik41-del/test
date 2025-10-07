<?php
// --- bootstrapping (kept minimal to avoid breaking your includes) ---
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rbac.php';
require_login();
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

// --- tiny helpers (non-invasive) ---
function fmt(?string $s): string { return htmlspecialchars((string)$s ?? '', ENT_QUOTES, 'UTF-8'); }
function safeCount(PDO $pdo, string $sql, array $params = []): int {
  try { $st = $pdo->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
  catch (Throwable $e) { return 0; }
}
function safeRows(PDO $pdo, string $sql, array $params = [], int $limit = 10): array {
  try {
    if ($limit > 0 && stripos($sql, 'limit') === false) { $sql .= " LIMIT " . (int)$limit; }
    $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { return []; }
}
function tableExists(PDO $pdo, string $t): bool {
  if (!preg_match('/^[a-zA-Z0-9_]+$/', $t)) return false;
  try { $pdo->query("SELECT 1 FROM `{$t}` LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}
$today = (new DateTime('now'))->format('Y-m-d');
$in7   = (new DateTime('now'))->modify('+7 days')->format('Y-m-d');

// --- KPI: Operations (aligned to DB schema) ---
$kpi = [
  'items_active'     => tableExists($pdo,'items')             ? safeCount($pdo, "SELECT COUNT(*) FROM items WHERE status='active'") : 0, // items.status enum, active=running
  'projects_active'  => tableExists($pdo,'projects')          ? safeCount($pdo, "SELECT COUNT(*) FROM projects WHERE status='active'") : 0,
  'indents_open'     => tableExists($pdo,'indents')           ? safeCount($pdo, "SELECT COUNT(*) FROM indents WHERE COALESCE(status,'') NOT IN ('closed','cancelled')") : 0,
  'rfqs_open'        => tableExists($pdo,'rfqs')              ? safeCount($pdo, "SELECT COUNT(*) FROM rfqs WHERE status IN ('draft','sent','quoted')") : 0,
  'vquotes_pipe'     => tableExists($pdo,'rfq_quotes')        ? safeCount($pdo, "SELECT COUNT(*) FROM rfq_quotes WHERE status IN ('received','revised','shortlisted')") : 0,
  'po_open'          => tableExists($pdo,'purchase_orders')   ? safeCount($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE status NOT IN ('closed','cancelled')") : 0,
  'grn_today'        => tableExists($pdo,'grn')               ? safeCount($pdo, "SELECT COUNT(*) FROM grn WHERE grn_date = ?", [$today]) : 0,
  'issues_today'     => tableExists($pdo,'material_issues')   ? safeCount($pdo, "SELECT COUNT(*) FROM material_issues WHERE issue_date = ?", [$today]) : 0,
  'pwo_open'         => tableExists($pdo,'process_work_orders') ? safeCount($pdo, "SELECT COUNT(*) FROM process_work_orders WHERE status IN ('planned','in_progress')") : 0,
  'gatepass_today'   => tableExists($pdo,'gatepasses')        ? safeCount($pdo, "SELECT COUNT(*) FROM gatepasses WHERE gp_date = ?", [$today]) : 0,
  'unread'           => tableExists($pdo,'notifications')     ? safeCount($pdo, "SELECT COUNT(*) FROM notifications WHERE is_read=0") : 0,
];

// --- Lists: Purchase pipeline (recent) ---
$indents = tableExists($pdo,'indents')
  ? safeRows($pdo, "SELECT id, indent_no, status, created_at FROM indents ORDER BY id DESC", [], 6) : [];
$rfqs = tableExists($pdo,'rfqs')
  ? safeRows($pdo, "SELECT id, rfq_no, status, created_at FROM rfqs ORDER BY id DESC", [], 6) : [];
$vquotes = tableExists($pdo,'rfq_quotes')
  ? safeRows($pdo, "SELECT id, quote_no, status, quote_date FROM rfq_quotes ORDER BY id DESC", [], 6) : [];
$pos = tableExists($pdo,'purchase_orders')
  ? safeRows($pdo, "SELECT id, po_no, supplier_id, status, po_date FROM purchase_orders ORDER BY id DESC", [], 6) : [];

// --- Lists: Stores today (GRN / Issues / Gatepasses) ---
$grnToday = tableExists($pdo,'grn')
  ? safeRows($pdo, "SELECT g.id, g.grn_no, g.grn_date, p.name AS supplier_name, w.name AS warehouse_name
                    FROM grn g
                    LEFT JOIN parties p ON p.id = g.supplier_id
                    LEFT JOIN warehouses w ON w.id = g.received_at_warehouse_id
                    WHERE g.grn_date = ?
                    ORDER BY g.id DESC", [$today], 8) : [];
$issuesToday = tableExists($pdo,'material_issues')
  ? safeRows($pdo, "SELECT mi.id, mi.issue_no, mi.issue_date, w.name AS from_wh
                    FROM material_issues mi
                    LEFT JOIN warehouses w ON w.id = mi.issued_from_warehouse_id
                    WHERE mi.issue_date = ?
                    ORDER BY mi.id DESC", [$today], 8) : [];
$gatepassesToday = tableExists($pdo,'gatepasses')
  ? safeRows($pdo, "SELECT id, gp_no, gp_date, type, vehicle_no FROM gatepasses WHERE gp_date = ? ORDER BY id DESC", [$today], 8) : [];

// --- Lists: Production & Maintenance (compact) ---
$pwoOpen = tableExists($pdo,'process_work_orders')
  ? safeRows($pdo, "SELECT id, pwo_no, status, due_date FROM process_work_orders WHERE status IN ('planned','in_progress') ORDER BY id DESC", [], 6) : [];
$maintTasks = tableExists($pdo,'maintenance_wo_tasks')
  ? safeRows($pdo, "SELECT t.id, t.wo_id, t.task, t.status FROM maintenance_wo_tasks t ORDER BY t.id DESC", [], 6) : [];

// --- Notifications ---
$unread = tableExists($pdo,'notifications')
  ? safeRows($pdo, "SELECT id, title, created_at FROM notifications WHERE is_read=0 ORDER BY id DESC", [], 6) : [];

// --- CRM (for the CRM tab inside dashboard) ---
$crm = [
  'accounts'       => tableExists($pdo,'crm_accounts')    ? safeCount($pdo, "SELECT COUNT(*) FROM crm_accounts WHERE deleted_at IS NULL") : 0,
  'contacts'       => tableExists($pdo,'crm_contacts')    ? safeCount($pdo, "SELECT COUNT(*) FROM crm_contacts WHERE deleted_at IS NULL") : 0,
  'leads_open'     => tableExists($pdo,'crm_leads')       ? safeCount($pdo, "SELECT COUNT(*) FROM crm_leads WHERE deleted_at IS NULL AND status IN ('New','In Progress')") : 0,
  'quotes_sent'    => tableExists($pdo,'sales_quotes')    ? safeCount($pdo, "SELECT COUNT(*) FROM sales_quotes WHERE deleted_at IS NULL AND status='Sent'") : 0,
  'quotes_draft'   => tableExists($pdo,'sales_quotes')    ? safeCount($pdo, "SELECT COUNT(*) FROM sales_quotes WHERE deleted_at IS NULL AND status='Draft'") : 0,
  'orders_ip'      => tableExists($pdo,'sales_orders')    ? safeCount($pdo, "SELECT COUNT(*) FROM sales_orders WHERE deleted_at IS NULL AND status IN ('Confirmed','In Progress')") : 0,
  'acts_due7'      => tableExists($pdo,'crm_activities')  ? safeCount($pdo, "SELECT COUNT(*) FROM crm_activities WHERE deleted_at IS NULL AND status IN ('Open','In Progress') AND due_at<=?", [$in7.' 23:59:59']) : 0,
];
$crmLeads = tableExists($pdo,'crm_leads')
  ? safeRows($pdo, "SELECT id, code, title, status, amount, follow_up_on FROM crm_leads WHERE deleted_at IS NULL ORDER BY id DESC", [], 6) : [];
$crmQuotes = tableExists($pdo,'sales_quotes')
  ? safeRows($pdo, "SELECT id, code, quote_date, status, grand_total FROM sales_quotes WHERE deleted_at IS NULL ORDER BY id DESC", [], 6) : [];
$crmOrders = tableExists($pdo,'sales_orders')
  ? safeRows($pdo, "SELECT id, code, order_date, status, amount FROM sales_orders WHERE deleted_at IS NULL ORDER BY id DESC", [], 6) : [];
$crmActs = tableExists($pdo,'crm_activities')
  ? safeRows($pdo, "SELECT id, code, type, subject, due_at, status FROM crm_activities WHERE deleted_at IS NULL AND status IN ('Open','In Progress') ORDER BY due_at ASC", [], 6) : [];

// --- page chrome (kept generic) ---
$UI_PATH = __DIR__ . '/ui';
$PAGE_TITLE = 'Dashboard';
$ACTIVE_MENU = 'dashboard';
if (is_file($UI_PATH.'/init.php')) require_once $UI_PATH.'/init.php';
if (is_file($UI_PATH.'/layout_start.php')) require_once $UI_PATH.'/layout_start.php';

// decide active tab from query (?tab=crm)
$activeTab = ($_GET['tab'] ?? 'ops') === 'crm' ? 'crm' : 'ops';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center mb-3">
    <h4 class="mb-0">Dashboard</h4>
    <ul class="nav nav-tabs ms-auto">
      <li class="nav-item"><a class="nav-link <?= $activeTab==='ops'?'active':'' ?>" href="?tab=ops">Operations</a></li>
      <li class="nav-item"><a class="nav-link <?= $activeTab==='crm'?'active':'' ?>" href="?tab=crm">CRM</a></li>
    </ul>
  </div>

  <?php if ($activeTab==='ops'): ?>
  <!-- ====================== OPERATIONS TAB ====================== -->
  <div class="row g-3">
    <!-- KPI strip -->
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Active Items</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['items_active'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Active Projects</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['projects_active'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Indents (Open)</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['indents_open'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">RFQs (Draft/Sent/Quoted)</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['rfqs_open'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Vendor Quotes (Pipeline)</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['vquotes_pipe'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Purchase Orders (Open)</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['po_open'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">GRN Today</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['grn_today'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Issues Today</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['issues_today'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">PWOs (Planned/In Progress)</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['pwo_open'] ?></div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-md-4 col-lg-3">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Gate Passes Today</div>
          <div class="fs-4 fw-semibold"><?= (int)$kpi['gatepass_today'] ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Purchase pipeline -->
  <div class="row g-3 mt-1">
    <div class="col-lg-3">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Indents (recent)</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($indents): foreach ($indents as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($r['indent_no'] ?? ('#'.$r['id'])) ?></span>
              <span class="text-muted small"><?= fmt($r['status'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No data</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/purchase/indents_list.php">Open list →</a></div>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>RFQs (recent)</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($rfqs): foreach ($rfqs as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($r['rfq_no'] ?? ('#'.$r['id'])) ?></span>
              <span class="text-muted small"><?= fmt($r['status'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No data</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/purchase/rfq_list.php">Open list →</a></div>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Vendor Quotes (recent)</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($vquotes): foreach ($vquotes as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($r['quote_no'] ?? ('#'.$r['id'])) ?></span>
              <span class="text-muted small"><?= fmt($r['status'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No data</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/purchase/quotes_list.php">Open list →</a></div>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Purchase Orders (recent)</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($pos): foreach ($pos as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($r['po_no'] ?? ('#'.$r['id'])) ?></span>
              <span class="text-muted small"><?= fmt($r['status'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No data</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/purchase/po_list.php">Open list →</a></div>
      </div>
    </div>
  </div>

  <!-- Stores today -->
  <div class="row g-3 mt-1">
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>GRN Today</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($grnToday): foreach ($grnToday as $g): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($g['grn_no'] ?? ('#'.$g['id'])) ?></span>
              <span class="text-muted small"><?= fmt(($g['supplier_name'] ?? '')) ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No GRNs today</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/stores/grn_list.php">Open GRN register →</a></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Material Issues Today</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($issuesToday): foreach ($issuesToday as $m): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($m['issue_no'] ?? ('#'.$m['id'])) ?></span>
              <span class="text-muted small"><?= fmt($m['from_wh'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No issues today</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/stores/issues_list.php">Open issues register →</a></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Gate Passes Today</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($gatepassesToday): foreach ($gatepassesToday as $g): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($g['gp_no'] ?? ('#'.$g['id'])) ?></span>
              <span class="text-muted small"><?= fmt(($g['type'] ?? '') . ' ' . ($g['vehicle_no'] ?? '')) ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No gate passes today</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/stores/gatepass_list.php">Open gate pass register →</a></div>
      </div>
    </div>
  </div>

  <!-- Production & Maintenance + Notifications -->
  <div class="row g-3 mt-1">
    <div class="col-lg-8">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Production Work Orders (open)</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($pwoOpen): foreach ($pwoOpen as $p): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($p['pwo_no'] ?? ('#'.$p['id'])) ?></span>
              <span class="text-muted small"><?= fmt(($p['status'] ?? '')) ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No open PWOs</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/production/pwo_list.php">Open PWO list →</a></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header d-flex align-items-center"><i class="bi bi-bell me-2"></i><strong>Unread Notifications</strong><span class="ms-auto text-muted small"><?= (int)$kpi['unread'] ?></span></div>
        <ul class="list-group list-group-flush">
          <?php if ($unread): foreach ($unread as $n): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt($n['title'] ?? 'Notification') ?></span>
              <span class="text-muted small"><?= fmt($n['created_at'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">All caught up</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/notifications/center.php">Open inbox →</a></div>
      </div>
    </div>
  </div>
  <?php else: ?>
  <!-- ======================== CRM TAB ======================== -->
  <div class="row g-3">
    <!-- CRM KPIs -->
    <div class="col-sm-6 col-md-4 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Accounts</div><div class="fs-4 fw-semibold"><?= (int)$crm['accounts'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Contacts</div><div class="fs-4 fw-semibold"><?= (int)$crm['contacts'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Leads (Open)</div><div class="fs-4 fw-semibold"><?= (int)$crm['leads_open'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Activities (Due ≤ 7d)</div><div class="fs-4 fw-semibold"><?= (int)$crm['acts_due7'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Sales Quotes (Draft)</div><div class="fs-4 fw-semibold"><?= (int)$crm['quotes_draft'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Sales Quotes (Sent)</div><div class="fs-4 fw-semibold"><?= (int)$crm['quotes_sent'] ?></div></div></div></div>
    <div class="col-sm-6 col-md-4 col-lg-3"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Sales Orders (WIP)</div><div class="fs-4 fw-semibold"><?= (int)$crm['orders_ip'] ?></div></div></div></div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Recent Leads</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($crmLeads): foreach ($crmLeads as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt(($r['code'] ?? ('#'.$r['id'])) . ' · ' . ($r['title'] ?? '')) ?></span>
              <span class="text-muted small"><?= fmt($r['status'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No leads</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/crm_leads/crm_leads_list.php">Open leads →</a></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-header"><strong>Recent Sales Quotes</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($crmQuotes): foreach ($crmQuotes as $r): ?>
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
        <div class="card-header"><strong>Upcoming Activities</strong></div>
        <ul class="list-group list-group-flush">
          <?php if ($crmActs): foreach ($crmActs as $r): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= fmt(($r['type'] ?? '').' · '.($r['subject'] ?? '')) ?></span>
              <span class="text-muted small"><?= fmt($r['due_at'] ?? '') ?></span>
            </li>
          <?php endforeach; else: ?><li class="list-group-item text-muted">No activities</li><?php endif; ?>
        </ul>
        <div class="card-footer text-end small"><a href="/crm/activities_list.php">Open activities →</a></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if (is_file($UI_PATH.'/layout_end.php')) require_once $UI_PATH.'/layout_end.php'; ?>
