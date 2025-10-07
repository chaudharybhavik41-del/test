<?php
declare(strict_types=1);
/** PATH: /public_html/machines/machines_list.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/machines_helpers.php';

require_login();
require_permission('machines.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/* filters */
$q = trim((string)($_GET['q'] ?? ''));
$where=[]; $args=[];
if ($q !== '') {
  $where[]="(m.machine_id LIKE CONCAT('%', ?, '%') OR m.name LIKE CONCAT('%', ?, '%') OR m.make LIKE CONCAT('%', ?, '%'))";
  array_push($args,$q,$q,$q);
}

$sql = "SELECT m.*
          FROM machines m
         ".($where?("WHERE ".implode(" AND ",$where)):"")."
         ORDER BY m.machine_id ASC
         LIMIT 500";
$st = $pdo->prepare($sql); $st->execute($args); $rows = $st->fetchAll(PDO::FETCH_ASSOC);

$PAGE_TITLE='Machines';
$ACTIVE_MENU='machines.list';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0">Machines</h1>
  <div class="d-flex gap-2">
    <form class="d-flex gap-2" method="get">
      <input class="form-control form-control-sm" name="q" placeholder="Search ID/Name/Make" value="<?=htmlspecialchars($q)?>">
      <button class="btn btn-sm btn-outline-secondary">Search</button>
    </form>
    <?php if (has_permission('machines.manage')): ?>
      <a class="btn btn-primary btn-sm" href="/machines/machines_form.php">+ Add</a>
    <?php endif; ?>
  </div>
</div>

<div class="table-responsive">
  <table class="table table-striped table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>ID</th><th>Name</th><th>Make/Model</th><th>Year</th><th>Holder</th>
        <th class="text-end" style="width:360px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): $holder = machine_current_holder($pdo, (int)$r['id']); ?>
      <tr>
        <td><code><?=htmlspecialchars((string)$r['machine_id'])?></code></td>
        <td><?=htmlspecialchars((string)$r['name'])?></td>
        <td><?=htmlspecialchars((string)$r['make'].' '.$r['model'])?></td>
        <td><?=htmlspecialchars((string)$r['purchase_year'])?></td>
        <td>
          <?php if ($holder): ?>
            <span class="badge bg-warning text-dark" title="Issued on <?=htmlspecialchars((string)$holder['alloc_date'])?>">
              <?=htmlspecialchars((string)$holder['contractor_code'].' — '.$holder['contractor_name'])?>
            </span>
          <?php else: ?>
            <span class="badge bg-success">Available</span>
          <?php endif; ?>
        </td>
        <td class="text-end">
          <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-dark" href="/machines/machines_view.php?id=<?=$r['id']?>">Edit</a>
            <a class="btn btn-outline-dark" href="/maintenance/wo_list.php?machine_id=<?=$r['id']?>">WOs</a>
            <a class="btn btn-outline-primary" href="/maintenance/wo_form.php?machine_id=<?=$r['id']?>">+ WO</a>
            <a class="btn btn-outline-secondary" href="/maintenance/programs_list.php?machine_id=<?=$r['id']?>">Programs</a>
            <a class="btn btn-outline-danger" href="/maintenance/breakdown_quick_create.php?machine_id=<?=$r['id']?>&symptom=Breakdown%20reported&severity=high">+ Breakdown</a>
            <?php if ($holder): ?>
              <a class="btn btn-success" href="/maintenance_alloc/allocations_return.php?id=<?=$holder['alloc_id']?>">Return</a>
            <?php else: ?>
              <a class="btn btn-success" href="/maintenance_alloc/allocations_form.php?machine_id=<?=$r['id']?>">Issue</a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; if (!$rows): ?>
      <tr><td colspan="6" class="text-center text-muted py-4">No machines found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/../ui/layout_end.php';