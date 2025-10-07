<?php
/** PATH: /public_html/stores/issues_list.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.issue.view');

$pdo = db();
$q = trim($_GET['q'] ?? '');
$where=[]; $p=[];
if ($q!==''){ $where[]="(mi.issue_no LIKE ? OR pr.name LIKE ?)"; $p[]="%$q%"; $p[]="%$q%"; }
$w = $where ? 'WHERE '.implode(' AND ',$where) : '';
$sql = "SELECT mi.*, pr.code AS project_code, pr.name AS project_name, w.code AS wh_code
        FROM material_issues mi
        LEFT JOIN projects pr ON pr.id = mi.project_id
        LEFT JOIN warehouses w ON w.id = mi.issued_from_warehouse_id
        $w
        ORDER BY mi.id DESC LIMIT 200";
$st = $pdo->prepare($sql); $st->execute($p); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

$page_title="Material Issues";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="requisitions_list.php">Requisitions</a>
  </div>

  <form class="row g-2 mb-3">
    <div class="col-auto">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Search issue no / project" value="<?= htmlspecialchars($q) ?>">
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-secondary">Filter</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>Issue No</th>
          <th>Date</th>
          <th>Warehouse</th>
          <th>Project</th>
          <th>Status</th>
          <th class="text-end">View</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No issues found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($r['issue_no']) ?></td>
            <td><?= htmlspecialchars($r['issue_date']) ?></td>
            <td><span class="badge bg-info-subtle text-info border"><?= htmlspecialchars($r['wh_code']??'') ?></span></td>
            <td><?php if ($r['project_id']): ?><span class="badge bg-info-subtle text-info border"><?= htmlspecialchars($r['project_code']??'') ?></span> <?= htmlspecialchars($r['project_name']??'') ?><?php else: ?>—<?php endif; ?></td>
            <td><span class="badge <?= $r['status']==='issued'?'bg-success':'bg-secondary' ?>"><?= strtoupper($r['status']) ?></span></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="issue_view.php?id=<?= (int)$r['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
