<?php
/** PATH: /public_html/maintenance_alloc/allocations_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('machines.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$machine_id  = (int)($_GET['machine_id'] ?? 0);
$contractor  = (int)($_GET['contractor_id'] ?? 0);
$status      = (string)($_GET['status'] ?? '');
$q           = trim((string)($_GET['q'] ?? ''));

$where = []; $args = [];
if ($machine_id) { $where[] = "ma.machine_id=?"; $args[]=$machine_id; }
if ($contractor){ $where[] = "ma.contractor_id=?"; $args[]=$contractor; }
if ($status !== '') { $where[] = "ma.status = CAST(? AS CHAR) COLLATE utf8mb4_general_ci"; $args[]=$status; }
if ($q!=='') { $where[] = "ma.alloc_code LIKE CONCAT('%',?,'%')"; $args[]=$q; }

$sql = "SELECT ma.*, m.machine_id AS mcode, m.name AS mname, p.code AS ccode, p.name AS cname
        FROM machine_allocations ma
        JOIN machines m ON m.id=ma.machine_id
        JOIN parties  p ON p.id=ma.contractor_id
        ".($where?"WHERE ".implode(" AND ",$where):"")."
        ORDER BY ma.created_at DESC LIMIT 300";
$st = $pdo->prepare($sql); $st->execute($args); $rows = $st->fetchAll(PDO::FETCH_ASSOC);

$machines = $pdo->query("SELECT id, CONCAT(machine_id,' - ',name) FROM machines ORDER BY machine_id")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
$contractors = $pdo->query("SELECT id, CONCAT(code,' - ',name) FROM parties WHERE type='contractor' ORDER BY name")
                  ->fetchAll(PDO::FETCH_KEY_PAIR); // reuse Parties contractors. 3

$PAGE_TITLE='Machine Allocations';
$ACTIVE_MENU='machines.list';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Machine Allocations</h1>
  <div class="d-flex gap-2">
    <a href="/maintenance_alloc/allocations_form.php" class="btn btn-primary btn-sm">+ Issue</a>
    <a href="/maintenance_alloc/allocations_report_contractor.php" class="btn btn-outline-dark btn-sm">Report</a>
    <a href="/machines/machines_list.php" class="btn btn-outline-dark btn-sm">Manage Machines</a>
  </div>
</div>

<form class="row g-2 mb-3">
  <div class="col-md-4">
    <select name="machine_id" class="form-select">
      <option value="0">— All machines —</option>
      <?php foreach($machines as $id=>$label): ?>
        <option value="<?=$id?>" <?=$machine_id===$id?'selected':''?>><?=htmlspecialchars($label)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <select name="contractor_id" class="form-select">
      <option value="0">— All contractors —</option>
      <?php foreach($contractors as $id=>$label): ?>
        <option value="<?=$id?>" <?=$contractor===$id?'selected':''?>><?=htmlspecialchars($label)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <select name="status" class="form-select">
      <?php foreach(['','issued','returned','lost','scrapped'] as $s): ?>
        <option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=$s===''?'— Any status —':ucfirst($s)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2 d-flex gap-2">
    <input name="q" class="form-control" placeholder="Alloc code…" value="<?=htmlspecialchars($q)?>">
    <button class="btn btn-outline-secondary">Go</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-striped table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>Alloc</th><th>Machine</th><th>Contractor</th>
        <th>Issued</th><th>Exp. Return</th><th>Status</th>
        <th class="text-end">Meter</th><th class="text-end">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($rows as $r): ?>
      <tr>
        <td><code><?=htmlspecialchars($r['alloc_code'])?></code></td>
        <td><?=htmlspecialchars($r['mcode'].' — '.$r['mname'])?></td>
        <td><?=htmlspecialchars($r['ccode'].' — '.$r['cname'])?></td>
        <td><?=htmlspecialchars($r['alloc_date'])?></td>
        <td><?=htmlspecialchars((string)$r['expected_return'])?></td>
        <td><span class="badge bg-<?= $r['status']==='issued'?'warning text-dark':'secondary'?>"><?=htmlspecialchars($r['status'])?></span></td>
        <td class="text-end"><?=number_format((float)$r['meter_issue'],2)?> → <?=number_format((float)$r['meter_return'],2)?></td>
        <td class="text-end">
          <?php if($r['status']==='issued'): ?>
            <a class="btn btn-sm btn-outline-success" href="/maintenance_alloc/allocations_return.php?id=<?=$r['id']?>">Return</a>
          <?php else: ?>
            <a class="btn btn-sm btn-outline-secondary" href="/maintenance_alloc/allocations_form.php?id=<?=$r['id']?>">View</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; if(!$rows): ?>
      <tr><td colspan="8" class="text-center text-muted py-4">No allocations.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/../ui/layout_end.php';