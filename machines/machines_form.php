<?php
declare(strict_types=1);
/** PATH: /public_html/machines/machines_form.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();
require_permission('machines.manage');

$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$editing = $id > 0;

/* Load dropdowns */
$cats = $pdo->query("SELECT id, CONCAT(prefix,' - ',name) AS label FROM machine_categories ORDER BY prefix, name")
            ->fetchAll(PDO::FETCH_KEY_PAIR);

/* Defaults */
$row = [
  'machine_id'=>'',
  'name'=>'',
  'category_id'=>0,
  'type_id'=>0,
  'make'=>'','model'=>'','serial_no'=>'','reg_no'=>'',
  'purchase_year'=>null,'purchase_date'=>null,'purchase_price'=>null,
  'warranty_months'=>0,
  'meter_type'=>'hours','current_meter'=>0,'current_meter_at'=>null,
  'calibration_required'=>0,'last_calibration_date'=>null,'next_calibration_due'=>null,
  'status'=>'active','notes'=>''
];
$contacts = [];

/* Editing load */
if ($editing) {
  $st = $pdo->prepare("SELECT * FROM machines WHERE id=?");
  $st->execute([$id]);
  if ($dbRow = $st->fetch(PDO::FETCH_ASSOC)) {
    $row = array_merge($row, $dbRow);
  }
  $cst = $pdo->prepare("SELECT contact_name, phone, alt_phone, email FROM machine_contacts WHERE machine_id=? ORDER BY id");
  $cst->execute([$id]);
  $contacts = $cst->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$err = '';
$val = fn($k,$d='') => htmlspecialchars((string)($row[$k] ?? $d), ENT_QUOTES);

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_require_token();
  // core
  $machine_id  = trim((string)($_POST['machine_id'] ?? ''));
  $name        = trim((string)($_POST['name'] ?? ''));
  $category_id = (int)($_POST['category_id'] ?? 0);
  $type_id     = (int)($_POST['type_id'] ?? 0);

  $make      = trim((string)($_POST['make'] ?? ''));
  $model     = trim((string)($_POST['model'] ?? ''));
  $serial_no = trim((string)($_POST['serial_no'] ?? ''));
  $reg_no    = trim((string)($_POST['reg_no'] ?? ''));

  $purchase_year  = ($_POST['purchase_year'] ?? '') !== '' ? (int)$_POST['purchase_year'] : null;
  $purchase_date  = $_POST['purchase_date']  ?? null;
  $purchase_price = ($_POST['purchase_price'] ?? '') !== '' ? (float)$_POST['purchase_price'] : null;

  $warranty_months = (int)($_POST['warranty_months'] ?? 0);

  $meter_type       = (string)($_POST['meter_type'] ?? 'hours'); // hours|km|none
  $current_meter    = ($_POST['current_meter'] ?? '') !== '' ? (float)$_POST['current_meter'] : 0;
  $current_meter_at = $_POST['current_meter_at'] ?? null;

  $calibration_required  = isset($_POST['calibration_required']) ? 1 : 0;
  $last_calibration_date = $_POST['last_calibration_date'] ?? null;
  $next_calibration_due  = $_POST['next_calibration_due']  ?? null;

  $status = (string)($_POST['status'] ?? 'active');
  $notes  = trim((string)($_POST['notes'] ?? ''));

  // contacts arrays (safe)
  $c_name  = (array)($_POST['contact_name']      ?? []);
  $c_phone = (array)($_POST['contact_phone']     ?? []);
  $c_alt   = (array)($_POST['contact_alt_phone'] ?? []);
  $c_mail  = (array)($_POST['contact_email']     ?? []);

  // validate
  $errors = [];
  if ($name === '')        $errors[] = 'Machine Name is required.';
  if ($category_id <= 0)   $errors[] = 'Category is required.';
  if ($type_id <= 0)       $errors[] = 'Type is required.';
  if ($machine_id === '' || strtoupper($machine_id) === 'ERR' ||
      !preg_match('/^[A-Z0-9]{1,8}-[A-Z]{2,4}-\d{3}$/', $machine_id)) {
    $errors[] = 'Please click Auto to generate a valid Machine ID (CAT-COD-001).';
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      if ($editing) {
        $sql = "UPDATE machines SET
                  machine_id=?, name=?, category_id=?, type_id=?, make=?, model=?, serial_no=?, reg_no=?,
                  purchase_year=?, purchase_date=?, purchase_price=?, warranty_months=?,
                  meter_type=?, current_meter=?, current_meter_at=?,
                  calibration_required=?, last_calibration_date=?, next_calibration_due=?,
                  status=?, notes=?, updated_at=NOW()
                WHERE id=?";
        $pdo->prepare($sql)->execute([
          $machine_id, $name, $category_id, $type_id, $make, $model, $serial_no, $reg_no,
          $purchase_year, $purchase_date, $purchase_price, $warranty_months,
          $meter_type, $current_meter, $current_meter_at,
          $calibration_required, $last_calibration_date, $next_calibration_due,
          $status, $notes, $id
        ]);

        // replace contacts
        $pdo->prepare("DELETE FROM machine_contacts WHERE machine_id=?")->execute([$id]);
        $mid = $id;
      } else {
        $sql = "INSERT INTO machines
                (machine_id,name,category_id,type_id,make,model,serial_no,reg_no,
                 purchase_year,purchase_date,purchase_price,warranty_months,
                 meter_type,current_meter,current_meter_at,
                 calibration_required,last_calibration_date,next_calibration_due,
                 status,notes,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
        $pdo->prepare($sql)->execute([
          $machine_id,$name,$category_id,$type_id,$make,$model,$serial_no,$reg_no,
          $purchase_year,$purchase_date,$purchase_price,$warranty_months,
          $meter_type,$current_meter,$current_meter_at,
          $calibration_required,$last_calibration_date,$next_calibration_due,
          $status,$notes
        ]);
        $mid = (int)$pdo->lastInsertId();
      }

      // insert contacts
      if ($c_name || $c_phone || $c_mail) {
        $ins = $pdo->prepare("INSERT INTO machine_contacts (machine_id,contact_name,phone,alt_phone,email) VALUES (?,?,?,?,?)");
        $rows = max(count($c_name),count($c_phone),count($c_alt),count($c_mail));
        for ($i=0; $i<$rows; $i++) {
          $nm = trim((string)($c_name[$i]  ?? ''));
          $ph = trim((string)($c_phone[$i] ?? ''));
          $ap = trim((string)($c_alt[$i]   ?? ''));
          $em = trim((string)($c_mail[$i]  ?? ''));
          if ($nm==='' && $ph==='' && $em==='') continue;
          $ins->execute([$mid,$nm,$ph,$ap,$em]);
        }
      }

      $pdo->commit();
      header("Location: machines_view.php?id=".$mid);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = 'Error saving machine: '.$e->getMessage();
    }
  } else {
    $err = implode('<br>', $errors);
  }

  // keep values after validation error
  $row = array_merge($row, [
    'machine_id'=>$machine_id,'name'=>$name,'category_id'=>$category_id,'type_id'=>$type_id,
    'make'=>$make,'model'=>$model,'serial_no'=>$serial_no,'reg_no'=>$reg_no,
    'purchase_year'=>$purchase_year,'purchase_date'=>$purchase_date,'purchase_price'=>$purchase_price,
    'warranty_months'=>$warranty_months,'meter_type'=>$meter_type,'current_meter'=>$current_meter,
    'current_meter_at'=>$current_meter_at,'calibration_required'=>$calibration_required,
    'last_calibration_date'=>$last_calibration_date,'next_calibration_due'=>$next_calibration_due,
    'status'=>$status,'notes'=>$notes
  ]);

  // keep contacts
  $contacts = [];
  $rowsC = max(count($c_name),count($c_phone),count($c_alt),count($c_mail));
  for ($i=0; $i<$rowsC; $i++) {
    $contacts[] = [
      'contact_name' => (string)($c_name[$i]  ?? ''),
      'phone'        => (string)($c_phone[$i] ?? ''),
      'alt_phone'    => (string)($c_alt[$i]   ?? ''),
      'email'        => (string)($c_mail[$i]  ?? ''),
    ];
  }
}

