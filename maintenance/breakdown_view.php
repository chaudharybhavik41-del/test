<?php
declare(strict_types=1);
/** PATH: /public_html/maintenance/breakdown_view.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('maintenance.wo.view'); // or 'maintenance.breakdown.view'

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id = (int)($_GET['id'] ?? 0);
$ok = isset($_GET['ok']);

$st = $pdo->prepare("SELECT b.*, m.machine_id AS machine_code, m.name AS machine_name
                       FROM breakdown_tickets b
                       JOIN machines m ON m.id=b.machine_id
                      WHERE b.id=?");
$st->execute([$id]);
$B = $st->fetch(PDO::FETCH_ASSOC);
if (!$B) { http_response_code(404); exit('Not found'); }

$PAGE_TITLE = "Breakdown #".$id;
$ACTIVE_MENU = 'machines.list';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0"><?=htmlspecialchars($PAGE_TITLE)?></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-light btn-sm" href="/machines/machines_list.php">Machines</a>
    <a class="btn btn-outline-primary btn-sm" href="/maintenance/wo_form.php?machine_id=<?=$B['machine_id']?>&title=Breakdown%20WO%20for%20<?=urlencode($B['machine_code'])?>">Create WO</a>
  </div>
</div>

<?php if ($ok): ?><div class="alert alert-success">Breakdown recorded.</div><?php endif; ?>

<div class="card shadow-sm">
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-4">Machine</dt><dd class="col-8"><?=htmlspecialchars($B['machine_code'].' — '.$B['machine_name'])?></dd>
      <dt class="col-4">Reported At</dt><dd class="col-8"><?=htmlspecialchars((string)$B['reported_at'])?></dd>
      <dt class="col-4">Symptom</dt><dd class="col-8"><?=htmlspecialchars((string)$B['symptom'])?></dd>
      <dt class="col-4">Severity</dt><dd class="col-8"><span class="badge bg-danger"><?=htmlspecialchars((string)$B['severity'])?></span></dd>
      <dt class="col-4">Status</dt><dd class="col-8"><span class="badge bg-secondary"><?=htmlspecialchars((string)$B['status'])?></span></dd>
    </dl>
  </div>
</div>
<?php include __DIR__ . '/../ui/layout_end.php';