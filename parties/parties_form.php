<?php
/** PATH: /public_html/parties/parties_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
require_permission('parties.manage');

$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;

$types = ['client','supplier','contractor','other'];

$errors = [];
$party = [
  'code' => '',
  'name' => '',
  'legal_name' => '',
  'type' => 'supplier',
  'contact_name' => '',
  'email' => '',
  'phone' => '',
  'gst_number' => '',
  'gst_state_code' => '',
  'gst_registration_type' => null,
  'pan_number' => '',
  'msme_udyam' => '',
  'address_line1' => '',
  'address_line2' => '',
  'city' => '',
  'state' => '',
  'country' => 'India',
  'pincode' => '',
  'status' => 1,
];

$commercial = [
  'payment_terms_days' => 30,
  'credit_limit' => '0.00',
  'tds_section' => '',
  'tds_rate' => null,
  'tcs_applicable' => 0,
  'e_invoice_required' => 0,
  'reverse_charge_applicable' => 0,
  'default_place_of_supply' => '',
];

$banks = [];
$contacts = [];

// Load existing
if ($isEdit) {
  $st = $pdo->prepare("SELECT * FROM parties WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); echo "Party not found"; exit; }
  $party = array_merge($party, $row);

  // commercial
  $st = $pdo->prepare("SELECT * FROM party_commercials WHERE party_id=? LIMIT 1");
  $st->execute([$id]);
  if ($c = $st->fetch(PDO::FETCH_ASSOC)) $commercial = array_merge($commercial, $c);

  // banks
  $st = $pdo->prepare("SELECT * FROM party_bank_accounts WHERE party_id=? ORDER BY is_primary DESC, id ASC");
  $st->execute([$id]);
  $banks = $st->fetchAll(PDO::FETCH_ASSOC);

  // contacts
  $st = $pdo->prepare("SELECT * FROM party_contacts WHERE party_id=? ORDER BY is_primary DESC, id ASC");
  $st->execute([$id]);
  $contacts = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf_or_die();

  // Party fields
  foreach ($party as $k => $_) {
    if ($k === 'status') continue;
    $party[$k] = trim((string)($_POST[$k] ?? ''));
  }
  $party['status'] = isset($_POST['status']) ? 1 : 0;

  // Validate
  if ($party['name'] === '') $errors['name'] = 'Name is required';
  if (!in_array($party['type'], $types, true)) $errors['type'] = 'Invalid type';
  if ($party['email'] !== '' && !filter_var($party['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Invalid email';
  if ($party['gst_number'] !== '' && !preg_match('/^[0-9A-Z]{15}$/', $party['gst_number'])) $errors['gst_number'] = 'Invalid GSTIN (15 alphanumerics)';

  // Commercials
  $commercial['payment_terms_days'] = (int)($_POST['payment_terms_days'] ?? 30);
  $commercial['credit_limit'] = (string)($_POST['credit_limit'] ?? '0.00');
  $commercial['tds_section'] = trim((string)($_POST['tds_section'] ?? ''));
  $commercial['tds_rate'] = ($_POST['tds_rate'] ?? '') === '' ? null : (string)$_POST['tds_rate'];
  $commercial['tcs_applicable'] = isset($_POST['tcs_applicable']) ? 1 : 0;
  $commercial['e_invoice_required'] = isset($_POST['e_invoice_required']) ? 1 : 0;
  $commercial['reverse_charge_applicable'] = isset($_POST['reverse_charge_applicable']) ? 1 : 0;
  $commercial['default_place_of_supply'] = trim((string)($_POST['default_place_of_supply'] ?? ''));

  // Bank arrays
  $bank_ids        = $_POST['bank_id'] ?? [];
  $beneficiary_name= $_POST['beneficiary_name'] ?? [];
  $bank_name       = $_POST['bank_name'] ?? [];
  $branch          = $_POST['branch'] ?? [];
  $account_number  = $_POST['account_number'] ?? [];
  $ifsc            = $_POST['ifsc'] ?? [];
  $account_type    = $_POST['account_type'] ?? [];
  $bank_is_primary = $_POST['bank_is_primary'] ?? []; // holds indices marked primary (single key)

  // Contact arrays
  $contact_ids         = $_POST['contact_id'] ?? [];
  $contact_name        = $_POST['contact_name'] ?? [];
  $contact_email       = $_POST['contact_email'] ?? [];
  $contact_phone       = $_POST['contact_phone'] ?? [];
  $contact_role_title  = $_POST['contact_role_title'] ?? [];
  $contact_is_primary  = $_POST['contact_is_primary'] ?? []; // holds indices marked primary (single key)

  try {
    if (!$errors) {
      $pdo->beginTransaction();

      // INSERT code generation if needed
      if (!$isEdit && $party['code'] === '') {
        $st = $pdo->prepare("SELECT code_prefix FROM party_type_meta WHERE type=?");
        $st->execute([$party['type']]);
        $prefix = (string)($st->fetchColumn() ?: '');
        if ($prefix === '') {
          $map = ['client'=>'CL','supplier'=>'SP','contractor'=>'CT','other'=>'OT'];
          $prefix = $map[$party['type']] ?? 'PT';
        }
        $scope = 'party:' . $prefix;
        $row = $pdo->prepare("SELECT id, current_value FROM party_sequences WHERE scope=? FOR UPDATE");
        $row->execute([$scope]);
        $seq = $row->fetch(PDO::FETCH_ASSOC);
        if (!$seq) {
          $pdo->prepare("INSERT INTO party_sequences(scope, current_value) VALUES(?, 0)")->execute([$scope]);
          $row->execute([$scope]);
          $seq = $row->fetch(PDO::FETCH_ASSOC);
        }
        $next = (int)$seq['current_value'] + 1;
        $pdo->prepare("UPDATE party_sequences SET current_value=? WHERE id=?")->execute([$next, (int)$seq['id']]);

        $cst = $pdo->prepare("SELECT code FROM parties WHERE code LIKE ? ORDER BY id DESC LIMIT 1");
        $cst->execute([$prefix.'%']);
        $lastCode = (string)($cst->fetchColumn() ?: '');
        $hyphen = $lastCode ? (strpos($lastCode, '-') !== false) : ($party['type']==='client');
        $party['code'] = $prefix . ($hyphen?'-':'') . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
      }

      // upsert party
      if ($isEdit) {
        $sql = "UPDATE parties SET code=?, name=?, legal_name=?, type=?, contact_name=?, email=?, phone=?, 
                gst_number=?, gst_state_code=?, gst_registration_type=?, pan_number=?, msme_udyam=?,
                address_line1=?, address_line2=?, city=?, state=?, country=?, pincode=?, status=? 
                WHERE id=?";
        $pdo->prepare($sql)->execute([
          $party['code'],$party['name'],$party['legal_name'],$party['type'],$party['contact_name'],$party['email'],$party['phone'],
          $party['gst_number'],$party['gst_state_code'],$party['gst_registration_type'],$party['pan_number'],$party['msme_udyam'],
          $party['address_line1'],$party['address_line2'],$party['city'],$party['state'],$party['country'],$party['pincode'],$party['status'],
          $id
        ]);
      } else {
        $sql = "INSERT INTO parties(code,name,legal_name,type,contact_name,email,phone,gst_number,gst_state_code,gst_registration_type,pan_number,msme_udyam,address_line1,address_line2,city,state,country,pincode,status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
          $party['code'],$party['name'],$party['legal_name'],$party['type'],$party['contact_name'],$party['email'],$party['phone'],
          $party['gst_number'],$party['gst_state_code'],$party['gst_registration_type'],$party['pan_number'],$party['msme_udyam'],
          $party['address_line1'],$party['address_line2'],$party['city'],$party['state'],$party['country'],$party['pincode'],$party['status']
        ]);
        $id = (int)$pdo->lastInsertId();
        $isEdit = true;
      }

      // upsert party_commercials (PK party_id)
      $st = $pdo->prepare("SELECT party_id FROM party_commercials WHERE party_id=?");
      $st->execute([$id]);
      if ($st->fetchColumn()) {
        $sql = "UPDATE party_commercials SET payment_terms_days=?, credit_limit=?, tds_section=?, tds_rate=?, 
                tcs_applicable=?, e_invoice_required=?, reverse_charge_applicable=?, default_place_of_supply=?
                WHERE party_id=?";
        $pdo->prepare($sql)->execute([
          $commercial['payment_terms_days'], $commercial['credit_limit'], $commercial['tds_section'], $commercial['tds_rate'],
          $commercial['tcs_applicable'], $commercial['e_invoice_required'], $commercial['reverse_charge_applicable'],
          $commercial['default_place_of_supply'], $id
        ]);
      } else {
        $sql = "INSERT INTO party_commercials(party_id,payment_terms_days,credit_limit,tds_section,tds_rate,tcs_applicable,e_invoice_required,reverse_charge_applicable,default_place_of_supply)
                VALUES (?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
          $id, $commercial['payment_terms_days'], $commercial['credit_limit'], $commercial['tds_section'], $commercial['tds_rate'],
          $commercial['tcs_applicable'], $commercial['e_invoice_required'], $commercial['reverse_charge_applicable'],
          $commercial['default_place_of_supply']
        ]);
      }

      // existing banks for cleanup
      $st = $pdo->prepare("SELECT id FROM party_bank_accounts WHERE party_id=?");
      $st->execute([$id]);
      $existingBankIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));

      $keptBankIds = [];
      $primaryBankIndex = is_array($bank_is_primary) && count($bank_is_primary) ? (int)array_keys($bank_is_primary)[0] : -1;

      foreach ((array)$beneficiary_name as $i => $bn) {
        $rowHasData = trim((string)$bn) !== '' || trim((string)($bank_name[$i] ?? '')) !== '' || trim((string)($account_number[$i] ?? '')) !== '';
        if (!$rowHasData) continue;

        $b = [
          'id' => (int)($bank_ids[$i] ?? 0),
          'beneficiary_name' => trim((string)$bn),
          'bank_name' => trim((string)($bank_name[$i] ?? '')),
          'branch' => trim((string)($branch[$i] ?? '')),
          'account_number' => trim((string)($account_number[$i] ?? '')),
          'ifsc' => trim((string)($ifsc[$i] ?? '')),
          'account_type' => (string)($account_type[$i] ?? 'current'),
          'is_primary' => ($i === $primaryBankIndex) ? 1 : 0,
        ];

        if ($b['id'] > 0) {
          $sql = "UPDATE party_bank_accounts SET beneficiary_name=?, bank_name=?, branch=?, account_number=?, ifsc=?, account_type=?, is_primary=? 
                  WHERE id=? AND party_id=?";
          $pdo->prepare($sql)->execute([
            $b['beneficiary_name'],$b['bank_name'],$b['branch'],$b['account_number'],$b['ifsc'],$b['account_type'],$b['is_primary'],
            $b['id'],$id
          ]);
          $keptBankIds[] = $b['id'];
        } else {
          $sql = "INSERT INTO party_bank_accounts(party_id,beneficiary_name,bank_name,branch,account_number,ifsc,account_type,is_primary)
                  VALUES (?,?,?,?,?,?,?,?)";
          $pdo->prepare($sql)->execute([
            $id,$b['beneficiary_name'],$b['bank_name'],$b['branch'],$b['account_number'],$b['ifsc'],$b['account_type'],$b['is_primary']
          ]);
          $keptBankIds[] = (int)$pdo->lastInsertId();
        }
      }

      // delete removed banks
      $toDelete = array_diff($existingBankIds, $keptBankIds);
      if ($toDelete) {
        $in = implode(',', array_fill(0, count($toDelete), '?'));
        $sql = "DELETE FROM party_bank_accounts WHERE party_id=? AND id IN ($in)";
        $params = array_merge([$id], array_values($toDelete));
        $pdo->prepare($sql)->execute($params);
      }

      // existing contacts for cleanup
      $st = $pdo->prepare("SELECT id FROM party_contacts WHERE party_id=?");
      $st->execute([$id]);
      $existingContactIds = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'id'));

      $keptContactIds = [];
      $primaryContactIndex = is_array($contact_is_primary) && count($contact_is_primary) ? (int)array_keys($contact_is_primary)[0] : -1;

      foreach ((array)$contact_name as $i => $cn) {
        $rowHasData = trim((string)$cn) !== '' || trim((string)($contact_phone[$i] ?? '')) !== '' || trim((string)($contact_email[$i] ?? '')) !== '';
        if (!$rowHasData) continue;

        $c = [
          'id' => (int)($contact_ids[$i] ?? 0),
          'name' => trim((string)$cn),
          'email' => trim((string)($contact_email[$i] ?? '')),
          'phone' => trim((string)($contact_phone[$i] ?? '')),
          'role_title' => trim((string)($contact_role_title[$i] ?? '')),
          'is_primary' => ($i === $primaryContactIndex) ? 1 : 0,
        ];

        if ($c['id'] > 0) {
          $sql = "UPDATE party_contacts SET name=?, email=?, phone=?, role_title=?, is_primary=? WHERE id=? AND party_id=?";
          $pdo->prepare($sql)->execute([
            $c['name'],$c['email'],$c['phone'],$c['role_title'],$c['is_primary'], $c['id'],$id
          ]);
          $keptContactIds[] = $c['id'];
        } else {
          $sql = "INSERT INTO party_contacts(party_id,name,email,phone,role_title,is_primary) VALUES (?,?,?,?,?,?)";
          $pdo->prepare($sql)->execute([$id,$c['name'],$c['email'],$c['phone'],$c['role_title'],$c['is_primary']]);
          $keptContactIds[] = (int)$pdo->lastInsertId();
        }
      }

      // delete removed contacts
      $toDeleteC = array_diff($existingContactIds, $keptContactIds);
      if ($toDeleteC) {
        $in = implode(',', array_fill(0, count($toDeleteC), '?'));
        $sql = "DELETE FROM party_contacts WHERE party_id=? AND id IN ($in)";
        $params = array_merge([$id], array_values($toDeleteC));
        $pdo->prepare($sql)->execute($params);
      }

      // audit (kernel signature: audit_log(PDO $pdo, string $entity, string $action, ?int $row_id, $payload))
      audit_log($pdo, 'parties', $isEdit ? 'update' : 'create', $id, ['party'=>$party,'commercial'=>$commercial]);

      $pdo->commit();
      set_flash('success', 'Saved successfully.');
      header("Location: parties_list.php?saved=1");
      exit;
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errors['db'] = $e->getMessage();
  }
}

$pageTitle = $isEdit ? ('Edit Party: '.h($party['code'] ?: $party['name'])) : 'New Party';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container-fluid py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><?= h($pageTitle) ?></h1>
    <div>
      <a href="parties_list.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <div class="fw-semibold mb-1">Please fix the errors below:</div>
      <ul class="mb-0">
        <?php foreach ($errors as $msg): ?>
          <li><?= h($msg) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="" novalidate>
    <?= csrf_field() ?>

    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-basic" type="button">Basic</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-commercial" type="button">Commercials</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-banks" type="button">Bank Details</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-contacts" type="button">Contacts</button></li>
      <?php if ($isEdit): ?>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-attachments" type="button">Attachments</button></li>
      <?php endif; ?>
    </ul>

    <div class="tab-content border border-top-0 p-3 rounded-bottom shadow-sm">
      <!-- BASIC -->
      <div class="tab-pane fade show active" id="tab-basic">
        <div class="row g-3">
          <div class="col-sm-4">
            <label class="form-label">Type</label>
            <select name="type" class="form-select" required>
              <?php foreach ($types as $t): ?>
                <option value="<?= h($t) ?>" <?= $party['type']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4">
            <label class="form-label">Code</label>
            <div class="input-group">
              <input type="text" name="code" class="form-control" value="<?= h($party['code']) ?>" placeholder="Auto" readonly>
              <button class="btn btn-outline-secondary" type="button" id="btnGen">Auto</button>
            </div>
            <div class="form-text">Generated on save. Use Auto to preview.</div>
          </div>
          <div class="col-sm-8">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= h($party['name']) ?>" required>
          </div>

          <div class="col-sm-8">
            <label class="form-label">Legal Name</label>
            <input type="text" name="legal_name" class="form-control" value="<?= h($party['legal_name']) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label">Contact Person</label>
            <input type="text" name="contact_name" class="form-control" value="<?= h($party['contact_name']) ?>">
          </div>

          <div class="col-sm-4">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($party['email']) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" class="form-control" value="<?= h($party['phone']) ?>">
          </div>

          <div class="col-sm-4">
            <label class="form-label">GSTIN</label>
            <div class="input-group">
              <input type="text" name="gst_number" id="gstin" class="form-control" value="<?= h($party['gst_number']) ?>" maxlength="15" placeholder="27ABCDE1234F1Z5">
              <button class="btn btn-outline-secondary" type="button" id="btnGST">Verify</button>
            </div>
          </div>
          <div class="col-sm-4">
            <label class="form-label">GST State Code</label>
            <input type="text" name="gst_state_code" class="form-control" value="<?= h($party['gst_state_code']) ?>" maxlength="2">
          </div>
          <div class="col-sm-4">
            <label class="form-label">GST Registration</label>
            <select name="gst_registration_type" class="form-select">
              <option value="">—</option>
              <?php foreach (['regular','composition','unregistered','consumer','sez'] as $opt): ?>
                <option value="<?= h($opt) ?>" <?= ($party['gst_registration_type']===$opt)?'selected':'' ?>><?= ucfirst($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-4">
            <label class="form-label">PAN</label>
            <input type="text" name="pan_number" class="form-control" value="<?= h($party['pan_number']) ?>" maxlength="20">
          </div>
          <div class="col-sm-4">
            <label class="form-label">MSME Udyam</label>
            <input type="text" name="msme_udyam" class="form-control" value="<?= h($party['msme_udyam']) ?>">
          </div>

          <div class="col-12"><hr></div>

          <div class="col-sm-8">
            <label class="form-label">Address Line 1</label>
            <input type="text" name="address_line1" class="form-control" value="<?= h($party['address_line1']) ?>">
          </div>
          <div class="col-sm-8">
            <label class="form-label">Address Line 2</label>
            <input type="text" name="address_line2" class="form-control" value="<?= h($party['address_line2']) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label">City</label>
            <input type="text" name="city" class="form-control" value="<?= h($party['city']) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label">State</label>
            <input type="text" name="state" class="form-control" value="<?= h($party['state']) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label">Country</label>
            <input type="text" name="country" class="form-control" value="<?= h($party['country']) ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label">PIN</label>
            <input type="text" name="pincode" class="form-control" value="<?= h($party['pincode']) ?>">
          </div>

          <div class="col-sm-4 form-check mt-4">
            <input type="checkbox" class="form-check-input" id="status" name="status" value="1" <?= ((int)$party['status']===1)?'checked':'' ?>>
            <label class="form-check-label" for="status">Active</label>
          </div>
        </div>
      </div>

      <!-- COMMERCIALS -->
      <div class="tab-pane fade" id="tab-commercial">
        <div class="row g-3">
          <div class="col-sm-3">
            <label class="form-label">Payment Terms (days)</label>
            <input type="number" name="payment_terms_days" class="form-control" value="<?= h((string)$commercial['payment_terms_days']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Credit Limit</label>
            <input type="text" name="credit_limit" class="form-control" value="<?= h((string)$commercial['credit_limit']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">TDS Section</label>
            <input type="text" name="tds_section" class="form-control" value="<?= h((string)$commercial['tds_section']) ?>" placeholder="194C">
          </div>
          <div class="col-sm-3">
            <label class="form-label">TDS Rate (%)</label>
            <input type="text" name="tds_rate" class="form-control" value="<?= h((string)$commercial['tds_rate']) ?>">
          </div>

          <div class="col-sm-3 form-check mt-4">
            <input type="checkbox" class="form-check-input" id="tcs_applicable" name="tcs_applicable" value="1" <?= $commercial['tcs_applicable']? 'checked':'' ?>>
            <label class="form-check-label" for="tcs_applicable">TCS Applicable</label>
          </div>
          <div class="col-sm-3 form-check mt-4">
            <input type="checkbox" class="form-check-input" id="e_invoice_required" name="e_invoice_required" value="1" <?= $commercial['e_invoice_required']? 'checked':'' ?>>
            <label class="form-check-label" for="e_invoice_required">E-Invoice Required</label>
          </div>
          <div class="col-sm-3 form-check mt-4">
            <input type="checkbox" class="form-check-input" id="reverse_charge_applicable" name="reverse_charge_applicable" value="1" <?= $commercial['reverse_charge_applicable']? 'checked':'' ?>>
            <label class="form-check-label" for="reverse_charge_applicable">Reverse Charge</label>
          </div>

          <div class="col-sm-6">
            <label class="form-label">Default Place of Supply</label>
            <input type="text" name="default_place_of_supply" class="form-control" value="<?= h((string)$commercial['default_place_of_supply']) ?>">
          </div>
        </div>
      </div>

      <!-- BANKS -->
      <div class="tab-pane fade" id="tab-banks">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <strong>Bank Accounts</strong>
          <button type="button" class="btn btn-sm btn-outline-primary" id="addBank"><i class="bi bi-plus"></i> Add</button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="bankTable">
            <thead class="table-light">
              <tr>
                <th>Beneficiary</th>
                <th>Bank</th>
                <th>Branch</th>
                <th>Account #</th>
                <th>IFSC</th>
                <th>Type</th>
                <th>Primary</th>
                <th style="width:48px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php $i=0; if ($banks): foreach ($banks as $b): ?>
                <tr>
                  <td>
                    <input type="hidden" name="bank_id[]" value="<?= (int)$b['id'] ?>">
                    <input type="text" name="beneficiary_name[]" class="form-control form-control-sm" value="<?= h((string)$b['beneficiary_name']) ?>">
                  </td>
                  <td><input type="text" name="bank_name[]" class="form-control form-control-sm" value="<?= h((string)$b['bank_name']) ?>"></td>
                  <td><input type="text" name="branch[]" class="form-control form-control-sm" value="<?= h((string)$b['branch']) ?>"></td>
                  <td><input type="text" name="account_number[]" class="form-control form-control-sm" value="<?= h((string)$b['account_number']) ?>"></td>
                  <td><input type="text" name="ifsc[]" class="form-control form-control-sm" value="<?= h((string)$b['ifsc']) ?>"></td>
                  <td>
                    <select name="account_type[]" class="form-select form-select-sm">
                      <?php foreach (['current','savings','overdraft','other'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= ((string)$b['account_type']===$opt?'selected':'') ?>><?= ucfirst($opt) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td class="text-center">
                    <input type="radio" name="bank_is_primary[<?= $i ?>]" class="form-check-input" <?= ((int)$b['is_primary']===1?'checked':'') ?>>
                  </td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
                </tr>
              <?php $i++; endforeach; endif; ?>
              <!-- empty rows via JS -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- CONTACTS -->
      <div class="tab-pane fade" id="tab-contacts">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <strong>Contacts</strong>
          <button type="button" class="btn btn-sm btn-outline-primary" id="addContact"><i class="bi bi-plus"></i> Add</button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="contactTable">
            <thead class="table-light">
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Primary</th>
                <th style="width:48px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php $j=0; if ($contacts): foreach ($contacts as $c): ?>
                <tr>
                  <td>
                    <input type="hidden" name="contact_id[]" value="<?= (int)$c['id'] ?>">
                    <input type="text" name="contact_name[]" class="form-control form-control-sm" value="<?= h((string)$c['name']) ?>">
                  </td>
                  <td><input type="email" name="contact_email[]" class="form-control form-control-sm" value="<?= h((string)$c['email']) ?>"></td>
                  <td><input type="text" name="contact_phone[]" class="form-control form-control-sm" value="<?= h((string)$c['phone']) ?>"></td>
                  <td><input type="text" name="contact_role_title[]" class="form-control form-control-sm" value="<?= h((string)$c['role_title']) ?>"></td>
                  <td class="text-center">
                    <input type="radio" name="contact_is_primary[<?= $j ?>]" class="form-check-input" <?= ((int)$c['is_primary']===1?'checked':'') ?>>
                  </td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
                </tr>
              <?php $j++; endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ATTACHMENTS -->
      <?php if ($isEdit): ?>
      <div class="tab-pane fade" id="tab-attachments">
        <div class="card shadow-sm">
          <div class="card-body">
            <?php @include __DIR__ . '/../attachments/panel.php'; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="d-grid gap-2 d-sm-flex justify-content-end mt-3">
      <button class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
      <a class="btn btn-outline-secondary" href="parties_list.php">Cancel</a>
    </div>
  </form>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>

<script>
/** Auto-code preview */
document.getElementById('btnGen')?.addEventListener('click', async function(){
  const typeSel = document.querySelector('select[name="type"]');
  const type = typeSel ? typeSel.value : '';
  try {
    const res = await fetch('party_next_code.php?type=' + encodeURIComponent(type));
    const js = await res.json();
    if (js.ok) document.querySelector('input[name="code"]').value = js.code;
    else alert(js.message || 'Failed to generate');
  } catch (e) { alert('Network error'); }
});

