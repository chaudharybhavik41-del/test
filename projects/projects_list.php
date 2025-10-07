<?php
/** PATH: /public_html/projects/projects_list.php */
declare(strict_types=1);

// 1) Auth FIRST so user_has_permission()/require_permission() exist
require_once __DIR__ . '/../includes/auth.php';
// 2) DB next
require_once __DIR__ . '/../includes/db.php';

// Guard this page
require_permission('project.project.view');

$pdo = db();

/* ---------------------------
   Filters & safe parameters
----------------------------*/
$q       = trim($_GET['q'] ?? '');
$status  = trim($_GET['status'] ?? ''); // planned|active|on-hold|closed
$limit   = (int)($_GET['limit'] ?? 200);
$limit   = ($limit > 0 && $limit <= 500) ? $limit : 200;

$whereSql = [];
$params   = [];

// Search filter (collate columns to baseline to avoid cross-table mismatch)
if ($q !== '') {
    $like = '%' . $q . '%';
    $whereSql[] = "(pr.code COLLATE utf8mb4_general_ci LIKE ?
                 OR pr.name COLLATE utf8mb4_general_ci LIKE ?
                 OR p.name  COLLATE utf8mb4_general_ci LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

// Status filter
$validStatuses = ['planned','active','on-hold','closed'];
if ($status !== '' && in_array($status, $validStatuses, true)) {
    $whereSql[] = "pr.status = ?";
    $params[] = $status;
}

$whereClause = $whereSql ? ('WHERE ' . implode(' AND ', $whereSql)) : '';

/* ---------------------------
   Query
----------------------------*/
$sql = "
  SELECT pr.id, pr.code, pr.name, pr.status, pr.start_date, pr.end_date,
         p.name AS client_name, pt.name AS type_name, pr.site_city, pr.site_state
  FROM projects pr
  LEFT JOIN parties p ON p.id = pr.client_party_id
  LEFT JOIN project_types pt ON pt.id = pr.type_id
  $whereClause
  ORDER BY pr.id DESC
  LIMIT $limit
";

$st   = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Only include layout after auth/db/logic are loaded
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Projects</h2>
    <div class="d-flex gap-2">
      <form class="d-flex gap-2" method="get">
        <input class="form-control" name="q" placeholder="Search code/name/client" value="<?= htmlspecialchars($q) ?>">
        <select class="form-select" name="status">
          <option value="">All Status</option>
          <?php foreach (['planned','active','on-hold','closed'] as $st): ?>
            <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-select" name="limit" title="Rows">
          <?php foreach ([50,100,200,300,500] as $opt): ?>
            <option value="<?= $opt ?>" <?= $limit===$opt ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-primary" type="submit">Filter</button>
      </form>
      <?php if (user_has_permission('project.project.manage')): ?>
        <a class="btn btn-primary" href="projects_form.php">+ New</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
      <thead>
        <tr>
          <th style="min-width:120px;">Code</th>
          <th style="min-width:200px;">Name</th>
          <th>Client</th>
          <th>Type</th>
          <th>Status</th>
          <th>Start</th>
          <th>End</th>
          <th>Site</th>
          <th style="width:80px;"></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-muted">No projects found.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['code']) ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['client_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['type_name'] ?? '') ?></td>
            <td><span class="badge bg-secondary"><?= htmlspecialchars($r['status']) ?></span></td>
            <td><?= htmlspecialchars((string)$r['start_date']) ?></td>
            <td><?= htmlspecialchars((string)$r['end_date']) ?></td>
            <td><?= htmlspecialchars(trim((string)($r['site_city'] ?? '') . ', ' . (string)($r['site_state'] ?? ''), ' ,')) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="projects_form.php?id=<?= (int)$r['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>