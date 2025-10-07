<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_permission('purchase.indent.view');

$pdo = db();
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$project_id = (int)($_GET['project_id'] ?? 0);
$where = []; $p = [];

if ($q !== '') {
  $like = "%$q%";
  $where[] = "(r.rmi_no COLLATE utf8mb4_unicode_ci LIKE ? OR pr.code COLLATE utf8mb4_unicode_ci LIKE ? OR pr.name COLLATE utf8mb4_unicode_ci LIKE ?)";
  array_push($p, $like, $like, $like);
}
if ($status !== '' && in_array($status, ['draft','raised','approved','closed','cancelled'], true)) {
  $where[] = "r.status COLLATE utf8mb4_unicode_ci = ?";
  $p[] = $status;
}
if ($project_id > 0) { $where[] = "r.project_id = ?"; $p[] = $project_id; }

$w = $where ? 'WHERE '.implode(' AND ', $where) : '';

$sql = "
SELECT r.id, r.rmi_no, r.status, r.priority, r.created_at,
       pr.code project_code, pr.name project_name,
       COALESCE( (SELECT SUM(theoretical_weight_kg) FROM rm_indent_lines l WHERE l.rmi_id=r.id), 0) total_kg
FROM rm_indents r
LEFT JOIN projects pr ON pr.id=r.project_id
$w
ORDER BY r.id DESC
LIMIT 300";
$st = $pdo->prepare($sql); $st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$projects = $pdo->query("SELECT id, CONCAT(code,' — ',name) label FROM projects ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__.'/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Raw-Material Indents (RMI)</h2>
    <?php if (user_has_permission('purchase.indent.manage')): ?>
      <a class="btn btn-primary" href="rm_indent_form.php">+ New RMI</a>
    <?php endif; ?>
  </div>

  <form class="row g-2 mb-3">
    <div class="col-md-4"><input class="form-control" name="q" placeholder="Search RMI/Project" value="<?=htmlspecialchars($q)?>"></div>
    <div class="col-md-3">
      <select class="form-select" name="project_id">
        <option value="0">All projects</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?=$p['id']?>" <?=($project_id===(int)$p['id']?'selected':'')?>><?=htmlspecialchars($p['label'])?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select class="form-select" name="status">
        <option value="">All status</option>
        <?php foreach (['draft','raised','approved','closed','cancelled'] as $s): ?>
          <option value="<?=$s?>" <?=($status===$s?'selected':'')?>><?=ucfirst($s)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-primary w-100">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead><tr>
        <th>RMI No</th><th>Project</th><th>Priority</th><th>Status</th><th class="text-end">Total kg</th><th>Created</th><th class="text-end">Actions</th>
      </tr></thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-muted">No RMIs yet.</td></tr>
        <?php else: foreach ($rows as $r):
          $badge = ['draft'=>'secondary','raised'=>'warning','approved'=>'success','closed'=>'dark','cancelled'=>'danger'][$r['status']] ?? 'secondary'; ?>
          <tr>
            <td><?=htmlspecialchars($r['rmi_no'])?></td>
            <td><?= $r['project_code'] ? htmlspecialchars($r['project_code'].' — '.$r['project_name']) : '<span class="text-muted">General</span>' ?></td>
            <td><?=ucfirst(htmlspecialchars($r['priority']))?></td>
            <td><span class="badge bg-<?=$badge?>"><?=htmlspecialchars($r['status'])?></span></td>
            <td class="text-end"><?=number_format((float)$r['total_kg'],3)?></td>
            <td><?=htmlspecialchars((string)$r['created_at'])?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="rm_indent_form.php?id=<?=$r['id']?>">Open</a>
              <a class="btn btn-sm btn-outline-secondary" href="rm_indent_print.php?id=<?=$r['id']?>" target="_blank">Print</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__.'/../ui/layout_end.php';