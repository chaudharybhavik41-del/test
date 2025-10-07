<?php
/** PATH: /public_html/maintenance/programs_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/machines_helpers.php';

require_login();
require_permission('maintenance.plan.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$machine_id = (int)($_GET['machine_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

// machine (header)
$machine = null;
if ($machine_id) {
  $st = $pdo->prepare("SELECT id, machine_id, name, make, model FROM machines WHERE id=?");
  $st->execute([$machine_id]);
  $machine = $st->fetch(PDO::FETCH_ASSOC);
}
$holder = $machine ? machine_current_holder($pdo, (int)$machine['id']) : null;

// list programs
$where = []; $params = [];
if ($machine_id) { $where[]="p.machine_id=?"; $params[]=$machine_id; }
if ($q!=='') { $where[]="(p.notes LIKE CONCAT('%', ?, '%') OR COALESCE(p.default_team,'') LIKE CONCAT('%', ?, '%'))"; array_push($params,$q,$q); }
$sql = "SELECT p.*, (SELECT COUNT(*) FROM maintenance_intervals mi WHERE mi.program_id=p.id AND mi.active=1) as intervals_count
        FROM maintenance_programs p ".($where?"WHERE ".implode(" AND ",$where):"")." ORDER BY p.id DESC";
$rows = $pdo->prepare($sql); $rows->execute($params); $rows = $rows->fetchAll(PDO::FETCH_ASSOC);

$PAGE_TITLE = 'Maintenance Programs';
$ACTIVE_MENU = 'machines.list';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h5 mb-0">Maintenance Programs</h1>
    <?php if ($machine): ?>
      <div class="text-muted small">Machine: <strong><?=htmlspecialchars((string)$machine['machine_id'])?></strong> — <?=htmlspecialchars((string)$machine['name'])?> (<?=htmlspecialchars((string)$machine['make'].' '.$machine['model'])?>)</div>
    <?php endif; ?>
  </div>
  <div class="d-flex align-items-center gap-2">
    <?php if ($holder): ?>
      <span class="badge bg-warning text-dark">Held by <?=htmlspecialchars($holder['contractor_code'].' — '.$holder['contractor_name'])?></span>
    <?php endif; ?>
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-dark" href="/maintenance/wo_list.php?machine_id=<?=$machine_id?>">WO List</a>
      <a class="btn btn-outline-primary" href="/maintenance/wo_form.php?machine_id=<?=$machine_id?>">+ WO</a>
      <a class="btn btn-outline-danger" href="/maintenance/breakdown_quick_create.php?machine_id=<?=$machine_id?>&symptom=Breakdown%20reported&severity=high">+ Breakdown</a>
    </div>
    <a class="btn btn-light btn-sm" href="/machines/machines_list.php">Back to Machines</a>
    <a class="btn btn-primary btn-sm" href="/maintenance/programs_form.php?machine_id=<?=$machine_id?>">+ Program</a>
  </div>
</div>

<form class="row g-2 mb-3">
  <input type="hidden" name="machine_id" value="<?=$machine_id?>">
  <div class="col-md-6">
    <input class="form-control" name="q" placeholder="Search team / notes…" value="<?=htmlspecialchars($q)?>">
  </div>
  <div class="col-md-2">
    <button class="btn btn-outline-secondary w-100">Search</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-striped table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>#</th><th>Intervals (active)</th><th>Anchor Date</th><th>Default Team</th><th>Notes</th><th style="width:220px;"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><span class="badge bg-secondary"><?= (int)$r['intervals_count'] ?></span></td>
        <td><?= htmlspecialchars((string)$r['anchor_date'] ?? '') ?></td>
        <td><?= htmlspecialchars((string)$r['default_team'] ?? '') ?></td>
        <td><?= htmlspecialchars((string)$r['notes'] ?? '') ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-primary" href="/maintenance/programs_form.php?id=<?=$r['id']?>">Edit</a>
          <a class="btn btn-sm btn-outline-success" href="/maintenance/intervals_generate.php?program_id=<?=$r['id']?>">Generate Due</a>
          <a class="btn btn-sm btn-outline-dark" href="/maintenance/wo_list.php?machine_id=<?=$machine_id?>">Work Orders</a>
        </td>
      </tr>
    <?php endforeach; if (!$rows): ?>
      <tr><td colspan="6" class="text-muted text-center py-4">No programs yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../ui/layout_end.php';