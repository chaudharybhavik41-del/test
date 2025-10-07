<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/numbering.php';

$pdo = db();
$inquiry_id = (int)($_GET['id'] ?? 0);

/* Convert to RFQ (reuse if already exists for this inquiry) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['convert_to_rfq'])) {
  // 1) reuse existing
  $ex = $pdo->prepare("SELECT id FROM rfqs WHERE inquiry_id=? LIMIT 1");
  $ex->execute([$inquiry_id]);
  $rfq_id = (int)($ex->fetchColumn() ?: 0);

  if (!$rfq_id) {
    // create new
    $inq = $pdo->prepare("SELECT * FROM inquiries WHERE id=?"); $inq->execute([$inquiry_id]);
    $I = $inq->fetch(); if(!$I) { http_response_code(404); exit('Inquiry not found'); }

    $rfq_no = next_no('RFQ');
    $pdo->prepare("INSERT INTO rfqs (rfq_no, inquiry_id, project_id, status) VALUES (?,?,?, 'draft')")
        ->execute([$rfq_no, $inquiry_id, (int)$I['project_id']]);
    $rfq_id = (int)$pdo->lastInsertId();

    // copy lines once
    $ls = $pdo->prepare("SELECT * FROM inquiry_lines WHERE inquiry_id=? ORDER BY id");
    $ls->execute([$inquiry_id]);
    $ins = $pdo->prepare("INSERT INTO rfq_lines (rfq_id, source_type, source_line_id, item_id, description, qty, qty_uom_id, weight_kg, exp_delivery, project_id)
                          VALUES (?,?,?,?,?,?,?,?,NULL,?)");
    while($r = $ls->fetch()){
      $ins->execute([$rfq_id, $r['source_type'] ?? 'PI', (int)$r['id'], (int)$r['item_id'],
                     (string)($r['description'] ?? ''), $r['qty']!==null?(float)$r['qty']:null,
                     $r['qty_uom_id'] ?? null, $r['weight_kg']!==null?(float)$r['weight_kg']:null,
                     (int)$I['project_id']]);
    }
    // optionally mark inquiry
    $pdo->prepare("UPDATE inquiries SET status='issued' WHERE id=?")->execute([$inquiry_id]);
  }

  header("Location: rfq_form.php?id=".$rfq_id."&from_inq=".$inquiry_id);
  exit;
}

/* View inquiry */
$sth = $pdo->prepare("SELECT * FROM inquiries WHERE id=? LIMIT 1"); $sth->execute([$inquiry_id]);
$inq = $sth->fetch(); if(!$inq){ http_response_code(404); exit('Inquiry not found'); }

$lst = $pdo->prepare("SELECT * FROM inquiry_lines WHERE inquiry_id=? ORDER BY id");
$lst->execute([$inquiry_id]); $lines = $lst->fetchAll();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtn($v,$p=3){ return ($v===null||$v==='')?'—':number_format((float)$v,$p); }

$total_qty=$total_kg=0.0; foreach($lines as $r){ if($r['qty']!==null)$total_qty+=(float)$r['qty']; if($r['weight_kg']!==null)$total_kg+=(float)$r['weight_kg']; }

include __DIR__.'/../ui/layout_start.php'; ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-1">Inquiry: <?= h($inq['inquiry_no'] ?? ('INQ-'.$inq['id'])) ?></h3>
      <div class="text-muted small">Status: <?= h((string)($inq['status'] ?? 'draft')) ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="req_wizard.php">← Back</a>
      <form method="post">
        <input type="hidden" name="convert_to_rfq" value="1">
        <button class="btn btn-primary">Convert to RFQ</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between"><strong>Lines</strong>
      <div class="small text-muted">Total Qty: <?=fmtn($total_qty)?> | Total Weight: <?=fmtn($total_kg)?> kg</div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered table-sm mb-0">
          <thead class="table-light"><tr><th>Item / Description</th><th class="text-end">Qty</th><th class="text-center">UOM</th><th class="text-end">Weight (kg)</th></tr></thead>
          <tbody>
          <?php if(!$lines): ?><tr><td colspan="4" class="text-muted">No lines</td></tr>
          <?php else: foreach($lines as $r): ?>
            <tr>
              <td><?=h($r['description'] ?: ('Item #'.(int)$r['item_id']))?></td>
              <td class="text-end"><?=fmtn($r['qty'])?></td>
              <td class="text-center"><?= isset($r['qty_uom_id'])&&$r['qty_uom_id'] ? h((string)$r['qty_uom_id']) : 'NOS' ?></td>
              <td class="text-end"><?=fmtn($r['weight_kg'])?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/../ui/layout_end.php';