<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/numbering.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
require_permission('project.core.create');
require_permission('sales.order.view');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf_or_die();
  $orderId = (int)($_POST['order_id'] ?? 0);
  $row = $pdo->prepare("SELECT * FROM sales_orders WHERE id=? AND (deleted_at IS NULL) LIMIT 1");
  $row->execute([$orderId]);
  $so = $row->fetch(PDO::FETCH_ASSOC);
  if (!$so) { set_flash('danger','Order not found'); header('Location: sales_orders_list.php'); exit; }

  $proj = [
    'code'              => next_no('PRJ'),
    'sales_order_id'    => $orderId,
    'client_account_id' => (int)$so['account_id'],
    'client_contact_id' => $so['contact_id'] ? (int)$so['contact_id'] : null,
    'name'              => 'Project for '.$so['code'],
    'status'            => 'Planned',
    'start_date'        => date('Y-m-d'),
    'end_date'          => null,
    'notes'             => 'Created from Sales Order '.$so['code'],
  ];
  $st = $pdo->prepare("INSERT INTO projects
    (`code`,`sales_order_id`,`client_account_id`,`client_contact_id`,`name`,`status`,`start_date`,`end_date`,`notes`)
    VALUES (:code,:sales_order_id,:client_account_id,:client_contact_id,:name,:status,:start_date,:end_date,:notes)");
  $st->execute($proj);
  $pid = (int)$pdo->lastInsertId();
  audit_log($pdo, 'projects', 'create', $pid, $proj);

  set_flash('success', 'Project '.$proj['code'].' created with same party/contact.');
  header('Location: ../projects/projects_form.php?id='.$pid);
  exit;
}

require_once __DIR__ . '/../ui/layout_start.php';
?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="order_id" value="<?= (int)($_GET['order_id'] ?? 0) ?>">
  <p>Create a Project for this Sales Order using the same Account & Contact?</p>
  <button class="btn btn-primary">Create Project</button>
</form>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>