<?php
/** PATH: /public_html/projects/projects_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php'; // if present; safe if missing via function_exists checks
require_permission('project.project.manage');

$pdo = db();

/* -----------------------------
   Helpers for project code
------------------------------*/
function format_project_code(int $year, int $seq): string {
  return sprintf('PA-%04d-%04d', $year, $seq);
}

/**
 * Allocate the next project code (atomic). Called ONLY on INSERT.
 */
function allocate_project_code(PDO $pdo): string {
  $year = (int)date('Y');
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT seq FROM project_sequences WHERE year = ? FOR UPDATE");
    $st->execute([$year]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $seq = $row ? (int)$row['seq'] : 0;
    if (!$row) {
      $pdo->prepare("INSERT INTO project_sequences(year, seq) VALUES(?, 0)")->execute([$year]);
    }

    $seq++;
    $pdo->prepare("UPDATE project_sequences SET seq = ? WHERE year = ?")->execute([$seq, $year]);
    $code = format_project_code($year, $seq);
    $pdo->commit();
    return $code;
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}

/* -----------------------------
   Load dropdown data
------------------------------*/
$types = $pdo->query("SELECT id, name FROM project_types WHERE status='active' ORDER BY name")
             ->fetchAll(PDO::FETCH_ASSOC);

$clients = $pdo->query("SELECT id, CONCAT(code,' - ',name) label FROM parties ORDER BY name")
               ->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   Model load
------------------------------*/
$id = (int)($_GET['id'] ?? 0);

$project = [
  'code'=>'','name'=>'','client_party_id'=>null,'type_id'=>null,'status'=>'planned',
  'start_date'=>null,'end_date'=>null,
  'site_address_line1'=>null,'site_address_line2'=>null,'site_city'=>null,'site_state'=>null,'site_pincode'=>null,
  'site_contact_id'=>null,'site_contact_name'=>null,'site_contact_phone'=>null,'site_contact_email'=>null
];

if ($id) {
  $st = $pdo->prepare("SELECT * FROM projects WHERE id=?");
  $st->execute([$id]);
  if ($row = $st->fetch(PDO::FETCH_ASSOC)) $project = array_merge($project, $row);
}

/* -----------------------------
   Handle POST (save)
------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Collect fields (ignore client-provided code on CREATE to avoid reserving via client)
  $data = [
    'name' => trim($_POST['name'] ?? ''),
    'client_party_id' => (int)($_POST['client_party_id'] ?? 0) ?: null,
    'type_id' => (int)($_POST['type_id'] ?? 0) ?: null,
    'status' => $_POST['status'] ?? 'planned',
    'start_date' => $_POST['start_date'] ?: null,
    'end_date' => $_POST['end_date'] ?: null,
    'site_address_line1' => trim($_POST['site_address_line1'] ?? ''),
    'site_address_line2' => trim($_POST['site_address_line2'] ?? ''),
    'site_city' => trim($_POST['site_city'] ?? ''),
    'site_state' => trim($_POST['site_state'] ?? ''),
    'site_pincode' => trim($_POST['site_pincode'] ?? ''),
    'site_contact_id' => (int)($_POST['site_contact_id'] ?? 0) ?: null,
    'site_contact_name' => trim($_POST['site_contact_name'] ?? ''),
    'site_contact_phone' => trim($_POST['site_contact_phone'] ?? ''),
    'site_contact_email' => trim($_POST['site_contact_email'] ?? ''),
  ];

  if ($id) {
    // UPDATE: keep existing code as-is
    $before = $project;
    $sql = "UPDATE projects SET
            name=?, client_party_id=?, type_id=?, status=?, start_date=?, end_date=?,
            site_address_line1=?, site_address_line2=?, site_city=?, site_state=?, site_pincode=?,
            site_contact_id=?, site_contact_name=?, site_contact_phone=?, site_contact_email=?
            WHERE id=?";
    $pdo->prepare($sql)->execute([
      $data['name'],$data['client_party_id'],$data['type_id'],$data['status'],$data['start_date'],$data['end_date'],
      $data['site_address_line1'],$data['site_address_line2'],$data['site_city'],$data['site_state'],$data['site_pincode'],
      $data['site_contact_id'],$data['site_contact_name'],$data['site_contact_phone'],$data['site_contact_email'],
      $id
    ]);
    if (function_exists('logAuditAction')) {
      logAuditAction('project', $id, 'update', current_user_id(), $before, $data);
    }
  } else {
    // INSERT: allocate code now (single increment on actual save)
    $code = allocate_project_code($pdo);

    $sql = "INSERT INTO projects
            (code,name,client_party_id,type_id,status,start_date,end_date,
             site_address_line1,site_address_line2,site_city,site_state,site_pincode,
             site_contact_id,site_contact_name,site_contact_phone,site_contact_email)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $pdo->prepare($sql)->execute([
      $code,$data['name'],$data['client_party_id'],$data['type_id'],$data['status'],$data['start_date'],$data['end_date'],
      $data['site_address_line1'],$data['site_address_line2'],$data['site_city'],$data['site_state'],$data['site_pincode'],
      $data['site_contact_id'],$data['site_contact_name'],$data['site_contact_phone'],$data['site_contact_email']
    ]);
    $id = (int)$pdo->lastInsertId();

    if (function_exists('logAuditAction')) {
      $after = $data; $after['code'] = $code;
      logAuditAction('project', $id, 'create', current_user_id(), null, $after);
    }
  }

  // Redirect before any output
  header("Location: projects_form.php?id=".$id);
  exit;
}

/* -----------------------------
   Peek next code for new record
------------------------------*/
$peek_code = '';
if (!$id) {
  try {
    $y = (int)date('Y');
    $st = $pdo->prepare("SELECT seq FROM project_sequences WHERE year=?");
    $st->execute([$y]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $nextSeq = $row ? ((int)$row['seq'] + 1) : 1;
    $peek_code = format_project_code($y, $nextSeq);
  } catch (Throwable $e) {
    $peek_code = ''; // silent; UI can fetch via JS too
  }
}

/* -----------------------------
   Render layout
------------------------------*/
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= $id ? "Edit Project" : "New Project" ?></h2>
    <a href="projects_list.php" class="btn btn-outline-secondary">Back</a>
  </div>

  <form method="post" id="projectForm">
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label">Project Code</label>
        <div class="input-group">
          <input type="text" id="code" class="form-control" value="<?= htmlspecialchars($id ? (string)$project['code'] : (string)$peek_code) ?>" readonly>
          <?php if (!$id): ?>
            <button type="button" id="regenCode" class="btn btn-outline-secondary">↻</button>
          <?php endif; ?>
        </div>
        <div class="form-text">Code is suggested and allocated only when you save.</div>
      </div>
      <div class="col-md-8 mb-3">
        <label class="form-label">Project Name</label>
        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($project['name']) ?>" required>
      </div>
    </div>

    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Client (Party)</label>
        <select name="client_party_id" id="client_party_id" class="form-select">
          <option value="">— Select —</option>
          <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)$project['client_party_id']===(int)$c['id'])?'selected':'' ?>>
              <?= htmlspecialchars($c['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Project Type</label>
        <select name="type_id" class="form-select">
          <option value="">— Select —</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= ((int)$project['type_id']===(int)$t['id'])?'selected':'' ?>>
              <?= htmlspecialchars($t['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="row">
      <div class="col-md-3 mb-3">
        <label class="form-label">Start Date</label>
        <input type="date" name="start_date" class="form-control" value="<?= $project['start_date'] ?>">
      </div>
      <div class="col-md-3 mb-3">
        <label class="form-label">End Date</label>
        <input type="date" name="end_date" class="form-control" value="<?= $project['end_date'] ?>">
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach (['planned','active','on-hold','closed'] as $st): ?>
            <option value="<?= $st ?>" <?= $st===$project['status']?'selected':'' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <h5 class="mt-3">Site Address</h5>
    <div class="row">
      <div class="col-md-6 mb-3"><input class="form-control" name="site_address_line1" placeholder="Address line 1" value="<?= htmlspecialchars((string)$project['site_address_line1']) ?>"></div>
      <div class="col-md-6 mb-3"><input class="form-control" name="site_address_line2" placeholder="Address line 2" value="<?= htmlspecialchars((string)$project['site_address_line2']) ?>"></div>
      <div class="col-md-4 mb-3"><input class="form-control" name="site_city" placeholder="City" value="<?= htmlspecialchars((string)$project['site_city']) ?>"></div>
      <div class="col-md-4 mb-3"><input class="form-control" name="site_state" placeholder="State" value="<?= htmlspecialchars((string)$project['site_state']) ?>"></div>
      <div class="col-md-4 mb-3"><input class="form-control" name="site_pincode" placeholder="PIN Code" value="<?= htmlspecialchars((string)$project['site_pincode']) ?>"></div>
    </div>

    <h5 class="mt-3">Site Contact</h5>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label">Pick from Client Contacts</label>
        <select name="site_contact_id" id="site_contact_id" class="form-select">
          <option value="">— None —</option>
        </select>
        <div class="form-text">Change client to refresh contacts</div>
      </div>
      <div class="col-md-6 mb-3">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" id="useManualContact" <?= ($project['site_contact_name']||$project['site_contact_phone']||$project['site_contact_email'])?'checked':'' ?>>
          <label class="form-check-label" for="useManualContact">Enter contact details manually</label>
        </div>
      </div>
    </div>
    <div id="manualContact" class="row <?= ($project['site_contact_name']||$project['site_contact_phone']||$project['site_contact_email'])?'':'d-none' ?>">
      <div class="col-md-4 mb-3"><input class="form-control" name="site_contact_name"  placeholder="Contact name"  value="<?= htmlspecialchars((string)$project['site_contact_name']) ?>"></div>
      <div class="col-md-4 mb-3"><input class="form-control" name="site_contact_phone" placeholder="Phone"         value="<?= htmlspecialchars((string)$project['site_contact_phone']) ?>"></div>
      <div class="col-md-4 mb-3"><input class="form-control" name="site_contact_email" type="email" placeholder="Email" value="<?= htmlspecialchars((string)$project['site_contact_email']) ?>"></div>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-success" type="submit">Save</button>
      <a href="projects_list.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const isEdit    = <?= $id ? 'true' : 'false' ?>;
  const codeEl    = document.getElementById('code');
  const regenBtn  = document.getElementById('regenCode');
  const clientSel = document.getElementById('client_party_id');
  const contactSel= document.getElementById('site_contact_id');
  const manualChk = document.getElementById('useManualContact');
  const manualBox = document.getElementById('manualContact');

  function peekCode() {
    fetch('project_next_code.php?mode=peek')
      .then(r=>r.json()).then(j=>{
        if (j && j.ok && codeEl) codeEl.value = j.code;
      });
  }

  function loadContacts(partyId) {
    contactSel.innerHTML = '<option value="">— None —</option>';
    if (!partyId) return;
    fetch('project_contacts.php?party_id=' + encodeURIComponent(partyId))
      .then(r=>r.json())
      .then(rows => {
        rows.forEach(r => {
          const opt = document.createElement('option');
          opt.value = r.id;
          opt.textContent = r.name + (r.phone ? (' — ' + r.phone) : '');
          contactSel.appendChild(opt);
        });
      });
  }

  if (!isEdit) { peekCode(); }
  regenBtn && regenBtn.addEventListener('click', peekCode);
  clientSel && clientSel.addEventListener('change', ()=>loadContacts(clientSel.value));
  manualChk && manualChk.addEventListener('change', ()=> {
    manualBox.classList.toggle('d-none', !manualChk.checked);
  });

  <?php if ($id && $project['client_party_id']): ?>
    loadContacts(<?= (int)$project['client_party_id'] ?>);
  <?php endif; ?>
});
</script>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
