<?php
/** PATH: /public_html/maintenance/wo_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('maintenance.wo.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$machine_id = (int)($_GET['machine_id'] ?? 0);
$status     = (string)($_GET['status'] ?? '');
$q          = trim((string)($_GET['q'] ?? ''));

// filters
$machines = $pdo->query("SELECT id, CONCAT(machine_id,' - ',name) AS label FROM machines ORDER BY machine_id")->fetchAll(PDO::FETCH_KEY_PAIR);

$where = [];
$params = [];
if ($machine_id) { $where[] = "wo.machine_id = ?"; $params[] = $machine_id; }
if ($status !== '') { $where[] = "wo.status = CAST(? AS CHAR) COLLATE utf8mb4_general_ci"; $params[] = $status; }
if ($q !== '') {
  $where[] = "(wo.wo_code LIKE CONCAT('%', ?, '%') OR wo.title LIKE CONCAT('%', ?, '%') OR m.machine_id LIKE CONCAT('%', ?, '%'))";
  array_push($params, $q, $q, $q);
}

$sql = "SELECT wo.*, m.machine_id, m.name AS machine_name
        FROM maintenance_work_orders wo
        JOIN machines m ON m.id = wo.machine_id
        " . ($where ? "WHERE ".implode(" AND ", $where) : "") . "
        ORDER BY wo.created_at DESC LIMIT 200";
$st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC);

// ui
$PAGE_TITLE = 'Work Orders';
$ACTIVE_MENU = 'machines.list';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0">Work Orders</h1>
  <div class="d-flex gap-2">
    <?php if ($machine_id): ?>
      <a class="btn btn-outline-secondary btn-sm" href="/machines/machines_list.php?id=<?=$machine_id?>">Back to Machine</a>
    <?php endif; ?>
    <?php if (has_permission('maintenance.wo.manage')): ?>
      <a class="btn btn-primary btn-sm" href="/maintenance/wo_form.php?machine_id=<?=$machine_id?>">+ Work Order</a>
    <?php endif; ?>
  </div>
</div>

<form class="row g-2 mb-3">
  <div class="col-md-4">
    <select name="machine_id" class="form-select">
      <option value="0">— All machines —</option>
      <?php foreach ($machines as $id=>$label): ?>
        <option value="<?=$id?>" <?= $machine_id===$id?'selected':'' ?>><?=htmlspecialchars($label)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <select name="status" class="form-select">
      <?php foreach (['','open','in_progress','waiting_parts','completed','cancelled'] as $s): ?>
        <option value="<?=$s?>" <?= $status===$s?'selected':'' ?>><?= $s===''?'— Any status —':ucwords(str_replace('_',' ',$s)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <input class="form-control" name="q" placeholder="WO code / title / machine…" value="<?=htmlspecialchars($q)?>">
  </div>
  <div class="col-md-2">
    <button class="btn btn-outline-secondary w-100">Search</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-striped table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>WO Code</th><th>Machine</th><th>Title</th><th>Type</th><th>Priority</th><th>Status</th><th>Due</th><th class="text-end">Total ₹</th><th style="width:120px;"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><code><?=htmlspecialchars((string)$r['wo_code'])?></code></td>
        <td><?=htmlspecialchars((string)$r['machine_id'].' — '.$r['machine_name'])?></td>
        <td><?=htmlspecialchars((string)$r['title'])?></td>
        <td><?= (int)$r['maintenance_type_id'] ?: '—' ?></td>
        <td><span class="badge bg-secondary"><?=htmlspecialchars((string)$r['priority'])?></span></td>
        <td><span class="badge bg-<?= $r['status']==='completed'?'success':($r['status']==='open'?'warning text-dark':'secondary') ?>"><?=htmlspecialchars((string)$r['status'])?></span></td>
        <td><?=htmlspecialchars((string)$r['due_date'] ?? '')?></td>
        <td class="text-end"><?= number_format((float)$r['total_cost'], 2) ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-primary" href="/maintenance/wo_form.php?id=<?=$r['id']?>">Open</a>
        </td>
      </tr>
    <?php endforeach; if (!$rows): ?>
      <tr><td colspan="9" class="text-muted text-center py-4">No work orders.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../ui/layout_end.php';