/** GST helper */
document.getElementById('btnGST')?.addEventListener('click', async function(){
  const gstin = (document.getElementById('gstin')?.value || '').trim().toUpperCase();
  if (!gstin) { alert('Enter GSTIN'); return; }
  try {
    const res = await fetch('party_gst_lookup.php?gstin=' + encodeURIComponent(gstin));
    const js = await res.json();
    if (js.ok) {
      if (js.state_code) document.querySelector('input[name="gst_state_code"]').value = js.state_code;
      if (js.legal_name)  document.querySelector('input[name="legal_name"]').value  = js.legal_name;
    } else alert(js.message || 'GST lookup failed');
  } catch (e) { alert('Network error'); }
});

/** Dynamic rows: Banks */
(function(){
  const tbody = document.querySelector('#bankTable tbody');
  const addBtn = document.getElementById('addBank');
  addBtn?.addEventListener('click', () => {
    const idx = Date.now();
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="hidden" name="bank_id[]" value="0">
          <input type="text" name="beneficiary_name[]" class="form-control form-control-sm"></td>
      <td><input type="text" name="bank_name[]" class="form-control form-control-sm"></td>
      <td><input type="text" name="branch[]" class="form-control form-control-sm"></td>
      <td><input type="text" name="account_number[]" class="form-control form-control-sm"></td>
      <td><input type="text" name="ifsc[]" class="form-control form-control-sm"></td>
      <td>
        <select name="account_type[]" class="form-select form-select-sm">
          <option value="current">Current</option>
          <option value="savings">Savings</option>
          <option value="overdraft">Overdraft</option>
          <option value="other">Other</option>
        </select>
      </td>
      <td class="text-center"><input type="radio" name="bank_is_primary[${idx}]" class="form-check-input"></td>
      <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
    `;
    tbody.appendChild(tr);
  });
  tbody?.addEventListener('click', (e) => {
    if (e.target.closest('.delRow')) e.target.closest('tr').remove();
  });
})();

/** Dynamic rows: Contacts */
(function(){
  const tbody = document.querySelector('#contactTable tbody');
  const addBtn = document.getElementById('addContact');
  addBtn?.addEventListener('click', () => {
    const idx = Date.now();
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="hidden" name="contact_id[]" value="0">
          <input type="text" name="contact_name[]" class="form-control form-control-sm"></td>
      <td><input type="email" name="contact_email[]" class="form-control form-control-sm"></td>
      <td><input type="text" name="contact_phone[]" class="form-control form-control-sm"></td>
      <td><input type="text" name="contact_role_title[]" class="form-control form-control-sm"></td>
      <td class="text-center"><input type="radio" name="contact_is_primary[${idx}]" class="form-check-input"></td>
      <td><button type="button" class="btn btn-sm btn-outline-danger delRow">&times;</button></td>
    `;
    tbody.appendChild(tr);
  });
  tbody?.addEventListener('click', (e) => {
    if (e.target.closest('.delRow')) e.target.closest('tr').remove();
  });
})();
</script>