<?php
/** PATH: /public_html/stores/issue_view.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.issue.view');

$pdo = db();

function best_code_col(PDO $pdo, string $table): ?string {
  $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
  foreach (['code','material_code'] as $c) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $q->execute([$db,$table,$c]);
    if ((int)$q->fetchColumn()>0) return $c;
  }
  return null;
}

$id = (int)($_GET['id'] ?? 0);
$hdr = null; $lines=[];
if ($id>0){
  $st = $pdo->prepare("SELECT mi.*, w.name AS wh_name, w.code AS wh_code, pr.code AS project_code, pr.name AS project_name
                       FROM material_issues mi
                       LEFT JOIN warehouses w ON w.id = mi.issued_from_warehouse_id
                       LEFT JOIN projects pr ON pr.id = mi.project_id
                       WHERE mi.id=?");
  $st->execute([$id]); $hdr=$st->fetch(PDO::FETCH_ASSOC);

  $iCode = best_code_col($pdo,'items') ?: 'id'; // fallback to id label if none
  $uCode = best_code_col($pdo,'uom');

  $iCodeSel = $iCode ? "i.`$iCode` AS item_code," : "'' AS item_code,";
  $uCodeSel = $uCode ? "u.`$uCode` AS uom_code,"  : "'' AS uom_code,";

  $sql = "SELECT mii.*, {$iCodeSel} i.name AS item_name, {$uCodeSel} u.name AS uom_name
          FROM material_issue_items mii
          JOIN items i ON i.id = mii.item_id
          JOIN uom u   ON u.id = mii.uom_id
          WHERE mii.issue_id=? ORDER BY mii.id";
  $st2 = $pdo->prepare($sql);
  $st2->execute([$id]); $lines=$st2->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = $hdr ? ("Issue ".$hdr['issue_no']) : "Issue";
require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= htmlspecialchars($page_title) ?></h1>
    <div><a href="issues_list.php" class="btn btn-outline-secondary btn-sm">Back</a></div>
  </div>

  <?php if(!$hdr): ?>
    <div class="alert alert-warning">Issue not found.</div>
  <?php else: ?>
    <div class="row g-2 mb-3">
      <div class="col-md-3"><div class="small text-muted">Issue No</div><div class="fw-semibold"><?= htmlspecialchars($hdr['issue_no']) ?></div></div>
      <div class="col-md-3"><div class="small text-muted">Date</div><div class="fw-semibold"><?= htmlspecialchars($hdr['issue_date']) ?></div></div>
      <div class="col-md-3"><div class="small text-muted">Warehouse</div><div class="fw-semibold"><?= htmlspecialchars(($hdr['wh_code']??'').' — '.($hdr['wh_name']??'')) ?></div></div>
      <div class="col-md-3"><div class="small text-muted">Project</div>
        <div class="fw-semibold">
          <?php if ($hdr['project_id']): ?>
            <span class="badge bg-info-subtle text-info border"><?= htmlspecialchars($hdr['project_code']??'') ?></span>
            <?= htmlspecialchars($hdr['project_name']??'') ?>
          <?php else: ?>—<?php endif; ?>
        </div>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>Item</th>
            <th class="text-center">UOM</th>
            <th class="text-end">Qty</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lines as $ln): ?>
            <tr>
              <td><?= htmlspecialchars(($ln['item_code']??'').' — '.($ln['item_name']??'')) ?></td>
              <td class="text-center"><?= htmlspecialchars($ln['uom_code']??$ln['uom_name']??'') ?></td>
              <td class="text-end"><?= number_format((float)$ln['qty'], 3) ?></td>
              <td><?= htmlspecialchars($ln['remarks']??'') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../ui/layout_end.php'; ?>
