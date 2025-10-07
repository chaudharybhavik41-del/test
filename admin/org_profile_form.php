<?php
/** PATH: /public_html/admin/org_profile_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
/** Choose the permission name you prefer; keep it consistent */
require_permission('admin.org.edit');

$pdo = db();
/** always single row with id=1; create if missing so UI works first time */
$pdo->exec("INSERT IGNORE INTO org_profile (id, legal_name) VALUES (1, 'Your Company Pvt Ltd')");
$row = $pdo->query("SELECT * FROM org_profile WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/../ui/layout_start.php';
render_flash();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0">Company Profile</h1>
  <a href="<?= h('../dashboard.php') ?>" class="btn btn-outline-secondary">Back</a>
</div>

<form method="post" action="<?= h('org_profile_save.php') ?>" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-md-6">
    <label class="form-label">Legal Name</label>
    <input class="form-control" name="legal_name" value="<?= h((string)($row['legal_name'] ?? '')) ?>" required>
  </div>
  <div class="col-md-6">
    <label class="form-label">GSTIN</label>
    <input class="form-control" name="gstin" value="<?= h((string)($row['gstin'] ?? '')) ?>">
  </div>

  <div class="col-md-6">
    <label class="form-label">Address Line 1</label>
    <input class="form-control" name="address_line1" value="<?= h((string)($row['address_line1'] ?? '')) ?>">
  </div>
  <div class="col-md-6">
    <label class="form-label">Address Line 2</label>
    <input class="form-control" name="address_line2" value="<?= h((string)($row['address_line2'] ?? '')) ?>">
  </div>

  <div class="col-md-4">
    <label class="form-label">City</label>
    <input class="form-control" name="city" value="<?= h((string)($row['city'] ?? '')) ?>">
  </div>
  <div class="col-md-4">
    <label class="form-label">State</label>
    <input class="form-control" name="state" value="<?= h((string)($row['state'] ?? '')) ?>" placeholder="e.g. Maharashtra">
  </div>
  <div class="col-md-2">
    <label class="form-label">State Code</label>
    <input class="form-control" name="state_code" value="<?= h((string)($row['state_code'] ?? '')) ?>" placeholder="e.g. 27">
  </div>
  <div class="col-md-2">
    <label class="form-label">PIN Code</label>
    <input class="form-control" name="pincode" value="<?= h((string)($row['pincode'] ?? '')) ?>">
  </div>

  <div class="col-md-6">
    <label class="form-label">Phone</label>
    <input class="form-control" name="phone" value="<?= h((string)($row['phone'] ?? '')) ?>">
  </div>
  <div class="col-md-6">
    <label class="form-label">Email</label>
    <input type="email" class="form-control" name="email" value="<?= h((string)($row['email'] ?? '')) ?>">
  </div>

  <div class="col-12"><hr></div>
  <div class="col-md-6">
    <label class="form-label">Bank Name</label>
    <input class="form-control" name="bank_name" value="<?= h((string)($row['bank_name'] ?? '')) ?>">
  </div>
  <div class="col-md-6">
    <label class="form-label">Branch</label>
    <input class="form-control" name="bank_branch" value="<?= h((string)($row['bank_branch'] ?? '')) ?>">
  </div>
  <div class="col-md-4">
    <label class="form-label">IFSC</label>
    <input class="form-control" name="bank_ifsc" value="<?= h((string)($row['bank_ifsc'] ?? '')) ?>">
  </div>
  <div class="col-md-8">
    <label class="form-label">Account No.</label>
    <input class="form-control" name="bank_account_no" value="<?= h((string)($row['bank_account_no'] ?? '')) ?>">
  </div>

  <div class="col-12">
    <button class="btn btn-primary">Save Profile</button>
  </div>
</form>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>