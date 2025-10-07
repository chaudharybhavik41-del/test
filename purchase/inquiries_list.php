<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('purchase.inquiry.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$project_id = (int)($_GET['project_id'] ?? 0);

$where = [];
$args  = [];
if ($q !== '') { $where[] = "i.inquiry_no LIKE CONCAT('%', ?, '%')"; $args[] = $q; }
if ($status !== '') { $where[] = "i.status = ?"; $args[] = $status; }
if ($project_id > 0) { $where[] = "i.project_id = ?"; $args[] = $project_id; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "
  SELECT i.id, i.inquiry_no, i.inquiry_date, i.status,
         p.name AS project_name,
         (SELECT COUNT(*) FROM inquiry_items ii WHERE ii.inquiry_id=i.id) AS line_count,
         (SELECT COUNT(*) FROM inquiry_suppliers s WHERE s.inquiry_id=i.id) AS vendor_count
  FROM inquiries i
  LEFT JOIN projects p ON p.id = i.project_id
  $whereSql
  ORDER BY i.id DESC
  LIMIT 200";
$st = $pdo->prepare($sql); $st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$projects = $pdo->query("SELECT id, code, name FROM projects ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Purchase Inquiries</h1>
  <div class="d-flex gap-2">
    <?php if (has_permission('purchase.inquiry.manage')): ?>
      <a class="btn btn-primary" href="/purchase/inquiries_form.php">New Inquiry</a>
      <a class="btn btn-outline-primary" href="/purchase/quotes_list.php">Quotation</a>
    <?php endif; ?>
  </div>
</div>

<!-- Filters -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-12 col-md-4">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Find by INQ number">
        </div>
      </div>

      <div class="col-12 col-md-4">
        <select name="project_id" class="form-select">
          <option value="0" <?= $project_id===0?'selected':'' ?>>All Projects</option>
          <?php foreach($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $project_id===(int)$p['id']?'selected':'' ?>>
              <?= htmlspecialchars($p['code'].' — '.$p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <select name="status" class="form-select">
          <option value="" <?= $status===''?'selected':'' ?>>All</option>
          <option value="draft"  <?= $status==='draft'?'selected':'' ?>>Draft</option>
          <option value="issued" <?= $status==='issued'?'selected':'' ?>>Issued</option>
          <option value="closed" <?= $status==='closed'?'selected':'' ?>>Closed</option>
        </select>
      </div>

      <div class="col-6 col-md-2 text-md-end">
        <button class="btn btn-outline-secondary"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== '' || $project_id>0): ?>
          <a class="btn btn-light ms-1" href="<?= strtok($_SERVER['REQUEST_URI'], '?') ?>"><i class="bi bi-x-circle me-1"></i> Reset</a>
        <?php endif; ?>
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
          <th style="width:140px">Inquiry No</th>
          <th style="width:110px">Date</th>
          <th>Project</th>
          <th class="text-center" style="width:110px">Lines</th>
          <th class="text-center" style="width:110px">Vendors</th>
          <th style="width:120px">Status</th>
          <th class="text-end" style="width:180px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td class="fw-semibold"><?= htmlspecialchars($r['inquiry_no']) ?></td>
            <td><?= htmlspecialchars($r['inquiry_date']) ?></td>
            <td><span class="text-muted"><?= htmlspecialchars($r['project_name'] ?? '') ?></span></td>
            <td class="text-center"><?= (int)$r['line_count'] ?></td>
            <td class="text-center"><?= (int)$r['vendor_count'] ?></td>
            <td>
              <?php
                $cls = 'bg-secondary-subtle text-secondary-emphasis border';
                if ($r['status']==='issued') $cls = 'bg-warning-subtle text-warning-emphasis border';
                elseif ($r['status']==='closed') $cls = 'bg-dark-subtle text-dark-emphasis border';
                elseif ($r['status']==='draft') $cls = 'bg-secondary-subtle text-secondary-emphasis border';
              ?>
              <span class="badge <?= $cls ?>"><?= htmlspecialchars($r['status']) ?></span>
            </td>
            <td class="text-end">
              <div class="btn-group btn-group-sm" role="group">
                <a class="btn btn-light" href="/purchase/inquiries_form.php?id=<?= (int)$r['id'] ?>" title="Open">
                  <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <?php if ($r['status']==='issued'): ?>
                  <a class="btn btn-light" href="/purchase/inquiry_print.php?id=<?= (int)$r['id'] ?>" target="_blank" title="Print">
                    <i class="bi bi-printer"></i>
                  </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (!$rows): ?>
          <tr>
            <td colspan="7" class="p-0">
              <div class="text-center text-muted py-4">No inquiries found.</div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>
