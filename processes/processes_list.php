<?php
declare(strict_types=1);
/** PATH: /public_html/processes/processes_list.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
if (function_exists('require_login')) require_login();
if (function_exists('require_permission')) @require_permission('processes.manage');

$pdo = db();

/** Force the session collation to baseline (no kernel change) */
$pdo->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->query("SET collation_connection = 'utf8mb4_general_ci'");

$q = trim($_GET['q'] ?? '');

/** Note: apply COLLATE on both sides to avoid mix errors */
$sql = "SELECT id, code, name, kind, default_setup_min, default_run_min, requires_machine, skill_level, active
        FROM processes
        WHERE (
          (? COLLATE utf8mb4_general_ci = '')
          OR (code COLLATE utf8mb4_general_ci LIKE CONCAT('%', ? COLLATE utf8mb4_general_ci, '%'))
          OR (name COLLATE utf8mb4_general_ci LIKE CONCAT('%', ? COLLATE utf8mb4_general_ci, '%'))
        )
        ORDER BY code ASC
        LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute([$q, $q, $q]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Processes</h1>
    <a href="processes_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> New</a>
  </div>

  <form class="row g-2 mb-3" method="get">
    <div class="col-auto"><input type="text" class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search code or name"></div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Search</button></div>
  </form>

  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead class="table-light">
        <tr>
          <th>Code</th><th>Name</th><th>Kind</th><th class="text-end">Setup (min)</th><th class="text-end">Run (min)</th><th>Machine?</th><th>Skill</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['code'])?></td>
          <td><?=htmlspecialchars($r['name'])?></td>
          <td><span class="badge bg-secondary"><?=htmlspecialchars($r['kind'] ?? 'production')?></span></td>
          <td class="text-end"><?= $r['default_setup_min'] !== null ? number_format((float)$r['default_setup_min'],2) : '—' ?></td>
          <td class="text-end"><?= $r['default_run_min'] !== null ? number_format((float)$r['default_run_min'],2) : '—' ?></td>
          <td><?= (int)$r['requires_machine'] ? 'Yes' : 'No' ?></td>
          <td><?= $r['skill_level'] !== null ? (int)$r['skill_level'] : '—' ?></td>
          <td><?= (int)$r['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
          <td class="text-end"><a href="processes_form.php?id=<?=$r['id']?>" class="btn btn-sm btn-outline-primary">Edit</a></td>
        </tr>
      <?php endforeach; if (!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No processes found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
