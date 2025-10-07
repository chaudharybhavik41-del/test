<?php
/** PATH: /public_html/crm_leads/convert_to_order.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/numbering.php';

require_login();
require_permission('crm.lead.edit');
require_permission('sales.order.create');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf_or_die();
  $leadId = (int)($_POST['lead_id'] ?? 0);
  if ($leadId <= 0) { set_flash('danger','Invalid lead id'); header('Location: crm_leads_list.php'); exit; }

  $st = $pdo->prepare("SELECT * FROM crm_leads WHERE id=? AND (deleted_at IS NULL) LIMIT 1");
  $st->execute([$leadId]);
  $lead = $st->fetch(PDO::FETCH_ASSOC);
  if (!$lead) { set_flash('danger','Lead not found'); header('Location: crm_leads_list.php'); exit; }

  if (empty($lead['party_id'])) {
    set_flash('danger','Please select a Party on the Lead before conversion.');
    header('Location: crm_leads_form.php?id='.$leadId); exit;
  }

  $order = [
    'code'        => next_no('SO'),
    'lead_id'     => $leadId,
    'account_id'  => (int)$lead['party_id'],
    'contact_id'  => !empty($lead['party_contact_id']) ? (int)$lead['party_contact_id'] : null,
    'status'      => 'Draft',
    'order_date'  => date('Y-m-d'),
    'amount'      => $lead['amount'] ?? null,
    'notes'       => 'Converted from Lead #'.$leadId,
  ];
  $ins = $pdo->prepare("INSERT INTO sales_orders
    (`code`,`lead_id`,`account_id`,`contact_id`,`status`,`order_date`,`amount`,`notes`)
    VALUES (:code,:lead_id,:account_id,:contact_id,:status,:order_date,:amount,:notes)");
  $ins->execute($order);
  $oid = (int)$pdo->lastInsertId();
  audit_log($pdo, 'sales_orders', 'create', $oid, $order);

  set_flash('success', 'Converted to Sales Order '.$order['code'].' (Party reused).');
  header('Location: ../sales_orders/sales_orders_form.php?id='.$oid); exit;
}

require_once __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0">Convert Lead to Sales Order</h1>
  <a href="<?= h('crm_leads_list.php') ?>" class="btn btn-outline-secondary">Back</a>
</div>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="lead_id" value="<?= (int)($_GET['lead_id'] ?? 0) ?>">
  <p>This will create a Sales Order and reuse the same Party & Contact selected in the Lead. Continue?</p>
  <button class="btn btn-primary">Convert</button>
</form>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>