<?php
/** PATH: /public_html/purchase/rfq_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
// If you gate RFQs behind RBAC, you can enable next line:
// require_once __DIR__ . '/../includes/rbac.php';

require_login();
// require_permission('purchase.rfq.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/* ---------- helpers ---------- */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtn($v, int $p = 2): string {
  if ($v === null || $v === '') return '—';
  $v = (float)$v;
  return number_format($v, $p);
}

/* ---------- filters ---------- */
$q         = trim((string)($_GET['q'] ?? ''));                // search by rfq_no
$status    = trim((string)($_GET['status'] ?? ''));           // draft/sent/quoted/awarded/closed/cancelled
$from_date = trim((string)($_GET['from'] ?? ''));             // created from
$to_date   = trim((string)($_GET['to'] ?? ''));               // created to
$open_id   = isset($_GET['open_id']) ? (int)$_GET['open_id'] : 0;

$whereParts = ["1=1"];
$args = [];

if ($q !== '') {
  $whereParts[] = "r.rfq_no LIKE CONCAT('%', ?, '%')";
  $args[] = $q;
}
if ($status !== '') {
  $whereParts[] = "r.status = ?";
  $args[] = $status;
}
if ($from_date !== '') {
  $whereParts[] = "DATE(r.created_at) >= ?";
  $args[] = $from_date;
}
if ($to_date !== '') {
  $whereParts[] = "DATE(r.created_at) <= ?";
  $args[] = $to_date;
}
$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

/* ---------- feature flags (tables exist?) ---------- */
$HAS_RRQ    = true;  // rfq_recipients present (per schema)
$HAS_QUOTES = true;  // rfq_quotes + rfq_quote_lines present (per schema)
// (Both tables exist in your schema dump, so keep as true.)  :contentReference[oaicite:0]{index=0}

/* ---------- main query (JOIN aggregates; no correlated subqueries) ---------- */
$parts = [];
$parts[] = "SELECT r.id, r.rfq_no, r.status, r.created_at";
$parts[] = "     , i.inquiry_no";
$parts[] = "     , pr.code AS project_code";

if ($HAS_RRQ) {
  $parts[] = "     , COALESCE(rr.recipients_cnt, 0) AS recipients_cnt";
} else {
  $parts[] = "     , 0 AS recipients_cnt";
}

if ($HAS_QUOTES) {
  $parts[] = "     , COALESCE(qs.quotes_cnt, 0) AS quotes_cnt";
  $parts[] = "     , qs.best_total";
} else {
  $parts[] = "     , 0 AS quotes_cnt";
  $parts[] = "     , NULL AS best_total";
}

$parts[] = "FROM rfqs r";
$parts[] = "LEFT JOIN inquiries i ON i.id = r.inquiry_id";
$parts[] = "LEFT JOIN projects  pr ON pr.id = r.project_id";

/* recipients aggregate */
if ($HAS_RRQ) {
  $parts[] = "LEFT JOIN (";
  $parts[] = "  SELECT rfq_id, COUNT(*) AS recipients_cnt";
  $parts[] = "  FROM rfq_recipients";
  $parts[] = "  GROUP BY rfq_id";
  $parts[] = ") rr ON rr.rfq_id = r.id";
}

/* quotes aggregate + best total (sum per quote -> min across quotes) */
if ($HAS_QUOTES) {
  $parts[] = "LEFT JOIN (";
  $parts[] = "  SELECT t.rfq_id, COUNT(DISTINCT t.quote_id) AS quotes_cnt, MIN(t.tot_amt) AS best_total";
  $parts[] = "  FROM (";
  $parts[] = "    SELECT q2.rfq_id, ql.quote_id,";
  $parts[] = "           SUM(CASE WHEN ql.rate_basis = 'PER_KG'";
  $parts[] = "                    THEN IFNULL(ql.weight_kg,0) * ql.rate";
  $parts[] = "                    ELSE IFNULL(ql.qty,0)       * ql.rate END) AS tot_amt";
  $parts[] = "    FROM rfq_quotes q2";
  $parts[] = "    JOIN rfq_quote_lines ql ON ql.quote_id = q2.id";
  $parts[] = "    GROUP BY q2.rfq_id, ql.quote_id";
  $parts[] = "  ) t";
  $parts[] = "  GROUP BY t.rfq_id";
  $parts[] = ") qs ON qs.rfq_id = r.id";
}

