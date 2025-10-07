<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/numbering.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

/* Load RFQ */
$sth = $pdo->prepare("SELECT * FROM rfqs WHERE id=? LIMIT 1");
$sth->execute([$id]);
$rfq = $sth->fetch();
if (!$rfq) { http_response_code(404); exit('RFQ not found'); }

/* Lines */
$lst = $pdo->prepare("SELECT * FROM rfq_lines WHERE rfq_id=? ORDER BY id");
$lst->execute([$id]);
$lines = $lst->fetchAll();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtnum($v, int $p=3): string { if ($v===null || $v==='') return '—'; return number_format((float)$v, $p); }

$total_qty = 0.0; $total_kg = 0.0;
foreach ($lines as $r) {
  if ($r['qty'] !== null)       $total_qty += (float)$r['qty'];
  if ($r['weight_kg'] !== null) $total_kg  += (float)$r['weight_kg'];
}

include __DIR__.'/../ui/layout_start.php'; ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-1">RFQ: <?= h($rfq['rfq_no'] ?? ('RFQ-'.$rfq['id'])) ?></h3>
      <div class="text-muted small">
        <?php if (!empty($rfq['project_id'])): ?>Project ID: <?= h((string)$rfq['project_id']) ?> • <?php endif; ?>
        Status: <?= h((string)($rfq['status'] ?? 'draft')) ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="inquiry_build.php?id=<?= (int)($_GET['from_inq'] ?? 0) ?>">← Back</a>
      <a class="btn btn-primary disabled" title="Stub — implement next">Convert to PO</a>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Lines</strong>
      <div class="small text-muted">
        Total Qty: <?= fmtnum($total_qty, 3) ?> &nbsp;|&nbsp; Total Weight: <?= fmtnum($total_kg, 3) ?> kg
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:60%">Item / Description</th>
              <th class="text-end" style="width:12%">Qty</th>
              <th class="text-center" style="width:10%">UOM</th>
              <th class="text-end" style="width:18%">Weight (kg)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$lines): ?>
              <tr><td colspan="4" class="text-muted">No lines.</td></tr>
            <?php else: foreach ($lines as $r): ?>
              <tr>
                <td><?= h($r['description'] ?: ('Item #'.(int)$r['item_id'])) ?></td>
                <td class="text-end"><?= fmtnum($r['qty'], 3) ?></td>
                <td class="text-center"><?= isset($r['qty_uom_id']) && $r['qty_uom_id'] ? h((string)$r['qty_uom_id']) : 'NOS' ?></td>
                <td class="text-end"><?= fmtnum($r['weight_kg'], 3) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/../ui/layout_end.php';