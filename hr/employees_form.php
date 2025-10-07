<?php
/** PATH: /public_html/hr/employees_form.php (enhanced) */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('hr.employee.manage');

$PAGE_TITLE  = 'Employee Profile';
$ACTIVE_MENU = 'hr.employees';

/* ---------- Layout start ---------- */
$LAYOUT_DIR = null;
foreach ([__DIR__ . '/../ui', dirname(__DIR__) . '/ui', $_SERVER['DOCUMENT_ROOT'] . '/ui'] as $dir) {
  if (is_dir($dir)) { $LAYOUT_DIR = rtrim($dir, '/'); break; }
}
if ($LAYOUT_DIR) include $LAYOUT_DIR . '/layout_start.php';
else echo '<!doctype html><html><head><meta charset="utf-8"><title>'.$PAGE_TITLE.'</title><link rel="stylesheet" href="/assets/bootstrap.min.css"></head><body><div class="container-fluid">';
/* ---------------------------------- */

$pdo = db();
$id   = isset($_GET['id']) ? (int)$_GET['id'] : null;
$mode = $id ? 'edit' : 'create';

/* ---------- Fetch reference data ---------- */
$depts    = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$managers = $pdo->query("SELECT id, code, first_name, last_name FROM employees ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Defaults ---------- */
$emp = [
  'code'=>'','first_name'=>'','last_name'=>'','email'=>'','phone'=>'',
  'dob'=>null,'gender'=>null,'marital_status'=>null,'blood_group'=>null,
  'dept_id'=>null,'title'=>'','grade_level'=>null,'employment_type'=>'FullTime','location'=>'',
  'manager_employee_id'=>null,'start_date'=>null,'termination_date'=>null,'status'=>'active',
  'aadhaar'=>'','pan'=>'','uan'=>'','esic'=>'','photo_path'=>null
];
$bank = ['bank_name'=>'','branch'=>'','ifsc'=>'','account_no'=>''];
$addr = [
  'current'   => ['line1'=>'','line2'=>'','city'=>'','state'=>'','pincode'=>'','country'=>'India'],
  'permanent' => ['line1'=>'','line2'=>'','city'=>'','state'=>'','pincode'=>'','country'=>'India']
];
$family = []; // [{name,relation,phone,dob,is_emergency}...]

/* ---------- Load existing ---------- */
if ($id) {
  $st = $pdo->prepare("SELECT * FROM employees WHERE id=?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); exit('Employee not found'); }
  $emp = array_merge($emp, $row);

  // Bank primary
  $st = $pdo->prepare("SELECT bank_name,branch,ifsc,account_no FROM employees_bank_accounts WHERE employee_id=? AND is_primary=1 LIMIT 1");
  $st->execute([$id]);
  if ($tmp = $st->fetch(PDO::FETCH_ASSOC)) $bank = array_merge($bank, $tmp);

  // Addresses
  $st = $pdo->prepare("SELECT type,line1,line2,city,state,pincode,country FROM employees_addresses WHERE employee_id=?");
  $st->execute([$id]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $type = $a['type'];
    unset($a['type']);
    if (isset($addr[$type])) $addr[$type] = array_merge($addr[$type], $a);
  }

  // Family contacts
  $st = $pdo->prepare("SELECT name,relation,phone,dob,is_emergency FROM employees_family WHERE employee_id=? ORDER BY id ASC");
  $st->execute([$id]);
  $family = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Collect and validate
  $data = [
    'code' => trim($_POST['code'] ?? ''),
    'first_name' => trim($_POST['first_name'] ?? ''),
    'last_name'  => trim($_POST['last_name'] ?? ''),
    'email' => trim($_POST['email'] ?? ''),
    'phone' => trim($_POST['phone'] ?? ''),
    'dob'   => $_POST['dob'] ?: null,
    'gender'=> $_POST['gender'] ?: null,
    'marital_status' => $_POST['marital_status'] ?: null,
    'blood_group' => $_POST['blood_group'] ?: null,
    'dept_id' => $_POST['dept_id'] !== '' ? (int)$_POST['dept_id'] : null,
    'title' => trim($_POST['title'] ?? ''),
    'grade_level' => $_POST['grade_level'] !== '' ? (int)$_POST['grade_level'] : null,
    'employment_type' => $_POST['employment_type'] ?? 'FullTime',
    'location' => trim($_POST['location'] ?? ''),
    'manager_employee_id' => $_POST['manager_employee_id'] !== '' ? (int)$_POST['manager_employee_id'] : null,
    'start_date' => $_POST['start_date'] ?: null,
    'termination_date' => $_POST['termination_date'] ?: null,
    'status' => $_POST['status'] ?? 'active',
    'aadhaar' => preg_replace('/\D+/', '', $_POST['aadhaar'] ?? ''),
    'pan'     => strtoupper(trim($_POST['pan'] ?? '')),
    'uan'     => preg_replace('/\D+/', '', $_POST['uan'] ?? ''),
    'esic'    => preg_replace('/\s+/', '', $_POST['esic'] ?? ''),
  ];

  $bank = [
    'bank_name' => trim($_POST['bank_name'] ?? ''),
    'branch'    => trim($_POST['bank_branch'] ?? ''),
    'ifsc'      => strtoupper(str_replace(' ', '', $_POST['bank_ifsc'] ?? '')),
    'account_no'=> trim($_POST['bank_account_no'] ?? ''),
  ];

  $addr['current'] = [
    'line1'=>trim($_POST['curr_line1'] ?? ''), 'line2'=>trim($_POST['curr_line2'] ?? ''),
    'city'=>trim($_POST['curr_city'] ?? ''), 'state'=>trim($_POST['curr_state'] ?? ''),
    'pincode'=>trim($_POST['curr_pincode'] ?? ''), 'country'=>trim($_POST['curr_country'] ?? 'India')
  ];
  $addr['permanent'] = [
    'line1'=>trim($_POST['perm_line1'] ?? ''), 'line2'=>trim($_POST['perm_line2'] ?? ''),
    'city'=>trim($_POST['perm_city'] ?? ''), 'state'=>trim($_POST['perm_state'] ?? ''),
    'pincode'=>trim($_POST['perm_pincode'] ?? ''), 'country'=>trim($_POST['perm_country'] ?? 'India')
  ];

  // Family arrays
  $fam_names = $_POST['fam_name'] ?? [];
  $fam_rels  = $_POST['fam_relation'] ?? [];
  $fam_phones= $_POST['fam_phone'] ?? [];
  $fam_dobs  = $_POST['fam_dob'] ?? [];
  $fam_emerg = $_POST['fam_emergency'] ?? []; // indexes checked
  $family = [];
  for ($i=0; $i<count($fam_names); $i++) {
    $n = trim($fam_names[$i] ?? '');
    $r = trim($fam_rels[$i] ?? '');
    if ($n === '' || $r === '') continue;
    $family[] = [
      'name' => $n,
      'relation' => $r,
      'phone' => trim($fam_phones[$i] ?? ''),
      'dob' => ($fam_dobs[$i] ?? '') ?: null,
      'is_emergency' => isset($fam_emerg[$i]) ? 1 : 0,
    ];
  }

  // Minimal validations
  if ($data['code']==='' || $data['first_name']==='' || $data['last_name']==='' || $data['email']==='') {
    $error = 'Code, First name, Last name, Email are required.';
  } elseif ($data['aadhaar'] && strlen($data['aadhaar']) !== 12) {
    $error = 'Aadhaar must be 12 digits.';
  } elseif ($data['pan'] && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $data['pan'])) {
    $error = 'PAN format invalid.';
  } elseif ($bank['ifsc'] && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $bank['ifsc'])) {
    $error = 'IFSC format invalid.';
  } else {
    // Start transaction
    $pdo->beginTransaction();
    try {
      if ($id) {
        $sql = "UPDATE employees SET code=?, first_name=?, last_name=?, email=?, phone=?, dob=?, gender=?, marital_status=?,
                blood_group=?, dept_id=?, title=?, grade_level=?, employment_type=?, location=?, manager_employee_id=?,
                start_date=?, termination_date=?, status=?, aadhaar=?, pan=?, uan=?, esic=?, updated_at=NOW()
                WHERE id=?";
        $pdo->prepare($sql)->execute([
          $data['code'],$data['first_name'],$data['last_name'],$data['email'],$data['phone'],$data['dob'],$data['gender'],$data['marital_status'],
          $data['blood_group'],$data['dept_id'],$data['title'],$data['grade_level'],$data['employment_type'],$data['location'],$data['manager_employee_id'],
          $data['start_date'],$data['termination_date'],$data['status'],$data['aadhaar'],$data['pan'],$data['uan'],$data['esic'], $id
        ]);
      } else {
        $sql = "INSERT INTO employees (code, first_name, last_name, email, phone, dob, gender, marital_status, blood_group, dept_id, title,
                grade_level, employment_type, location, manager_employee_id, start_date, termination_date, status, aadhaar, pan, uan, esic,
                created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
        $pdo->prepare($sql)->execute([
          $data['code'],$data['first_name'],$data['last_name'],$data['email'],$data['phone'],$data['dob'],$data['gender'],$data['marital_status'],$data['blood_group'],
          $data['dept_id'],$data['title'],$data['grade_level'],$data['employment_type'],$data['location'],$data['manager_employee_id'],
          $data['start_date'],$data['termination_date'],$data['status'],$data['aadhaar'],$data['pan'],$data['uan'],$data['esic']
        ]);
        $id = (int)$pdo->lastInsertId();
      }

      // Photo upload
      if (!empty($_FILES['photo']['name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $mime = mime_content_type($_FILES['photo']['tmp_name']);
        if (!isset($allowed[$mime])) throw new RuntimeException('Photo must be JPG/PNG/WebP');
        if ($_FILES['photo']['size'] > 2 * 1024 * 1024) throw new RuntimeException('Photo max 2MB');

        $ext  = $allowed[$mime];
        $root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        $dir  = $root . '/uploads/employees/' . $id;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $path = $dir . '/photo.' . $ext;

        // remove old photo if different ext
        foreach (glob($dir.'/photo.*') as $old) @unlink($old);

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $path)) {
          throw new RuntimeException('Failed to save photo');
        }
        $rel = '/uploads/employees/' . $id . '/photo.' . $ext;
        $pdo->prepare("UPDATE employees SET photo_path=? WHERE id=?")->execute([$rel, $id]);
        $emp['photo_path'] = $rel;
      }

      // Upsert primary bank
      $pdo->prepare("DELETE FROM employees_bank_accounts WHERE employee_id=? AND is_primary=1")->execute([$id]);
      if ($bank['bank_name'] || $bank['account_no'] || $bank['ifsc']) {
        $pdo->prepare("INSERT INTO employees_bank_accounts (employee_id, bank_name, branch, ifsc, account_no, is_primary, created_at, updated_at)
                       VALUES (?,?,?,?,?,1,NOW(),NOW())")
            ->execute([$id, $bank['bank_name'], $bank['branch'], $bank['ifsc'], $bank['account_no']]);
      }

      // Upsert addresses
      $pdo->prepare("DELETE FROM employees_addresses WHERE employee_id=?")->execute([$id]);
      foreach (['current','permanent'] as $t) {
        $a = $addr[$t];
        if ($a['line1'] || $a['city'] || $a['state'] || $a['pincode']) {
          $pdo->prepare("INSERT INTO employees_addresses (employee_id,type,line1,line2,city,state,pincode,country,created_at,updated_at)
                         VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
              ->execute([$id, $t, $a['line1'],$a['line2'],$a['city'],$a['state'],$a['pincode'],$a['country']]);
        }
      }

      // Upsert family
      $pdo->prepare("DELETE FROM employees_family WHERE employee_id=?")->execute([$id]);
      if ($family) {
        $ins = $pdo->prepare("INSERT INTO employees_family (employee_id,name,relation,phone,dob,is_emergency,created_at,updated_at)
                              VALUES (?,?,?,?,?,?,NOW(),NOW())");
        foreach ($family as $f) {
          $ins->execute([$id, $f['name'], $f['relation'], $f['phone'], $f['dob'], (int)$f['is_emergency']]);
        }
      }

      $pdo->commit();
      // Trigger provisioning in-process (shared hosting friendly)
require_once __DIR__ . '/../includes/lib_iam_provisioning.php';
try {
  iam_commit_provision($pdo, (int)$id, (int)(current_user()['id'] ?? 0));
} catch (Throwable $e) {
  // optional: log and continue
  error_log('[auto-provision] '.$e->getMessage());
}

      header('Location: employees_list.php?'.($mode==='edit'?'updated=1':'created=1')); exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      $error = $e->getMessage();
    }
  }
}

/* ---------- View ---------- */
?>
<div class="row">
  <div class="col-xl-10">
    <h1 class="h4 mb-3"><?= $mode==='edit'?'Edit Employee':'Create Employee' ?></h1>
    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card p-3">
      <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-basic" type="button">Basic</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-ids" type="button">IDs</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-bank" type="button">Bank</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-address" type="button">Addresses</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-family" type="button">Family</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-docs" type="button">Documents</button></li>
      </ul>

      <div class="tab-content pt-3">
        <!-- Basic -->
        <div class="tab-pane fade show active" id="tab-basic">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Code *</label>
              <input name="code" class="form-control" value="<?= htmlspecialchars($emp['code']) ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">First name *</label>
              <input name="first_name" class="form-control" value="<?= htmlspecialchars($emp['first_name']) ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Last name *</label>
              <input name="last_name" class="form-control" value="<?= htmlspecialchars($emp['last_name']) ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Email *</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($emp['email']) ?>" required>
            </div>

            <div class="col-md-3">
              <label class="form-label">Phone</label>
              <input name="phone" class="form-control" value="<?= htmlspecialchars($emp['phone'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">DOB</label>
              <input type="date" name="dob" class="form-control" value="<?= htmlspecialchars($emp['dob'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-select">
                <option value="">--</option>
                <?php foreach (['Male','Female','Other'] as $g): ?>
                  <option value="<?= $g ?>" <?= ($emp['gender']===$g?'selected':'') ?>><?= $g ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Marital Status</label>
              <select name="marital_status" class="form-select">
                <option value="">--</option>
                <?php foreach (['Single','Married','Divorced','Widowed'] as $m): ?>
                  <option value="<?= $m ?>" <?= ($emp['marital_status']===$m?'selected':'') ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Blood Group</label>
              <input name="blood_group" class="form-control" placeholder="O+, A-, etc." value="<?= htmlspecialchars($emp['blood_group'] ?? '') ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">Department</label>
              <select name="dept_id" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($depts as $d): ?>
                  <option value="<?= (int)$d['id'] ?>" <?= ($emp['dept_id']==$d['id']?'selected':'') ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Title</label>
              <input name="title" class="form-control" value="<?= htmlspecialchars($emp['title'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Grade Level</label>
              <input type="number" name="grade_level" class="form-control" value="<?= htmlspecialchars((string)($emp['grade_level'] ?? '')) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Employment Type</label>
              <select name="employment_type" class="form-select">
                <?php foreach (['FullTime','PartTime','Contractor','Intern'] as $opt): ?>
                  <option value="<?= $opt ?>" <?= ($emp['employment_type']===$opt?'selected':'') ?>><?= $opt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Location</label>
              <input name="location" class="form-control" value="<?= htmlspecialchars($emp['location'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Manager</label>
              <select name="manager_employee_id" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($managers as $m): ?>
                  <option value="<?= (int)$m['id'] ?>" <?= ($emp['manager_employee_id']==$m['id']?'selected':'') ?>>
                    <?= htmlspecialchars(($m['code'] ?? '').' '.$m['first_name'].' '.$m['last_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label">Start Date</label>
              <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($emp['start_date'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Termination Date</label>
              <input type="date" name="termination_date" class="form-control" value="<?= htmlspecialchars($emp['termination_date'] ?? '') ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['active','inactive'] as $st): ?>
                  <option value="<?= $st ?>" <?= ($emp['status']===$st?'selected':'') ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label">Photo (JPG/PNG/WebP ≤ 2MB)</label>
              <input type="file" name="photo" accept="image/*" class="form-control">
              <?php if (!empty($emp['photo_path'])): ?>
                <div class="mt-2"><img src="<?= htmlspecialchars($emp['photo_path']) ?>" alt="" style="height:80px;border-radius:6px"></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- IDs -->
        <div class="tab-pane fade" id="tab-ids">
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Aadhaar</label>
              <input name="aadhaar" class="form-control" maxlength="12" value="<?= htmlspecialchars($emp['aadhaar'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">PAN</label>
              <input name="pan" class="form-control" maxlength="10" value="<?= htmlspecialchars($emp['pan'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">UAN (EPF)</label>
              <input name="uan" class="form-control" maxlength="16" value="<?= htmlspecialchars($emp['uan'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">ESIC</label>
              <input name="esic" class="form-control" maxlength="20" value="<?= htmlspecialchars($emp['esic'] ?? '') ?>">
            </div>
          </div>
        </div>
        

        <!-- Bank -->
        <div class="tab-pane fade" id="tab-bank">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Bank Name</label>
              <input name="bank_name" class="form-control" value="<?= htmlspecialchars($bank['bank_name']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Branch</label>
              <input name="bank_branch" class="form-control" value="<?= htmlspecialchars($bank['branch']) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">IFSC</label>
              <input name="bank_ifsc" class="form-control" maxlength="11" value="<?= htmlspecialchars($bank['ifsc']) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label">Account No.</label>
              <input name="bank_account_no" class="form-control" value="<?= htmlspecialchars($bank['account_no']) ?>">
            </div>
          </div>
        </div>

        <!-- Addresses -->
        <div class="tab-pane fade" id="tab-address">
          <h6>Current Address</h6>
          <div class="row g-3">
            <div class="col-md-6"><input name="curr_line1" class="form-control" placeholder="Line 1" value="<?= htmlspecialchars($addr['current']['line1']) ?>"></div>
            <div class="col-md-6"><input name="curr_line2" class="form-control" placeholder="Line 2" value="<?= htmlspecialchars($addr['current']['line2']) ?>"></div>
            <div class="col-md-3"><input name="curr_city" class="form-control" placeholder="City" value="<?= htmlspecialchars($addr['current']['city']) ?>"></div>
            <div class="col-md-3"><input name="curr_state" class="form-control" placeholder="State" value="<?= htmlspecialchars($addr['current']['state']) ?>"></div>
            <div class="col-md-3"><input name="curr_pincode" class="form-control" placeholder="PIN" value="<?= htmlspecialchars($addr['current']['pincode']) ?>"></div>
            <div class="col-md-3"><input name="curr_country" class="form-control" placeholder="Country" value="<?= htmlspecialchars($addr['current']['country']) ?>"></div>
          </div>
          <div class="mt-2">
  <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyAddress()">Copy Current → Permanent</button>
</div>
<script>
function copyAddress() {
  const m = (id) => document.querySelector(`[name="${id}"]`);
  m('perm_line1').value = m('curr_line1').value;
  m('perm_line2').value = m('curr_line2').value;
  m('perm_city').value = m('curr_city').value;
  m('perm_state').value = m('curr_state').value;
  m('perm_pincode').value = m('curr_pincode').value;
  m('perm_country').value = m('curr_country').value || 'India';
}
</script>

          <hr>
          <h6>Permanent Address</h6>
          <div class="row g-3">
            <div class="col-md-6"><input name="perm_line1" class="form-control" placeholder="Line 1" value="<?= htmlspecialchars($addr['permanent']['line1']) ?>"></div>
            <div class="col-md-6"><input name="perm_line2" class="form-control" placeholder="Line 2" value="<?= htmlspecialchars($addr['permanent']['line2']) ?>"></div>
            <div class="col-md-3"><input name="perm_city" class="form-control" placeholder="City" value="<?= htmlspecialchars($addr['permanent']['city']) ?>"></div>
            <div class="col-md-3"><input name="perm_state" class="form-control" placeholder="State" value="<?= htmlspecialchars($addr['permanent']['state']) ?>"></div>
            <div class="col-md-3"><input name="perm_pincode" class="form-control" placeholder="PIN" value="<?= htmlspecialchars($addr['permanent']['pincode']) ?>"></div>
            <div class="col-md-3"><input name="perm_country" class="form-control" placeholder="Country" value="<?= htmlspecialchars($addr['permanent']['country']) ?>"></div>
          </div>
        </div>

        <!-- Family -->
        <div class="tab-pane fade" id="tab-family">
          <div id="famRows">
            <?php if (!$family) $family = [['name'=>'','relation'=>'','phone'=>'','dob'=>null,'is_emergency'=>0]]; ?>
            <?php foreach ($family as $i => $f): ?>
              <div class="row g-2 align-items-end fam-row mb-2">
                <div class="col-md-3">
                  <label class="form-label">Name</label>
                  <input name="fam_name[]" class="form-control" value="<?= htmlspecialchars($f['name']) ?>">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Relation</label>
                  <input name="fam_relation[]" class="form-control" value="<?= htmlspecialchars($f['relation']) ?>">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Phone</label>
                  <input name="fam_phone[]" class="form-control" value="<?= htmlspecialchars($f['phone']) ?>">
                </div>
                <div class="col-md-2">
                  <label class="form-label">DOB</label>
                  <input type="date" name="fam_dob[]" class="form-control" value="<?= htmlspecialchars($f['dob'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                  <div class="form-check mt-4">
                    <input type="checkbox" class="form-check-input" name="fam_emergency[<?= $i ?>]" <?= !empty($f['is_emergency'])?'checked':'' ?>>
                    <label class="form-check-label">Emergency</label>
                  </div>
                </div>
                <div class="col-md-1 text-end">
                  <button type="button" class="btn btn-outline-danger btn-sm mt-4" onclick="removeFam(this)">×</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="addFam()">+ Add Family</button>
        </div>
<!-- Documents -->
<div class="tab-pane fade" id="tab-docs">
  <?php if ($mode !== 'edit'): ?>
    <div class="alert alert-info">Save the employee first, then upload documents.</div>
  <?php else: ?>
    <form class="card card-body mb-3" action="/hr/employee_docs_post.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="employee_id" value="<?= (int)$id ?>">
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select name="doc_type" class="form-select">
            <option value="aadhaar">Aadhaar</option>
            <option value="pan">PAN</option>
            <option value="passbook">Passbook</option>
            <option value="offer_letter">Offer Letter</option>
            <option value="joining_form">Joining Form</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="col-md-7">
          <label class="form-label">File (PDF/JPG/PNG/ZIP ≤ 5MB)</label>
          <input type="file" name="file" class="form-control" required>
        </div>
        <div class="col-md-2 d-grid">
          <button class="btn btn-outline-primary">Upload</button>
        </div>
      </div>
    </form>

    <div id="docList">
      <?php
      $st = $pdo->prepare("SELECT id, doc_type, file_path, original_name, created_at FROM employee_documents WHERE employee_id=? ORDER BY created_at DESC");
      $st->execute([$id]);
      $docs = $st->fetchAll(PDO::FETCH_ASSOC);
      if (!$docs): ?>
        <div class="text-muted">No documents.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>Type</th><th>Name</th><th>File</th><th>Uploaded</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($docs as $d): ?>
              <tr>
                <td><?= htmlspecialchars($d['doc_type']) ?></td>
                <td><?= htmlspecialchars($d['original_name'] ?? '-') ?></td>
                <td><a href="<?= htmlspecialchars($d['file_path']) ?>" target="_blank">Open</a></td>
                <td><?= htmlspecialchars($d['created_at']) ?></td>
                <td>
                  <form method="post" action="/hr/employee_docs_post.php" onsubmit="return confirm('Delete document?')">
                    <input type="hidden" name="employee_id" value="<?= (int)$id ?>">
                    <input type="hidden" name="delete_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-outline-danger btn-sm">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>


      
      </div>

      <div class="mt-3">
        <button class="btn btn-primary">Save</button>
        <a href="employees_list.php" class="btn btn-outline-secondary">Back</a>
      </div>
    </form>
  </div>
</div>

<script>
function addFam() {
  const wrap = document.getElementById('famRows');
  const idx = wrap.querySelectorAll('.fam-row').length;
  const div = document.createElement('div');
  div.className = 'row g-2 align-items-end fam-row mb-2';
  div.innerHTML = `
    <div class="col-md-3"><label class="form-label">Name</label><input name="fam_name[]" class="form-control"></div>
    <div class="col-md-2"><label class="form-label">Relation</label><input name="fam_relation[]" class="form-control"></div>
    <div class="col-md-2"><label class="form-label">Phone</label><input name="fam_phone[]" class="form-control"></div>
    <div class="col-md-2"><label class="form-label">DOB</label><input type="date" name="fam_dob[]" class="form-control"></div>
    <div class="col-md-2"><div class="form-check mt-4">
      <input type="checkbox" class="form-check-input" name="fam_emergency[${idx}]">
      <label class="form-check-label">Emergency</label></div></div>
    <div class="col-md-1 text-end"><button type="button" class="btn btn-outline-danger btn-sm mt-4" onclick="removeFam(this)">×</button></div>`;
  wrap.appendChild(div);
}
function removeFam(btn) {
  const row = btn.closest('.fam-row');
  if (row) row.remove();
}
</script>

<?php
/* ---------- Layout end ---------- */
if (!empty($LAYOUT_DIR) && is_file($LAYOUT_DIR . '/layout_end.php')) include $LAYOUT_DIR . '/layout_end.php';
else echo '</div><script src="/assets/bootstrap.bundle.min.js"></script></body></html>';
/* -------------------------------- */
