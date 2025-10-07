<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';

$pdo = db();

$q = trim($_GET['q'] ?? '');
$project_id = (int)($_GET['project_id'] ?? 0);

$where=[]; $p=[];
if($q!==''){
  $where[]="(b.bom_no LIKE ? OR pr.name LIKE ? OR pr.code LIKE ?)";
  $p[]="%$q%"; $p[]="%$q%"; $p[]="%$q%";
}
if($project_id>0){
  $where[]="b.project_id=?";
  $p[]=$project_id;
}
$w = $where ? 'WHERE '.implode(' AND ',$where) : '';

$sql = "SELECT b.*, pr.code pcode, pr.name pname
        FROM bom b LEFT JOIN projects pr ON pr.id=b.project_id
        $w
        ORDER BY b.id DESC
        LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$projects = $pdo->query("SELECT id, CONCAT(code,' — ',name) label FROM projects ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__.'/../ui/layout_start.php'; ?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">BOMs</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-primary" href="bom_form.php"><i class="bi bi-plus-lg me-1"></i> New BOM</a>
    <a class="btn btn-outline-primary" href="req_wizard.php"><i class="bi bi-diagram-3 me-1"></i> Plate Requirement</a>
  </div>
</div>

<!-- Filters -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-12 col-md-5">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input class="form-control" name="q" placeholder="Search by BOM / Project code / name" value="<?= htmlspecialchars($q) ?>">
        </div>
      </div>
      <div class="col-12 col-md-5">
        <select class="form-select" name="project_id">
          <option value="0" <?= $project_id===0?'selected':'' ?>>All projects</option>
          <?php foreach($projects as $pr): ?>
            <option value="<?= (int)$pr['id'] ?>" <?= ($project_id===(int)$pr['id']?'selected':'') ?>>
              <?= htmlspecialchars($pr['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2 text-md-end">
        <button class="btn btn-outline-secondary w-100"><i class="bi bi-funnel me-1"></i> Filter</button>
      </div>
    </form>
  </div>
</div>

<!-- Results -->
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-striped table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>BOM No</th>
          <th>Project</th>
          <th>Status</th>
          <th>Revision</th>
          <th>Created</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr>
            <td colspan="6" class="p-0">
              <div class="text-center text-muted py-4">No BOMs</div>
            </td>
          </tr>
        <?php else: foreach($rows as $r): $bomId=(int)$r['id']; ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($r['bom_no']) ?></td>
            <td><?= $r['pcode'] ? htmlspecialchars($r['pcode'].' — '.$r['pname']) : '—' ?></td>
            <td>
              <?php
                // Soft badge styles (Bootstrap 5.3)
                $cls = 'bg-secondary-subtle text-secondary-emphasis border';
                if (($r['status'] ?? '') === 'active')  $cls = 'bg-success-subtle text-success-emphasis border';
                if (($r['status'] ?? '') === 'draft')   $cls = 'bg-secondary-subtle text-secondary-emphasis border';
                if (($r['status'] ?? '') === 'closed')  $cls = 'bg-dark-subtle text-dark-emphasis border';
              ?>
              <span class="badge <?= $cls ?>"><?= htmlspecialchars((string)$r['status']) ?></span>
            </td>
            <td><?= htmlspecialchars((string)$r['revision']) ?></td>
            <td><span class="text-muted"><?= htmlspecialchars((string)$r['created_at']) ?></span></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm" role="group">
                <a class="btn btn-light" href="bom_form.php?id=<?= $bomId ?>" title="Open">
                  <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <!-- Go straight to Routing Editor (passes bom_id) -->
                <a class="btn btn-light" href="/bom/routing_form.php?bom_id=<?= $bomId ?>" title="Routing">
                  <i class="bi bi-diagram-3"></i>
                </a>
                <!-- Quick view of Routing -->
                <a class="btn btn-light" href="/bom/routing_view.php?bom_id=<?= $bomId ?>" title="View">
                  <i class="bi bi-eye"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__.'/../ui/layout_end.php'; ?>
