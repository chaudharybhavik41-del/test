<?php
/** PATH: /public_html/maintenance/wo_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/machines_helpers.php';

require_login();
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;
if ($is_edit) require_permission('maintenance.wo.view'); else require_permission('maintenance.wo.manage');

function allocate_wo_code(PDO $pdo): string {
  $year = date('Y');
  $lock = "wo_code_$year";
  $pdo->query("SELECT GET_LOCK('$lock', 10)");
  try {
    $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(wo_code, 10) AS UNSIGNED)) FROM maintenance_work_orders WHERE wo_code LIKE ?");
    $st->execute(["WO-$year-%"]);
    $next = ((int)$st->fetchColumn()) + 1;
    return sprintf("WO-%s-%04d", $year, $next);
  } finally {
    $pdo->query("SELECT RELEASE_LOCK('$lock')");
  }
}
function sum_array_amount(array $arr, string $key): float { $t=0.0; foreach($arr as $r){ $t+=(float)($r[$key]??0);} return $t; }

$machines = $pdo->query("SELECT id, CONCAT(machine_id,' - ',name) AS label FROM machines ORDER BY machine_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$mtypes   = $pdo->query("SELECT id, CONCAT(code,' - ',name) AS label FROM maintenance_types ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR);

$wo = [
  'wo_code'=>'','machine_id'=> (int)($_GET['machine_id'] ?? 0),'interval_id'=>null,
  'title'=> (string)($_GET['title'] ?? ''),'description'=>null,'maintenance_type_id'=>null,'priority'=>'medium',
  'status'=>'open','due_date'=>null,'reported_by'=>null,'down_from'=>null,'up_at'=>null,
  'parts_cost'=>0,'labour_cost_internal'=>0,'labour_cost_vendor'=>0,'misc_cost'=>0,'total_cost'=>0
];
$tasks=[]; $parts=[]; $labour=[];
$interval_id = (int)($_GET['interval_id'] ?? 0);
if (!$is_edit && $interval_id) {
  $st = $pdo->prepare("SELECT mi.*, mp.machine_id FROM maintenance_intervals mi JOIN maintenance_programs mp ON mp.id=mi.program_id WHERE mi.id=?");
  $st->execute([$interval_id]);
  if ($ir=$st->fetch(PDO::FETCH_ASSOC)) {
    $wo['machine_id']=(int)$ir['machine_id']; $wo['interval_id']=(int)$ir['id']; $wo['title']=(string)$ir['title']; $wo['maintenance_type_id']=(int)$ir['maintenance_type_id']; $wo['due_date']=$ir['next_due_date'];
    if (!empty($ir['checklist_json'])) { $chk=json_decode((string)$ir['checklist_json'], true); if (is_array($chk)) foreach($chk as $c) $tasks[]=['task'=>(string)($c['task']??(is_string($c)?$c:'')),'status'=>'pending']; }
    if (!empty($ir['parts_json'])) { $pj=json_decode((string)$ir['parts_json'], true); if (is_array($pj)) foreach($pj as $p) $parts[]=['item_id'=>(int)($p['item_id']??0)?:null,'description'=>(string)($p['description']??''),'qty'=>(float)($p['qty']??0),'uom_id'=>(int)($p['uom_id']??0)?:null,'rate'=>(float)($p['rate']??0),'amount'=>(float)($p['amount']??0)]; }
  }
}
if ($is_edit) {
  $st=$pdo->prepare("SELECT * FROM maintenance_work_orders WHERE id=?"); $st->execute([$id]); $wo=$st->fetch(PDO::FETCH_ASSOC) ?: $wo;
  $tasks=$pdo->query("SELECT * FROM maintenance_wo_tasks WHERE wo_id=$id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
  $parts=$pdo->query("SELECT * FROM maintenance_wo_parts WHERE wo_id=$id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
  $labour=$pdo->query("SELECT * FROM maintenance_wo_labour WHERE wo_id=$id ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}
$errors=[]; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_require_token();
  $wo['machine_id']=(int)($_POST['machine_id'] ?? 0);
  $wo['interval_id']=($_POST['interval_id'] ?? '')!==''?(int)$_POST['interval_id']:null;
  $wo['title']=trim((string)($_POST['title'] ?? ''));
  $wo['description']=trim((string)($_POST['description'] ?? '')) ?: null;
  $wo['maintenance_type_id']=($_POST['maintenance_type_id'] ?? '')!==''?(int)$_POST['maintenance_type_id']:null;
  $wo['priority']=(string)($_POST['priority'] ?? 'medium');
  $wo['status']=(string)($_POST['status'] ?? 'open');
  $wo['due_date']=($_POST['due_date'] ?? '') ?: null;
  $wo['down_from']=($_POST['down_from'] ?? '') ?: null;
  $wo['up_at']=($_POST['up_at'] ?? '') ?: null;

  $tasks = array_values((array)($_POST['tasks'] ?? []));
  $parts = array_values((array)($_POST['parts'] ?? []));
  $labour= array_values((array)($_POST['labour'] ?? []));

  $parts_total = sum_array_amount($parts, 'amount');
  $lab_int=0.0; $lab_vend=0.0;
  foreach ($labour as $l){ $amt=(float)($l['amount']??0); if((string)($l['role_name']??'')==='vendor') $lab_vend+=$amt; else $lab_int+=$amt; }
  $misc=(float)($_POST['misc_cost'] ?? 0);
  $wo['parts_cost']=$parts_total; $wo['labour_cost_internal']=$lab_int; $wo['labour_cost_vendor']=$lab_vend; $wo['misc_cost']=$misc; $wo['total_cost']=$parts_total+$lab_int+$lab_vend+$misc;

  if ($wo['machine_id']<=0) $errors[]='Machine is required';
  if ($wo['title']==='')    $errors[]='Title is required';

  if (!$errors) {
    $pdo->beginTransaction();
    try {
      if ($is_edit) {
        $sql="UPDATE maintenance_work_orders SET title=?, description=?, maintenance_type_id=?, priority=?, status=?, due_date=?, interval_id=?, down_from=?, up_at=?, parts_cost=?, labour_cost_internal=?, labour_cost_vendor=?, misc_cost=?, total_cost=?, updated_at=NOW() WHERE id=?";
        $pdo->prepare($sql)->execute([$wo['title'],$wo['description'],$wo['maintenance_type_id'],$wo['priority'],$wo['status'],$wo['due_date'],$wo['interval_id'],$wo['down_from'],$wo['up_at'],$wo['parts_cost'],$wo['labour_cost_internal'],$wo['labour_cost_vendor'],$wo['misc_cost'],$wo['total_cost'],$id]);
      } else {
        $wo_code = allocate_wo_code($pdo);
        $sql="INSERT INTO maintenance_work_orders (wo_code,machine_id,interval_id,title,description,maintenance_type_id,priority,status,due_date,reported_by,reported_at,down_from,up_at,parts_cost,labour_cost_internal,labour_cost_vendor,misc_cost,total_cost,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $pdo->prepare($sql)->execute([$wo_code,$wo['machine_id'],$wo['interval_id'],$wo['title'],$wo['description'],$wo['maintenance_type_id'],$wo['priority'],$wo['status'],$wo['due_date'],current_user_id(),$wo['down_from'],$wo['up_at'],$wo['parts_cost'],$wo['labour_cost_internal'],$wo['labour_cost_vendor'],$wo['misc_cost'],$wo['total_cost'],current_user_id()]);
        $id=(int)$pdo->lastInsertId(); $is_edit=true;
      }
      $pdo->prepare("DELETE FROM maintenance_wo_tasks WHERE wo_id=?")->execute([$id]);
      if ($tasks) { $ins=$pdo->prepare("INSERT INTO maintenance_wo_tasks (wo_id,task,status) VALUES (?,?,?)"); foreach($tasks as $t){ $task=trim((string)($t['task']??'')); if($task==='') continue; $status=in_array(($t['status']??'pending'),['pending','done'],true)?$t['status']:'pending'; $ins->execute([$id,$task,$status]); } }
      $pdo->prepare("DELETE FROM maintenance_wo_parts WHERE wo_id=?")->execute([$id]);
      if ($parts) { $ins=$pdo->prepare("INSERT INTO maintenance_wo_parts (wo_id,item_id,description,qty,uom_id,rate,amount,source_doc) VALUES (?,?,?,?,?,?,?,?)");
        foreach($parts as $p){ $desc=trim((string)($p['description']??'')); $qty=(float)($p['qty']??0); if($desc==='' && $qty<=0) continue;
          $ins->execute([$id, ($p['item_id']??'')!==''?(int)$p['item_id']:null, $desc, $qty, ($p['uom_id']??'')!==''?(int)$p['uom_id']:null, ($p['rate']??'')!==''?(float)$p['rate']:null, ($p['amount']??'')!==''?(float)$p['amount']:null, trim((string)($p['source_doc']??''))?:null]); } }
      $pdo->prepare("DELETE FROM maintenance_wo_labour WHERE wo_id=?")->execute([$id]);
      if ($labour) { $ins=$pdo->prepare("INSERT INTO maintenance_wo_labour (wo_id,staff_id,role_name,entry_date,hours,rate,amount,shift,notes) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach($labour as $l){ $entry_date=(string)($l['entry_date']??''); if($entry_date==='') continue;
          $ins->execute([$id, ($l['staff_id']??'')!==''?(int)$l['staff_id']:null, trim((string)($l['role_name']??''))?:null, $entry_date, (float)($l['hours']??0), (float)($l['rate']??0), (float)($l['amount']??0), in_array(($l['shift']??null),['A','B','C'],true)?$l['shift']:null, trim((string)($l['notes']??''))?:null]); } }
      $pdo->commit();
      $ok='Saved.';
      $st=$pdo->prepare("SELECT * FROM maintenance_work_orders WHERE id=?"); $st->execute([$id]); $wo=$st->fetch(PDO::FETCH_ASSOC) ?: $wo;
    } catch(Throwable $e){ $pdo->rollBack(); $errors[]='Save failed: '.$e->getMessage(); }
  }
}

$holder = (int)$wo['machine_id']>0 ? machine_current_holder($pdo, (int)$wo['machine_id']) : null;

$PAGE_TITLE = $is_edit ? "Work Order ".($wo['wo_code'] ?? '') : "New Work Order";
$ACTIVE_MENU = 'machines.list';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h5 mb-0"><?=$PAGE_TITLE?></h1>
  <div class="d-flex gap-2">
    <?php if ($holder): ?>
      <span class="badge bg-warning text-dark align-self-center">Held by <?=htmlspecialchars($holder['contractor_code'].' — '.$holder['contractor_name'])?></span>
    <?php endif; ?>
    <div class="btn-group btn-group-sm">
      <?php if ((int)$wo['machine_id']>0): ?>
        <a class="btn btn-outline-secondary" href="/maintenance/programs_list.php?machine_id=<?=$wo['machine_id']?>">Programs</a>
        <a class="btn btn-outline-dark" href="/maintenance/wo_list.php?machine_id=<?=$wo['machine_id']?>">WO List</a>
        <a class="btn btn-outline-danger" href="/maintenance/breakdown_quick_create.php?machine_id=<?=$wo['machine_id']?>&symptom=Breakdown%20reported&severity=high">+ Breakdown</a>
        <?php if ($holder): ?>
          <a class="btn btn-success" href="/maintenance_alloc/allocations_return.php?id=<?=$holder['alloc_id']?>">Return</a>
        <?php else: ?>
          <a class="btn btn-success" href="/maintenance_alloc/allocations_form.php?machine_id=<?=$wo['machine_id']?>">Issue</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <a class="btn btn-light btn-sm" href="/maintenance/wo_list.php?machine_id=<?=(int)$wo['machine_id']?>">Back</a>
    <?php if (!$is_edit && has_permission('maintenance.wo.manage')): ?>
      <button form="woForm" class="btn btn-primary btn-sm">Create</button>
    <?php elseif (has_permission('maintenance.wo.manage')): ?>
      <button form="woForm" class="btn btn-primary btn-sm">Save</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul></div>
<?php elseif ($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>

<form id="woForm" method="post" class="row g-3">
  <?= csrf_hidden_input() ?>
  <div class="col-md-4">
    <label class="form-label">Machine</label>
    <select name="machine_id" class="form-select" required>
      <option value="">— choose —</option>
      <?php foreach ($machines as $mid=>$label): ?>
        <option value="<?=$mid?>" <?= (int)$wo['machine_id']===$mid?'selected':'' ?>><?=htmlspecialchars((string)$label)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Maintenance Type</label>
    <select name="maintenance_type_id" class="form-select">
      <option value="">—</option>
      <?php foreach ($mtypes as $tid=>$label): ?>
        <option value="<?=$tid?>" <?= (int)($wo['maintenance_type_id'] ?? 0)===$tid?'selected':'' ?>><?=htmlspecialchars($label)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Priority</label>
    <select name="priority" class="form-select">
      <?php foreach (['low','medium','high'] as $p): ?>
        <option value="<?=$p?>" <?= ($wo['priority'] ?? 'medium')===$p?'selected':'' ?>><?=ucfirst($p)?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <?php foreach (['open','in_progress','waiting_parts','completed','cancelled'] as $s): ?>
        <option value="<?=$s?>" <?= ($wo['status'] ?? 'open')===$s?'selected':'' ?>><?=ucwords(str_replace('_',' ',$s))?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-8">
    <label class="form-label">Title</label>
    <input name="title" class="form-control" required value="<?=htmlspecialchars((string)$wo['title'])?>">
  </div>
  <div class="col-md-4">
    <label class="form-label">Due Date</label>
    <input type="date" name="due_date" class="form-control" value="<?=htmlspecialchars((string)$wo['due_date'] ?? '')?>">
  </div>

  <div class="col-12">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="3"><?=htmlspecialchars((string)($wo['description'] ?? ''))?></textarea>
  </div>

  <div class="col-md-3">
    <label class="form-label">Linked Interval</label>
    <input name="interval_id" class="form-control" value="<?=htmlspecialchars((string)($wo['interval_id'] ?? ''))?>">
    <div class="form-text">Optional: link to a planned interval ID.</div>
  </div>
  <div class="col-md-3">
    <label class="form-label">Down From</label>
    <input type="datetime-local" name="down_from" class="form-control" value="<?= $wo['down_from'] ? date('Y-m-d\TH:i', strtotime((string)$wo['down_from'])) : '' ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Up At</label>
    <input type="datetime-local" name="up_at" class="form-control" value="<?= $wo['up_at'] ? date('Y-m-d\TH:i', strtotime((string)$wo['up_at'])) : '' ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Misc Cost (₹)</label>
    <input type="number" step="0.01" name="misc_cost" class="form-control" value="<?=htmlspecialchars((string)$wo['misc_cost'])?>">
  </div>

  <hr class="mt-4">

  <h6>Tasks</h6>
  <div id="tasksWrap" class="mb-2">
    <?php foreach ($tasks ?: [['task'=>'','status'=>'pending']] as $i=>$t): ?>
      <div class="row g-2 mb-2">
        <div class="col-md-8"><input class="form-control" name="tasks[<?=$i?>][task]" placeholder="Task…" value="<?=htmlspecialchars((string)($t['task'] ?? ''))?>"></div>
        <div class="col-md-3">
          <select class="form-select" name="tasks[<?=$i?>][status]">
            <?php foreach (['pending','done'] as $ts): ?>
              <option value="<?=$ts?>" <?= (($t['status'] ?? 'pending')===$ts)?'selected':'' ?>><?=ucfirst($ts)?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 text-end">
          <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.row').remove()">×</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addTask()">+ Add Task</button>

  <h6 class="mt-4">Parts</h6>
  <div id="partsWrap" class="mb-2">
    <?php foreach ($parts ?: [['description'=>'','qty'=>'','uom_id'=>'','rate'=>'','amount'=>'']] as $i=>$p): ?>
      <div class="row g-2 mb-2">
        <div class="col-md-5"><input class="form-control" name="parts[<?=$i?>][description]" placeholder="Description" value="<?=htmlspecialchars((string)($p['description'] ?? ''))?>"></div>
        <div class="col-md-2"><input type="number" step="0.001" class="form-control" name="parts[<?=$i?>][qty]" placeholder="Qty" value="<?=htmlspecialchars((string)($p['qty'] ?? ''))?>"></div>
        <div class="col-md-2"><input class="form-control" name="parts[<?=$i?>][uom_id]" placeholder="UOM ID" value="<?=htmlspecialchars((string)($p['uom_id'] ?? ''))?>"></div>
        <div class="col-md-1"><input type="number" step="0.01" class="form-control" name="parts[<?=$i?>][rate]" placeholder="Rate" value="<?=htmlspecialchars((string)($p['rate'] ?? ''))?>"></div>
        <div class="col-md-1"><input type="number" step="0.01" class="form-control" name="parts[<?=$i?>][amount]" placeholder="Amt" value="<?=htmlspecialchars((string)($p['amount'] ?? ''))?>"></div>
        <div class="col-md-1 text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.row').remove()">×</button></div>
      </div>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addPart()">+ Add Part</button>

  <h6 class="mt-4">Labour</h6>
  <div id="labourWrap" class="mb-2">
    <?php foreach ($labour ?: [['entry_date'=>date('Y-m-d'),'hours'=>'','rate'=>'','amount'=>'','role_name'=>'']] as $i=>$l): ?>
      <div class="row g-2 mb-2">
        <div class="col-md-2"><input type="date" class="form-control" name="labour[<?=$i?>][entry_date]" value="<?=htmlspecialchars((string)($l['entry_date'] ?? date('Y-m-d')))?>"></div>
        <div class="col-md-2"><input class="form-control" name="labour[<?=$i?>][role_name]" placeholder="Role/vendor" value="<?=htmlspecialchars((string)($l['role_name'] ?? ''))?>"></div>
        <div class="col-md-2"><input type="number" step="0.01" class="form-control" name="labour[<?=$i?>][hours]" placeholder="Hours" value="<?=htmlspecialchars((string)($l['hours'] ?? ''))?>"></div>
        <div class="col-md-2"><input type="number" step="0.01" class="form-control" name="labour[<?=$i?>][rate]" placeholder="Rate" value="<?=htmlspecialchars((string)($l['rate'] ?? ''))?>"></div>
        <div class="col-md-2"><input type="number" step="0.01" class="form-control" name="labour[<?=$i?>][amount]" placeholder="Amount" value="<?=htmlspecialchars((string)($l['amount'] ?? ''))?>"></div>
        <div class="col-md-1">
          <select class="form-select" name="labour[<?=$i?>][shift]"><option value="">-</option><option<?=($l['shift']??'')==='A'?' selected':''?>>A</option><option<?=($l['shift']??'')==='B'?' selected':''?>>B</option><option<?=($l['shift']??'')==='C'?' selected':''?>>C</option></select>
        </div>
        <div class="col-md-1 text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.row').remove()">×</button></div>
      </div>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLabour()">+ Add Labour</button>

  <div class="col-12 text-end">
    <button class="btn btn-primary"><?= $is_edit ? 'Save' : 'Create' ?></button>
  </div>
</form>

<script>
function addTask(){ const w=document.getElementById('tasksWrap'); const i=w.querySelectorAll('.row').length;
  w.insertAdjacentHTML('beforeend', `<div class="row g-2 mb-2">
    <div class="col-md-8"><input class="form-control" name="tasks[${i}][task]" placeholder="Task…"></div>
    <div class="col-md-3"><select class="form-select" name="tasks[${i}][status]"><option value="pending">Pending</option><option value="done">Done</option></select></div>
    <div class="col-md-1 text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.row').remove()">×</button></div>
  </div>`); }
function addPart(){ const w=document.getElementById('partsWrap'); const i=w.querySelectorAll('.row').length;
  w.insertAdjacentHTML('beforeend', `<div class="row g-2 mb-2">
    <div class="col-md-5"><input class="form-control" name="parts[${i}][description]" placeholder="Description"></div>
    <div class="col-md-2"><input type="number" step="0.001" class="form-control" name="parts[${i}][qty]" placeholder="Qty"></div>
    <div class="col-md-2"><input class="form-control" name="parts[${i}][uom_id]" placeholder="UOM ID"></div>
    <div class="col-md-1"><input type="number" step="0.01" class="form-control" name="parts[${i}][rate]" placeholder="Rate"></div>
    <div class="col-md-1"><input type="number" step="0.01" class="form-control" name="parts[${i}][amount]" placeholder="Amt"></div>
    <div class="col-md-1 text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.row').remove()">×</button></div>
  </div>`); }
function addLabour(){ const w=document.getElementById('labourWrap'); const i=w.querySelectorAll('.row').length;
  const today = new Date().toISOString().slice(0,10);
  w.insertAdjacentHTML('beforeend', `<div class="row g-2 mb-2">
    <div class="col-md-2"><input type="date" class="form-control" name="labour[${i}][entry_date]" value="${today}"></div>
    <div class="col-md-2"><input class="form-control" name="labour[${i}][role_name]" placeholder="Role/vendor"></div>
    <div class="col-md-2"><input type="number" step="0.01" class="form-control" name="labour[${i}][hours]" placeholder="Hours"></div>
    <div class="col-md-2"><input type="number" step="0.01" class="form-control" name="labour[${i}][rate]" placeholder="Rate"></div>
    <div class="col-md-2"><input type="number" step="0.01" class="form-control" name="labour[${i}][amount]" placeholder="Amount"></div>
    <div class="col-md-1"><select class="form-select" name="labour[${i}][shift]"><option value="">-</option><option>A</option><option>B</option><option>C</option></select></div>
    <div class="col-md-1 text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.row').remove()">×</button></div>
  </div>`); }
</script>
<?php include __DIR__ . '/../ui/layout_end.php';