$parts[] = $whereSql;
$parts[] = "ORDER BY r.id DESC";
$parts[] = "LIMIT 200";

$sql = implode("\n", $parts);
$sth = $pdo->prepare($sql);
$sth->execute($args);
$rows = $sth->fetchAll(PDO::FETCH_ASSOC);

/* ---------- UI ---------- */
$UI_PATH     = __DIR__ . '/../ui';
$PAGE_TITLE  = 'RFQs';
$ACTIVE_MENU = 'purchase.rfq.list';

include $UI_PATH . '/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">RFQs</h1>
    <div class="text-muted small">Showing latest 200</div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">RFQ No.</label>
          <input name="q" class="form-control" value="<?= h($q) ?>" placeholder="Search RFQ No.">
        </div>
        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['','draft','sent','quoted','awarded','closed','cancelled'] as $st): ?>
              <option value="<?= $st ?>" <?= $status===$st?'selected':''?>><?= $st===''?'All':ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-auto">
          <label class="form-label">From</label>
          <input type="date" class="form-control" name="from" value="<?= h($from_date) ?>">
        </div>
        <div class="col-md-auto">
          <label class="form-label">To</label>
          <input type="date" class="form-control" name="to" value="<?= h($to_date) ?>">
        </div>
        <div class="col-md-auto">
          <button class="btn btn-primary">Filter</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:18%">RFQ</th>
              <th style="width:18%">Inquiry / Project</th>
              <th style="width:12%">Status</th>
              <th class="text-center" style="width:10%">Recipients</th>
              <th class="text-center" style="width:10%">Quotes</th>
              <th class="text-end" style="width:14%">Best Total</th>
              <th class="text-end" style="width:18%">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="text-muted p-4">No RFQs found.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <?php
                $badge = match($r['status']) {
                  'sent'      => 'warning',
                  'quoted'    => 'info',
                  'awarded'   => 'success',
                  'closed'    => 'secondary',
                  'cancelled' => 'dark',
                  default     => 'secondary'
                };
              ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= h($r['rfq_no'] ?? ('RFQ-'.(int)$r['id'])) ?></div>
                  <div class="small text-muted">Created: <?= h(isset($r['created_at']) ? substr((string)$r['created_at'],0,19) : '') ?></div>
                </td>
                <td>
                  <div><?= h($r['inquiry_no'] ?: '—') ?></div>
                  <div class="small text-muted"><?= h($r['project_code'] ?: '') ?></div>
                </td>
                <td><span class="badge bg-<?= $badge ?>"><?= h((string)$r['status']) ?></span></td>
                <td class="text-center"><?= (int)($r['recipients_cnt'] ?? 0) ?></td>
                <td class="text-center"><?= (int)($r['quotes_cnt'] ?? 0) ?></td>
                <td class="text-end"><?= ($r['best_total'] !== null) ? fmtn($r['best_total'], 2) : '—' ?></td>
                <td class="text-end">
                  <div class="btn-group">
                    <a class="btn btn-sm btn-outline-secondary" href="/purchase/rfq_build.php?id=<?= (int)$r['id'] ?>">Open</a>
                    <a class="btn btn-sm btn-outline-secondary" href="/purchase/rfq_send.php?id=<?= (int)$r['id'] ?>">Send</a>
                    <a class="btn btn-sm btn-outline-secondary" href="/purchase/rfq_print.php?id=<?= (int)$r['id'] ?>" target="_blank">Print</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include $UI_PATH . '/layout_end.php'; ?>