/* Types for selected category (server render only) */
$types = [];
if ((int)$row['category_id'] > 0) {
  $col = $pdo->query("SHOW COLUMNS FROM machine_types LIKE 'machine_code'")->fetch() ? 'machine_code' : 'code';
  $ts = $pdo->prepare("SELECT id, CONCAT($col,' - ',name) AS label FROM machine_types WHERE category_id=? ORDER BY $col");
  $ts->execute([(int)$row['category_id']]);
  $types = $ts->fetchAll(PDO::FETCH_KEY_PAIR);
}

/* UI */
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0"><?= $editing ? 'Edit Machine' : 'Add Machine' ?></h1>
  <div class="d-flex gap-2">
    <?php if ($editing): ?>
      <a class="btn btn-outline-secondary btn-sm" href="/maintenance/breakdown_form.php?machine_id=<?= (int)$id ?>">+ Breakdown</a>
    <?php endif; ?>
    <a class="btn btn-light btn-sm" href="types_list.php">Types</a>
    <a class="btn btn-light btn-sm" href="categories_list.php">Categories</a>
    <a class="btn btn-light btn-sm" href="machines_list.php">Back</a>
  </div>
</div>

<?php if ($err): ?>
  <div class="alert alert-danger"><?= $err ?></div>
<?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_hidden_input() ?>
  <div class="col-md-4">
    <label class="form-label">Category</label>
    <select class="form-select" name="category_id" id="category_id" required>
      <option value="">-- choose --</option>
      <?php foreach ($cats as $cid => $label): ?>
        <option value="<?= $cid ?>" <?= $cid==(int)$row['category_id']?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-4">
    <label class="form-label">Type</label>
    <select class="form-select" name="type_id" id="type_id" required <?= (int)$row['category_id'] ? '' : 'disabled' ?>>
      <option value="">-- choose --</option>
      <?php foreach ($types as $tid => $label): ?>
        <option value="<?= $tid ?>" <?= $tid==(int)$row['type_id']?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-4">
    <label class="form-label">Machine ID</label>
    <div class="input-group">
      <input class="form-control" name="machine_id" id="machine_id" value="<?= $val('machine_id') ?>" maxlength="30" required>
      <button type="button" class="btn btn-outline-secondary" id="btnAuto">Auto</button>
    </div>
    <div class="form-text">Format: CAT-COD-001 (generated from Category + Type)</div>
  </div>

  <div class="col-md-6">
    <label class="form-label">Machine Name</label>
    <input class="form-control" name="name" value="<?= $val('name') ?>" required>
  </div>
  <div class="col-md-3">
    <label class="form-label">Make</label>
    <input class="form-control" name="make" value="<?= $val('make') ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Model</label>
    <input class="form-control" name="model" value="<?= $val('model') ?>">
  </div>

  <div class="col-md-3">
    <label class="form-label">Serial No.</label>
    <input class="form-control" name="serial_no" value="<?= $val('serial_no') ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Reg. No.</label>
    <input class="form-control" name="reg_no" value="<?= $val('reg_no') ?>">
  </div>

  <div class="col-md-2">
    <label class="form-label">Purchase Year</label>
    <input type="number" class="form-control" name="purchase_year" value="<?= $val('purchase_year') ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Purchase Date</label>
    <input type="date" class="form-control" name="purchase_date" value="<?= $val('purchase_date') ?>">
  </div>
  <div class="col-md-4">
    <label class="form-label">Purchase Price</label>
    <input type="number" step="0.01" class="form-control" name="purchase_price" value="<?= $val('purchase_price') ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Warranty (months)</label>
    <input type="number" class="form-control" name="warranty_months" value="<?= $val('warranty_months','0') ?>">
  </div>

  <div class="col-md-3">
    <label class="form-label">Meter Type</label>
    <select class="form-select" name="meter_type">
      <option value="hours" <?= ($row['meter_type'] ?? 'hours')==='hours'?'selected':'' ?>>Hours</option>
      <option value="km"    <?= ($row['meter_type'] ?? '')==='km'?'selected':'' ?>>Kilometers</option>
      <option value="none"  <?= ($row['meter_type'] ?? '')==='none'?'selected':'' ?>>None</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Current Meter</label>
    <input type="number" step="0.01" class="form-control" name="current_meter" value="<?= $val('current_meter','0') ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Current Meter @</label>
    <input type="datetime-local" class="form-control" name="current_meter_at" value="<?= $val('current_meter_at') ?>">
  </div>

  <div class="col-md-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <?php foreach (['active'=>'Active','in_service'=>'In Service','retired'=>'Retired'] as $v=>$lbl): ?>
        <option value="<?= $v ?>" <?= ($row['status'] ?? 'active')===$v?'selected':'' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-12">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="calreq" name="calibration_required" <?= !empty($row['calibration_required'])?'checked':'' ?>>
      <label class="form-check-label" for="calreq">Calibration Required</label>
    </div>
  </div>

  <div class="col-md-3">
    <label class="form-label">Last Calibration</label>
    <input type="date" class="form-control" name="last_calibration_date" value="<?= $val('last_calibration_date') ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Next Calibration Due</label>
    <input type="date" class="form-control" name="next_calibration_due" value="<?= $val('next_calibration_due') ?>">
  </div>

  <div class="col-12">
    <label class="form-label">Notes</label>
    <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars((string)$row['notes']) ?></textarea>
  </div>

  <div class="col-12">
    <div class="d-flex align-items-center justify-content-between">
      <label class="form-label mb-0">Service Contacts</label>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addContactRow()">+ Add contact</button>
    </div>
    <div class="table-responsive mt-2">
      <table class="table table-sm align-middle" id="contactsTable">
        <thead>
          <tr>
            <th style="width:25%">Name</th>
            <th style="width:20%">Phone</th>
            <th style="width:20%">Alt. Phone</th>
            <th style="width:25%">Email</th>
            <th style="width:10%"></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($contacts): foreach ($contacts as $c): ?>
          <tr>
            <td><input class="form-control form-control-sm" name="contact_name[]" value="<?= htmlspecialchars((string)($c['contact_name'] ?? '')) ?>"></td>
            <td><input class="form-control form-control-sm" name="contact_phone[]" value="<?= htmlspecialchars((string)($c['phone'] ?? '')) ?>"></td>
            <td><input class="form-control form-control-sm" name="contact_alt_phone[]" value="<?= htmlspecialchars((string)($c['alt_phone'] ?? '')) ?>"></td>
            <td><input class="form-control form-control-sm" name="contact_email[]" value="<?= htmlspecialchars((string)($c['email'] ?? '')) ?>"></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" onclick="this.closest('tr').remove()">Remove</button></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary">Save</button>
    <a class="btn btn-light" href="machines_list.php">Cancel</a>
  </div>
