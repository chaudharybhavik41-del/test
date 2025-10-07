<?php
/** PATH: /public_html/crm/activities_list.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();
require_permission('crm.activity.view');

$pdo = db();

/* filters */
$q       = trim((string)($_GET['q'] ?? ''));
$type    = trim((string)($_GET['type'] ?? ''));
$status  = trim((string)($_GET['status'] ?? ''));
$owner   = (int)($_GET['owner'] ?? 0);
$lead_id = (int)($_GET['lead_id'] ?? 0);
$from    = trim((string)($_GET['from'] ?? ''));
$to      = trim((string)($_GET['to'] ?? ''));

$where = ["a.deleted_at IS NULL"];
$params = [];

if ($q !== '')        { $where[]="(a.subject LIKE :kw OR a.notes LIKE :kw)"; $params[':kw']="%$q%"; }
if ($type !== '')     { $where[]="a.type = :type";       $params[':type']=$type; }
if ($status !== '')   { $where[]="a.status = :status";   $params[':status']=$status; }
if ($owner > 0)       { $where[]="a.owner_id = :owner";  $params[':owner']=$owner; }
if ($lead_id > 0)     { $where[]="a.lead_id = :lead_id"; $params[':lead_id']=$lead_id; }
if ($from !== '')     { $where[]="a.due_at >= :from";    $params[':from']=$from.' 00:00:00'; }
if ($to !== '')       { $where[]="a.due_at <= :to";      $params[':to']=$to.' 23:59:59'; }

$sql = "
SELECT a.id, a.code, a.type, a.subject, a.due_at, a.status, a.priority,
       a.lead_id, a.account_id, a.contact_id, a.owner_id,
       l.code AS lead_code, l.title AS lead_title,
       p.name AS party_name,
       u.name AS owner_name
FROM crm_activities a
LEFT JOIN crm_leads l ON l.id = a.lead_id
LEFT JOIN parties   p ON p.id = a.account_id
LEFT JOIN users     u ON u.id = a.owner_id
WHERE ".implode(' AND ',$where)."
ORDER BY a.due_at ASC, a.id DESC
LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$UI_PATH = dirname(__DIR__).'/ui';
$PAGE_TITLE='Activities';
$ACTIVE_MENU='crm.activities';
require_once $UI_PATH.'/init.php';
require_once $UI_PATH.'/layout_start.php';

function badge_status(string $s): string {
  $map = ['Open'=>'secondary','In Progress'=>'info','Completed'=>'success','Canceled'=>'dark'];
  $cls = $map[$s] ?? 'light';
  return '<span class="badge bg-'.$cls.'">'.h($s).'</span>';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Activities</h3>
  <div class="btn-group">
    <a class="btn btn-sm btn-outline-secondary" href="/crm/crm_leads_list.php">Leads</a>
    <a class="btn btn-sm btn-primary" href="/crm/activities_edit.php">+ New Activity</a>
  </div>
</div>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><input name="q" class="form-control" placeholder="Search subject/notes" value="<?=h($q)?>"></div>
  <div class="col-auto">
    <select name="type" class="form-select">
      <option value="">All Types</option>
      <?php foreach (['Task','Call','Meeting'] as $t): ?>
        <option value="<?=$t?>" <?=$t===$type?'selected':''?>><?=$t?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <select name="status" class="form-select">
      <option value="">All Status</option>
      <?php foreach (['Open','In Progress','Completed','Canceled'] as $s): ?>
        <option value="<?=$s?>" <?=$s===$status?'selected':''?>><?=$s?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto"><input type="number" name="owner" class="form-control" placeholder="Owner ID" value="<?=$owner?:''?>"></div>
  <div class="col-auto"><input type="number" name="lead_id" class="form-control" placeholder="Lead ID" value="<?=$lead_id?:''?>"></div>
  <div class="col-auto"><input type="date" name="from" class="form-control" value="<?=h($from)?>"></div>
  <div class="col-auto"><input type="date" name="to" class="form-control" value="<?=h($to)?>"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
</form>

<table class="table table-sm table-striped align-middle">
  <thead>
    <tr>
      <th>Code</th>
      <th>Type</th>
      <th>Subject</th>
      <th>Due</th>
      <th>Status</th>
      <th>Priority</th>
      <th>Lead</th>
      <th>Client</th>
      <th>Owner</th>
      <th style="width:170px">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><?=h($r['code'])?></td>
      <td><?=h($r['type'])?></td>
      <td><a href="/crm/activities_edit.php?id=<?=$r['id']?>"><?=h($r['subject'])?></a></td>
      <td><?=h($r['due_at'])?></td>
      <td><?=badge_status($r['status']??'Open')?></td>
      <td><?=h($r['priority'])?></td>
      <td><?=h(trim(($r['lead_code']??'').' '.($r['lead_title']??'')))?></td>
      <td><?=h($r['party_name'] ?? '')?></td>
      <td><?=h($r['owner_name'] ?? '')?></td>
      <td>
        <a class="btn btn-sm btn-outline-primary" href="/crm/activities_edit.php?id=<?=$r['id']?>">Open</a>
        <form action="/crm/activities_save.php" method="post" class="d-inline" onsubmit="return confirm('Mark Completed?');">
          <?=csrf_field()?>
          <input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="quick" value="complete">
          <button class="btn btn-sm btn-success">Complete</button>
        </form>
        <form action="/crm/activities_delete.php" method="post" class="d-inline" onsubmit="return confirm('Delete activity?');">
          <?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>">
          <button class="btn btn-sm btn-outline-danger">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php require_once $UI_PATH.'/layout_end.php';
