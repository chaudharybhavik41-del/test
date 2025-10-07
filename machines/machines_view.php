<?php
/** Machinery View (profile)
 * PATH: /public_html/machines/machines_view.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
require_permission('machines.view');

$pdo = db();
csrf_require_token();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
SELECT m.*, c.prefix AS cat_prefix, c.name AS cat_name, t.machine_code, t.name AS type_name
FROM machines m
JOIN machine_categories c ON c.id = m.category_id
JOIN machine_types t ON t.id = m.type_id
WHERE m.id=?");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$m) { http_response_code(404); echo "Not found"; exit; }

// contacts
$cstmt = $pdo->prepare("SELECT contact_name, phone, alt_phone, email, notes FROM machine_contacts WHERE machine_id=? ORDER BY id ASC");
$cstmt->execute([$id]);
$contacts = $cstmt->fetchAll(PDO::FETCH_ASSOC);

// recent meter logs
$ml = $pdo->prepare("SELECT reading, reading_at, source, note FROM machine_meters WHERE machine_id=? ORDER BY reading_at DESC LIMIT 10");
$ml->execute([$id]);
$meters = $ml->fetchAll(PDO::FETCH_ASSOC);

// handle quick meter post
if ($_SERVER['REQUEST_METHOD']==='POST' && has_permission('maintenance.meter.manage')) {
  $reading = (float)($_POST['reading'] ?? 0);
  $reading_at = ($_POST['reading_at'] ?? '') ?: date('Y-m-d H:i:s');
  $note = trim($_POST['note'] ?? '');
  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare("INSERT INTO machine_meters(machine_id,reading,reading_at,source,note,created_by) VALUES(?,?,?,?,?,?)");
    $ins->execute([$id,$reading,$reading_at,'manual',$note,current_user_id()]);
    // update cached meter
    $pdo->prepare("UPDATE machines SET current_meter=?, current_meter_at=? WHERE id=?")
        ->execute([$reading,$reading_at,$id]);
    $pdo->commit();
    header("Location: machines_view.php?id=".$id);
    exit;
  } catch (Throwable $e) {
    $pdo->rollBack();
    $err = $e->getMessage();
  }
}

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0">Machine: <?=htmlspecialchars($m['machine_id'])?></h1>
  <div class="d-flex gap-2">
    <?php if (has_permission('machines.manage')): ?>
      <a href="machines_form.php?id=<?=$m['id']?>" class="btn btn-outline-secondary btn-sm">Edit</a>
    <?php endif; ?>
    <a href="machines_list.php" class="btn btn-light btn-sm">Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <div class="fw-semibold"><?=htmlspecialchars($m['name'])?></div>
            <div class="text-muted">
              <?=htmlspecialchars($m['cat_prefix'])?>-<?=htmlspecialchars($m['machine_code'])?> &middot;
              <?=htmlspecialchars($m['cat_name'])?> / <?=htmlspecialchars($m['type_name'])?>
            </div>
          </div>
          <div>
            <span class="badge text-bg-<?= $m['status']==='active'?'success':($m['status']==='in_service'?'warning':'secondary') ?>">
              <?=htmlspecialchars($m['status'])?>
            </span>
          </div>
        </div>

        <hr>

        <div class="row small">
          <div class="col-md-6">
            <div><strong>Make/Model:</strong> <?=htmlspecialchars(trim(($m['make']??'').' '.$m['model']))?></div>
            <div><strong>Serial:</strong> <?=htmlspecialchars((string)$m['serial_no'])?> &nbsp; <strong>Reg:</strong> <?=htmlspecialchars((string)$m['reg_no'])?></div>
            <div><strong>Purchase:</strong> <?=htmlspecialchars((string)$m['purchase_year'])?> &nbsp; ₹<?=number_format((float)$m['purchase_price'],2)?></div>
          </div>
          <div class="col-md-6">
            <div><strong>Meter:</strong> <?=htmlspecialchars($m['meter_type'])?> → <?=number_format((float)$m['current_meter'],2)?> <small class="text-muted">(as of <?= $m['current_meter_at'] ? date('d-M-Y H:i', strtotime($m['current_meter_at'])) : '-' ?>)</small></div>
            <?php
              $w = '';
              if (!empty($m['warranty_months']) && !empty($m['purchase_date'])) {
                $wEnd = (new DateTime($m['purchase_date']))->modify('+'.$m['warranty_months'].' months');
                $w = ($wEnd >= new DateTime()) ? '<span class="badge text-bg-info">Under Warranty till '.$wEnd->format('d-M-Y').'</span>' : '<span class="badge text-bg-secondary">Warranty over</span>';
              }
              echo $w ? "<div>$w</div>" : '';
            ?>
            <?php
              $cal = '';
              if (!empty($m['next_calibration_due'])) {
                $due = new DateTime($m['next_calibration_due']);
                $diff = (int)(new DateTime())->diff($due)->format('%r%a');
                if ($diff < 0) $cal = '<span class="badge text-bg-danger">Calibration Overdue</span>';
                elseif ($diff <= 7) $cal = '<span class="badge text-bg-warning">Calibration Due Soon</span>';
                else $cal = '<span class="badge text-bg-success">Calibration OK</span>';
              }
              echo $cal ? "<div>$cal</div>" : '';
            ?>
          </div>
        </div>

        <hr>

        <div class="row">
          <div class="col-md-6">
            <h6>Service Contacts</h6>
            <?php if ($contacts): ?>
              <ul class="list-unstyled small mb-0">
                <?php foreach ($contacts as $c): ?>
                  <li class="mb-1">
                    <strong><?=htmlspecialchars((string)$c['contact_name'])?></strong> —
                    <span class="text-muted"><?=htmlspecialchars((string)$c['phone'])?><?= $c['alt_phone']? ' / '.htmlspecialchars((string)$c['alt_phone']) : ''?><?= $c['email']? ' · '.htmlspecialchars((string)$c['email']) : ''?></span>
                    <?php if (!empty($c['notes'])): ?><div class="text-muted"><?=htmlspecialchars((string)$c['notes'])?></div><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-muted small">No contacts added.</div>
            <?php endif; ?>
          </div>

          <div class="col-md-6">
            <div class="d-flex align-items-center justify-content-between">
              <h6 class="mb-0">Quick Meter Log</h6>
              <?php if (!has_permission('maintenance.meter.manage')): ?>
                <span class="badge text-bg-light">View only</span>
              <?php endif; ?>
            </div>
            <?php if (isset($err)): ?><div class="alert alert-danger mt-2"><?=htmlspecialchars($err)?></div><?php endif; ?>
            <form method="post" class="row g-2 mt-2" <?php if(!has_permission('maintenance.meter.manage')) echo 'disabled'; ?>
  <?= csrf_hidden_input() ?>>
              <div class="col-6">
                <input type="number" step="0.01" name="reading" class="form-control" placeholder="Meter reading">
              </div>
              <div class="col-6">
                <input type="datetime-local" name="reading_at" class="form-control" value="<?=date('Y-m-d\TH:i')?>">
              </div>
              <div class="col-12">
                <input name="note" class="form-control" placeholder="Note (optional)">
              </div>
              <div class="col-12">
                <button class="btn btn-sm btn-primary" <?=!has_permission('maintenance.meter.manage')?'disabled':''?>>Save</button>
              </div>
            </form>

            <div class="mt-3">
              <div class="small text-muted mb-1">Recent readings</div>
              <ul class="list-group list-group-flush small">
                <?php foreach ($meters as $mr): ?>
                  <li class="list-group-item px-0">
                    <?=htmlspecialchars((string)$mr['reading'])?> @ <?=date('d-M-Y H:i', strtotime($mr['reading_at']))?> <span class="text-muted">[<?=htmlspecialchars((string)$mr['source'])?>]</span>
                    <?php if ($mr['note']): ?> — <span class="text-muted"><?=htmlspecialchars((string)$mr['note'])?></span><?php endif; ?>
                  </li>
                <?php endforeach; ?>
                <?php if (!$meters): ?><li class="list-group-item px-0 text-muted">No readings</li><?php endif; ?>
              </ul>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-body">
        <h6 class="mb-2">Attachments</h6>
        <iframe src="../attachments/panel.php?entity=machines&id=<?=$m['id']?>" style="width:100%;height:320px;border:0;"></iframe>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h6 class="mb-2">Actions</h6>
        <div class="d-grid gap-2">
          <a href="../maintenance/plan_list.php?machine_id=<?=$m['id']?>" class="btn btn-outline-primary btn-sm">View Maintenance Plans</a>
          <a href="../maintenance/wo_form.php?machine_id=<?=$m['id']?>" class="btn btn-outline-secondary btn-sm">Create Work Order</a>
          <a href="../maintenance/breakdown_form.php?machine_id=<?=$m['id']?>" class="btn btn-outline-danger btn-sm">Report Breakdown</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>