</form>

<script>
function addContactRow() {
  const tb = document.querySelector('#contactsTable tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input class="form-control form-control-sm" name="contact_name[]"></td>
    <td><input class="form-control form-control-sm" name="contact_phone[]"></td>
    <td><input class="form-control form-control-sm" name="contact_alt_phone[]"></td>
    <td><input class="form-control form-control-sm" name="contact_email[]"></td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" onclick="this.closest('tr').remove()">Remove</button></td>
  `;
  tb.appendChild(tr);
}

const catSel = document.getElementById('category_id');
const typeSel = document.getElementById('type_id');
catSel?.addEventListener('change', async (e) => {
  const cid = e.target.value;
  typeSel.innerHTML = '<option value="">-- choose --</option>';
  typeSel.disabled = true;
  if (!cid) return;
  const r = await fetch('types_by_category.php?category_id='+encodeURIComponent(cid));
  if (!r.ok) return;
  const data = await r.json();
  data.forEach(it => {
    const opt = document.createElement('option');
    opt.value = it.id;
    opt.textContent = it.machine_code + ' - ' + it.name;
    typeSel.appendChild(opt);
  });
  typeSel.disabled = false;
});

document.getElementById('btnAuto')?.addEventListener('click', async () => {
  const tid = typeSel.value;
  if (!tid) { alert('Choose Type first'); return; }
  const btn = document.getElementById('btnAuto');
  btn.disabled = true;
  try {
    const r = await fetch('machine_next_code.php?type_id='+encodeURIComponent(tid));
    const code = (await r.text()).trim();
    if (!code || code.toUpperCase()==='ERR') {
      alert('Could not generate code. Please try again.');
    } else {
      document.getElementById('machine_id').value = code;
    }
  } finally {
    btn.disabled = false;
  }
});
</script>

<?php include __DIR__ . '/../ui/layout_end.php';