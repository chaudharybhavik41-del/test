<?php
declare(strict_types=1);
/** PATH: /public_html/maintenance/intervals_list.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('maintenance.manage');

$pdo = db();

// Filters
$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$q = trim((string)($_GET['q'] ?? ''));

// WHERE
$w = [];
$p = [];
if ($programId > 0) { $w[] = 'mi.program_id=?'; $p[] = $programId; }
if ($q !== '') {
  $w[] = '('
      ."mi.title LIKE CONCAT('%', ?, '%')"
      ." OR mt.name LIKE CONCAT('%', ?, '%')"
      ." OR m.machine_id LIKE CONCAT('%', ?, '%')"
      ." OR COALESCE(mp.notes,'') LIKE CONCAT('%', ?, '%')"
      ." OR COALESCE(mp.default_team,'') LIKE CONCAT('%', ?, '%')"
      .')';
  array_push($p, $q, $q, $q, $q, $q);
}
$whereSql = $w ? 'WHERE '.implode(' AND ', $w) : '';

// Query (matches your schema)
$sql = "
SELECT
  mi.id, mi.program_id, mi.maintenance_type_id, mi.title,
  mi.frequency, mi.interval_count, mi.custom_days,
  mi.notify_before_days, mi.grace_days, mi.requires_shutdown,
  mi.priority, mi.active, mi.last_completed_on, mi.next_due_date,
  mt.name AS mtype_name,
  m.machine_id, m.name AS machine_name,
  mp.notes, mp.default_team
FROM maintenance_intervals mi
LEFT JOIN maintenance_types    mt ON mt.id = mi.maintenance_type_id
LEFT JOIN maintenance_programs mp ON mp.id = mi.program_id
LEFT JOIN machines             m  ON m.id  = mp.machine_id
$whereSql
ORDER BY m.machine_id, mi.priority DESC, mi.next_due_date IS NULL, mi.next_due_date ASC, mi.id DESC
";
$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Program dropdown (label from machine + notes/team)
$progStmt = $pdo->query("
  SELECT mp.id, m.machine_id, m.name AS machine_name, mp.notes, mp.default_team
  FROM maintenance_programs mp
  LEFT JOIN machines m ON m.id = mp.machine_id
  ORDER BY m.machine_id, mp.id
");
$programs = $progStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0">Maintenance Intervals</h1>
  <div class="d-flex gap-2">
    <a href="intervals_form.php<?= $programId>0 ? ('?program_id='.$programId) : '' ?>" class="btn btn-primary btn-sm">Add Interval</a>
    <a href="programs_list.php" class="btn btn-outline-secondary btn-sm">Programs</a>
  </div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-md-4">
    <label class="form-label">Program</label>
    <select name="program_id" class="form-select" onchange="this.form.submit()">
      <option value="0">— All Programs —</option>
      <?php foreach ($programs as $pgr):
        $bits  = array_filter([$pgr['machine_id'] ?? '', $pgr['machine_name'] ?? '']);
        $suff  = trim(implode(' • ', array_filter([$pgr['notes'] ?? '', $pgr['default_team'] ?? ''])));
        $label = trim(implode(' — ', array_filter([implode(' • ', $bits), $suff])));
        if ($label === '') $label = 'Program #'.$pgr['id'];
      ?>
        <option value="<?=$pgr['id']?>" <?=$programId===(int)$pgr['id']?'selected':''?>><?=htmlspecialchars($label)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <label class="form-label">Search</label>
    <input name="q" class="form-control" value="<?=htmlspecialchars($q)?>" placeholder="Title / Type / Machine / Notes / Team">
  </div>
  <div class="col-md-2 d-grid align-items-end">
    <button class="btn btn-outline-secondary mt-4">Filter</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th style="width:160px;">Machine</th>
        <th>Program</th>
        <th>Interval</th>
        <th>Type</th>
        <th>Notify</th>
        <th>Grace</th>
        <th>Shutdown</th>
        <th>Priority</th>
        <th>Last Done</th>
        <th>Next Due</th>
        <th class="text-end" style="width:160px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r):
        $freq  = (string)$r['frequency'];
        $count = (int)$r['interval_count'];
        $intervalText = '';
        switch ($freq) {
          case 'daily': case 'weekly': case 'monthly': case 'quarterly': case 'semiannual': case 'annual':
            $unit = ['daily'=>'day','weekly'=>'week','monthly'=>'month','quarterly'=>'quarter','semiannual'=>'half-year','annual'=>'year'][$freq];
            $intervalText = 'Every '.($count>1?$count.' ':'').$unit.($count>1?'s':'');
            break;
          case 'custom_days':
            $days = (int)$r['custom_days']; $intervalText = $days>0 ? ('Every '.$days.' days') : 'Custom (days)'; break;
          default: $intervalText = ucfirst($freq);
        }
        $progBits = array_filter([$r['machine_id'] ?? '', $r['machine_name'] ?? '']);
        $progSuff = trim(implode(' • ', array_filter([$r['notes'] ?? '', $r['default_team'] ?? ''])));
        $progLbl  = trim(implode(' — ', array_filter([implode(' • ', $progBits), $progSuff])));
        if ($progLbl === '') $progLbl = 'Program #'.$r['program_id'];

        $notify = (int)$r['notify_before_days'];
        $grace  = (int)$r['grace_days'];
        $sdBadge = !empty($r['requires_shutdown']) ? '<span class="badge text-bg-danger">Yes</span>' : '<span class="badge text-bg-secondary">No</span>';
        $prio = htmlspecialchars((string)$r['priority']);
        $actBadge = !empty($r['active']) ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>';
      ?>
      <tr>
        <td class="text-nowrap">
          <div class="fw-semibold"><?=htmlspecialchars((string)$r['machine_id'])?></div>
          <div class="small text-muted"><?=htmlspecialchars((string)$r['machine_name'])?></div>
        </td>
        <td><?=htmlspecialchars($progLbl)?></td>
        <td>
          <div class="fw-semibold"><?=htmlspecialchars((string)$r['title'])?></div>
          <div class="small text-muted"><?=$intervalText?> <?=$actBadge?></div>
        </td>
        <td><?=htmlspecialchars((string)($r['mtype_name'] ?: '-'))?></td>
        <td><?=$notify ? $notify.' d' : '—'?></td>
        <td><?=$grace ? $grace.' d' : '—'?></td>
        <td><?=$sdBadge?></td>
        <td><?= $prio ?></td>
        <td><?=htmlspecialchars($r['last_completed_on'] ?? '—')?></td>
        <td>
          <?php if (!empty($r['next_due_date'])):
            $due = new DateTime((string)$r['next_due_date']);
            $today = new DateTime('today');
            $badge = $due < $today ? 'text-bg-danger' : ($due == $today ? 'text-bg-warning' : 'text-bg-success'); ?>
            <span class="badge <?=$badge?>"><?=htmlspecialchars($r['next_due_date'])?></span>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="text-end">
          <a href="intervals_form.php?id=<?=$r['id']?>" class="btn btn-sm btn-outline-secondary">Edit</a>
          <a href="intervals_generate.php?id=<?=$r['id']?>" class="btn btn-sm btn-outline-primary">Generate WO</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="text-center text-muted py-4">No intervals found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../ui/layout_end.php';