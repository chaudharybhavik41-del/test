<?php
/** PATH: /public_html/maintenance_alloc/allocations_return.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();
require_permission('machines.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id=(int)($_GET['id'] ?? 0);
$st=$pdo->prepare("SELECT * FROM machine_allocations WHERE id=?");
$st->execute([$id]);
$A=$st->fetch(PDO::FETCH_ASSOC);
if(!$A || $A['status']!=='issued'){ http_response_code(404); exit('Invalid allocation'); }

$errors=[]; $ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_require_token();
  $ret_date = ($_POST['return_date'] ?? '') ?: date('Y-m-d');
  $meter    = ($_POST['meter_return'] === '' ? null : (float)$_POST['meter_return']);
  $remarks  = trim((string)($_POST['return_remarks'] ?? '')) ?: null;

  $pdo->beginTransaction();
  try{
    $pdo->prepare("UPDATE machine_allocations
                      SET status='returned', return_date=?, meter_return=?, return_remarks=?, updated_at=NOW()
                    WHERE id=?")
        ->execute([$ret_date,$meter,$remarks,$id]);

    $pdo->commit(); $ok='Returned.';
    header("Location: /maintenance_alloc/allocations_list.php");
    exit;
  }catch(Throwable $e){ $pdo->rollBack(); $errors[]='Failed: '.$e->getMessage(); }
}

$PAGE_TITLE='Return '.$A['alloc_code'];
$ACTIVE_MENU='machines.list';
include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0"><?=$PAGE_TITLE?></h1>
  <a class="btn btn-light btn-sm" href="/maintenance_alloc/allocations_list.php">Back</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e){echo '<li>'.htmlspecialchars($e).'</li>';}?></ul></div><?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_hidden_input() ?>
  <div class="col-md-3">
    <label class="form-label">Return Date</label>
    <input type="date" name="return_date" class="form-control" value="<?=date('Y-m-d')?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Meter @ Return</label>
    <input type="number" step="0.01" name="meter_return" class="form-control">
  </div>
  <div class="col-md-6">
    <label class="form-label">Return Remarks</label>
    <input name="return_remarks" class="form-control" maxlength="255">
  </div>
  <div class="col-12 text-end">
    <button class="btn btn-success">Confirm Return</button>
  </div>
</form>
<?php include __DIR__ . '/../ui/layout_end.php';