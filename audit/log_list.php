<?php
/** PATH: /public_html/audit/log_list.php */
declare(strict_types=1);

/* Robust bootstrap: works from /public_html/audit/* */
$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';

require_login();
if (!has_permission('audit.view')) { http_response_code(403); exit('Forbidden'); }

$pdo = db();

/* ---- Filters ---- */
$q_entity     = trim((string)($_GET['entity'] ?? ''));   // parties, projects, items, etc.
$q_entity_id  = trim((string)($_GET['entity_id'] ?? '')) !== '' ? (int)$_GET['entity_id'] : null;
$q_action     = trim((string)($_GET['action'] ?? ''));   // created, updated, deleted...
$q_actor_id   = trim((string)($_GET['actor_id'] ?? '')) !== '' ? (int)$_GET['actor_id'] : null;
$q_from       = trim((string)($_GET['from'] ?? ''));     // YYYY-MM-DD
$q_to         = trim((string)($_GET['to'] ?? ''));       // YYYY-MM-DD

$where = [];
$params = [];

if ($q_entity !== '') { $where[] = "a.entity_type = ?"; $params[] = $q_entity; }
if ($q_entity_id !== null) { $where[] = "a.entity_id = ?"; $params[] = $q_entity_id; }
if ($q_action !== '') { $where[] = "a.action = ?"; $params[] = $q_action; }
if ($q_actor_id !== null) { $where[] = "a.actor_id = ?"; $params[] = $q_actor_id; }
if ($q_from !== '') { $where[] = "a.created_at >= ?"; $params[] = $q_from . " 00:00:00"; }
if ($q_to   !== '') { $where[] = "a.created_at <= ?"; $params[] = $q_to . " 23:59:59"; }

$WHERE_SQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---- Pagination (simple) ---- */
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;
$off  = ($page - 1) * $per;

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log a $WHERE_SQL");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));

$sql = "SELECT a.id, a.actor_id, a.entity_type, a.entity_id, a.action, a.ip_addr, a.created_at,
               u.username AS actor_username, u.name AS actor_name
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.actor_id
        $WHERE_SQL
        ORDER BY a.id DESC
        LIMIT $per OFFSET $off";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---- UI ---- */
$UI_PATH     = $ROOT . '/ui';
$PAGE_TITLE  = 'Audit Log';
$ACTIVE_MENU = 'system.audit';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Audit Log</h1>
</div>

<form method="get" class="row g-2 mb-3">
  <div class="col-md-3">
    <input class="form-control" name="entity" placeholder="Entity (e.g. parties)" value="<?= htmlspecialchars($q_entity) ?>">
  </div>
  <div class="col-md-2">
    <input class="form-control" name="entity_id" placeholder="Entity ID" value="<?= htmlspecialchars((string)($q_entity_id ?? '')) ?>">
  </div>
  <div class="col-md-2">
    <input class="form-control" name="action" placeholder="Action (created/updated/...)" value="<?= htmlspecialchars($q_action) ?>">
  </div>
  <div class="col-md-2">
    <input class="form-control" name="actor_id" placeholder="Actor ID" value="<?= htmlspecialchars((string)($q_actor_id ?? '')) ?>">
  </div>
  <div class="col-md-3">
    <div class="d-flex gap-2">
      <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($q_from) ?>">
      <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($q_to) ?>">
    </div>
  </div>
  <div class="col-md-12">
    <button class="btn btn-outline-secondary">Filter</button>
    <a class="btn btn-outline-dark" href="/audit/log_list.php">Reset</a>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th>#</th>
        <th>When</th>
        <th>Actor</th>
        <th>Entity</th>
        <th>Entity ID</th>
        <th>Action</th>
        <th>IP</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><small class="text-muted"><?= htmlspecialchars((string)$r['created_at']) ?></small></td>
          <td>
            <?= htmlspecialchars((string)($r['actor_username'] ?? 'user#'.$r['actor_id'])) ?>
            <?php if (!empty($r['actor_name'])): ?>
              <small class="text-muted"> (<?= htmlspecialchars((string)$r['actor_name']) ?>)</small>
            <?php endif; ?>
          </td>
          <td><code><?= htmlspecialchars((string)$r['entity_type']) ?></code></td>
          <td><?= (int)$r['entity_id'] ?></td>
          <td><span class="badge text-bg-light border"><?= htmlspecialchars((string)$r['action']) ?></span></td>
          <td><small class="text-muted"><?= htmlspecialchars((string)($r['ip_addr'] ?? '')) ?></small></td>
          <td><button class="btn btn-sm btn-outline-primary" onclick="viewAudit(<?= (int)$r['id'] ?>)">View</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No logs found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<nav aria-label="pager">
  <ul class="pagination">
    <?php for ($i=1; $i<=$pages; $i++): ?>
      <li class="page-item <?= $i===$page ? 'active' : '' ?>">
        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>"><?= $i ?></a>
      </li>
    <?php endfor; ?>
  </ul>
</nav>

<!-- Modal -->
<div class="modal fade" id="auditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Audit Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="auditBody">
        <div class="text-center text-muted">Loading...</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
async function viewAudit(id){
  const res = await fetch('/audit/log_view.php?id=' + encodeURIComponent(id));
  const html = await res.text();
  document.getElementById('auditBody').innerHTML = html;
  const m = new bootstrap.Modal(document.getElementById('auditModal'));
  m.show();
}
</script>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
