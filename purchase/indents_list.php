<?php
/** PATH: /public_html/purchase/indents_list.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('purchase.indent.view');

$pdo = db();
$q      = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$limit  = (int)($_GET['limit'] ?? 200);
$limit  = ($limit>0 && $limit<=500) ? $limit : 200;

$where = []; $p = [];
if ($q !== '') {
  $like = '%'.$q.'%';
  // Force all search comparisons to utf8mb4_unicode_ci to avoid mix errors across tables
  $where[] = "("
           . "i.indent_no COLLATE utf8mb4_unicode_ci LIKE ?"
           . " OR pr.code COLLATE utf8mb4_unicode_ci LIKE ?"
           . " OR pr.name COLLATE utf8mb4_unicode_ci LIKE ?"
           . ")";
  array_push($p, $like, $like, $like);
}
if ($status !== '' && in_array($status, ['draft','raised','approved','closed','cancelled'], true)) {
  // Also force the status comparison to utf8mb4_unicode_ci
  $where[] = "i.status COLLATE utf8mb4_unicode_ci = ?";
  $p[] = $status;
}
$w = $where ? 'WHERE '.implode(' AND ', $where) : '';

$sql = "
  SELECT i.id, i.indent_no, i.status,
         (SELECT MIN(ii.needed_by) FROM indent_items ii WHERE ii.indent_id = i.id) AS needed_by,
         i.created_at, i.priority,
         pr.code AS project_code, pr.name AS project_name
  FROM indents i
  LEFT JOIN projects pr ON pr.id = i.project_id
  $w
  ORDER BY i.id DESC
  LIMIT $limit
";
$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Purchase Indents</h2>
    <div class="d-flex gap-2">
      <form class="d-flex gap-2" method="get">
        <input class="form-control" name="q" placeholder="Search indent/project" value="<?= htmlspecialchars($q) ?>">
        <select class="form-select" name="status">
          <option value="">All</option>
          <?php foreach (['draft','raised','approved','closed','cancelled'] as $st): ?>
            <option value="<?= $st ?>" <?= $status===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-primary" type="submit">Filter</button>
      </form>
      <?php if (user_has_permission('purchase.indent.manage')): ?>
        <a class="btn btn-primary" href="indents_form.php">+ New</a>
        <a class="btn btn-primary" href="inquiries_list.php">+ Inquiry</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
      <thead>
        <tr>
          <th>Indent No</th>
          <th>Project</th>
          <th>Priority</th>
          <th>Status</th>
          <th>Needed By</th>
          <th>Created</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-muted">No indents found.</td></tr>
        <?php else: foreach ($rows as $r):
          $status = $r['status'] ?? 'draft';
          $badge = [
            'draft'     => 'secondary',
            'raised'    => 'warning',
            'approved'  => 'success',
            'closed'    => 'dark',
            'cancelled' => 'danger'
          ][$status] ?? 'secondary';
        ?>
          <tr>
            <td><?= htmlspecialchars($r['indent_no']) ?></td>
            <td><?= $r['project_code']
                  ? htmlspecialchars($r['project_code'].' — '.$r['project_name'])
                  : '<span class="text-muted">General</span>' ?></td>
            <td><?= ucfirst(htmlspecialchars($r['priority'] ?? 'normal')) ?></td>
            <td><span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($status) ?></span></td>
            <td><?= htmlspecialchars((string)$r['needed_by']) ?></td>
            <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="indents_form.php?id=<?= (int)$r['id'] ?>">Open</a>
              <a class="btn btn-sm btn-outline-secondary" href="indent_print.php?id=<?= (int)$r['id'] ?>" target="_blank">Print</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>