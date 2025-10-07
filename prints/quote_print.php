<?php
/** PATH: /public_html/prints/quote_print.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/org.php';

require_login();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$ORG = org_profile();
$ORG_NAME       = (string)$ORG['legal_name'];
$ORG_ADDR1      = trim((string)$ORG['address_line1']);
$ORG_ADDR2      = trim(((string)$ORG['city']).' '.((string)$ORG['state']).' '.((string)$ORG['pincode']));
$ORG_GSTIN      = (string)$ORG['gstin'];
$ORG_STATE      = (string)$ORG['state'];
$ORG_STATE_CODE = (string)$ORG['state_code'];

// header with client & site details
$st = $pdo->prepare("
  SELECT Q.*,
         P.name AS party_name, P.legal_name, P.gst_number,
         P.address_line1, P.address_line2, P.city, P.state, P.pincode
  FROM sales_quotes Q
  LEFT JOIN parties P ON P.id=Q.party_id
  WHERE Q.id=?");
$st->execute([$id]);
$h = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// items
$st = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id=? ORDER BY sl_no,id");
$st->execute([$id]);
$it = $st->fetchAll(PDO::FETCH_ASSOC);

// Place of supply: site state if toggled & present, else client state
$useSite = (int)($h['use_site_as_bill_to'] ?? 0) === 1;
$placeState = trim((string)(
  $useSite
    ? ($h['site_state'] ?? '')
    : ($h['state'] ?? '')
));
$split = gst_split((float)($h['tax_amount'] ?? 0), $placeState);
$isIntra = ($split['mode'] === 'intra');
$cgst = $split['cgst']; $sgst = $split['sgst']; $igst = $split['igst'];

// Bill-to vs Other block
$billBlock = [
  'title' => $useSite ? 'Bill To (Site Address / Place of Supply)' : 'Bill To (Client Registered Address)',
  'name'  => $useSite ? ($h['site_name'] ?? '') : ($h['party_name'] ?? ''),
  'addr1' => $useSite ? ($h['site_address_line1'] ?? '') : ($h['address_line1'] ?? ''),
  'addr2' => $useSite ? ($h['site_address_line2'] ?? '') : ($h['address_line2'] ?? ''),
  'city'  => $useSite ? ($h['site_city'] ?? '') : ($h['city'] ?? ''),
  'state' => $useSite ? ($h['site_state'] ?? '') : ($h['state'] ?? ''),
  'pin'   => $useSite ? ($h['site_pincode'] ?? '') : ($h['pincode'] ?? ''),
  'gst'   => $useSite ? ($h['site_gst_number'] ?? '') : ($h['gst_number'] ?? ''),
];
$otherBlock = [
  'title' => $useSite ? 'Client (Registered Address)' : 'Site Address',
  'name'  => $useSite ? ($h['party_name'] ?? '') : ($h['site_name'] ?? ''),
  'addr1' => $useSite ? ($h['address_line1'] ?? '') : ($h['site_address_line1'] ?? ''),
  'addr2' => $useSite ? ($h['address_line2'] ?? '') : ($h['site_address_line2'] ?? ''),
  'city'  => $useSite ? ($h['city'] ?? '') : ($h['site_city'] ?? ''),
  'state' => $useSite ? ($h['state'] ?? '') : ($h['site_state'] ?? ''),
  'pin'   => $useSite ? ($h['pincode'] ?? '') : ($h['site_pincode'] ?? ''),
  'gst'   => $useSite ? ($h['gst_number'] ?? '') : ($h['site_gst_number'] ?? ''),
]
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Quotation <?= h((string)($h['code'] ?? '')) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    @media print {.no-print{display:none}}
    body{padding:20px}
    .table td,.table th{vertical-align:middle}
    .box{border:1px solid #dee2e6; border-radius:6px; padding:12px;}
    .small-muted{font-size:.9rem;color:#6c757d;}
  </style>
</head>
<body>
<div class="no-print mb-3 d-flex gap-2">
  <button class="btn btn-primary" onclick="window.print()">Print</button>
</div>

<!-- Company + Quote meta -->
<div class="d-flex justify-content-between align-items-start">
  <div class="d-flex align-items-start gap-3">
    <div>
      <img src="<?= h('../assets/logo.jpg') ?>" alt="Logo" style="max-height:80px; max-width:160px;">
    </div>
    <div>
      <h1 class="h5 mb-1"><?= h($ORG_NAME) ?></h1>
      <?php if ($ORG_ADDR1): ?><div><?= h($ORG_ADDR1) ?></div><?php endif; ?>
      <?php if ($ORG_ADDR2): ?><div><?= h($ORG_ADDR2) ?></div><?php endif; ?>
      <div>GSTIN: <?= h($ORG_GSTIN ?: '—') ?><?= $ORG_STATE ? ' · State: '.h($ORG_STATE) : '' ?><?= $ORG_STATE_CODE ? ' ('.h($ORG_STATE_CODE).')' : '' ?></div>
      <?php if (!empty($ORG['phone']) || !empty($ORG['email'])): ?>
        <div class="small-muted">
          <?php if (!empty($ORG['phone'])): ?>Phone: <?= h((string)$ORG['phone']) ?><?php endif; ?>
          <?php if (!empty($ORG['phone']) && !empty($ORG['email'])): ?> · <?php endif; ?>
          <?php if (!empty($ORG['email'])): ?>Email: <?= h((string)$ORG['email']) ?><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="text-end">
    <h1 class="h4">QUOTATION</h1>
    <div><strong>No:</strong> <?= h((string)($h['code'] ?? '')) ?></div>
    <div><strong>Date:</strong> <?= h((string)($h['quote_date'] ?? '')) ?></div>
    <?php if (!empty($h['valid_till'])): ?>
      <div><strong>Valid Till:</strong> <?= h((string)$h['valid_till']) ?></div>
    <?php endif; ?>
    <div><strong>Status:</strong> <?= h((string)($h['status'] ?? '')) ?></div>
    <div><strong>Currency:</strong> <?= h((string)($h['currency'] ?? 'INR')) ?></div>
  </div>
</div>

<hr>

<!-- Addresses -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="box">
      <h2 class="h6 mb-2"><?= h($billBlock['title']) ?></h2>
      <div><strong><?= h((string)$billBlock['name']) ?></strong></div>
      <div><?= h((string)$billBlock['addr1']) ?></div>
      <div><?= h((string)$billBlock['addr2']) ?></div>
      <div><?= h((string)$billBlock['city']) ?> <?= h((string)$billBlock['state']) ?> <?= h((string)$billBlock['pin']) ?></div>
      <?php if (!empty($billBlock['gst'])): ?>
      <div>GSTIN: <?= h((string)$billBlock['gst']) ?></div>
      <?php endif; ?>
      <div class="small-muted mt-2">Place of supply considered: <?= h($placeState ?: '—') ?></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="box">
      <h2 class="h6 mb-2"><?= h($otherBlock['title']) ?></h2>
      <div><strong><?= h((string)$otherBlock['name']) ?></strong></div>
      <div><?= h((string)$otherBlock['addr1']) ?></div>
      <div><?= h((string)$otherBlock['addr2']) ?></div>
      <div><?= h((string)$otherBlock['city']) ?> <?= h((string)$otherBlock['state']) ?> <?= h((string)$otherBlock['pin']) ?></div>
      <?php if (!empty($otherBlock['gst'])): ?>
      <div>GSTIN: <?= h((string)$otherBlock['gst']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Items -->
<div class="table-responsive mt-3">
  <table class="table table-bordered">
    <thead class="table-light">
      <tr>
        <th style="width:60px;">SL</th>
        <th>Description</th>
        <th style="width:120px;">HSN/SAC</th>
        <th style="width:120px;">Qty</th>
        <th style="width:120px;">UOM</th>
        <th style="width:140px;">Rate</th>
        <th style="width:120px;">Disc %</th>
        <th style="width:120px;">Tax %</th>
        <th style="width:140px;">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($it as $r): ?>
        <tr>
          <td><?= (int)$r['sl_no'] ?></td>
          <td><?= nl2br(h((string)$r['item_name'])) ?></td>
          <td><?= h((string)$r['hsn_sac']) ?></td>
          <td class="text-end"><?= h((string)$r['qty']) ?></td>
          <td><?= h((string)$r['uom']) ?></td>
          <td class="text-end"><?= h((string)$r['rate']) ?></td>
          <td class="text-end"><?= h((string)$r['discount_pct']) ?></td>
          <td class="text-end"><?= h((string)$r['tax_pct']) ?></td>
          <td class="text-end"><?= h((string)$r['line_total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Totals (with GST split) -->
<div class="row justify-content-end">
  <div class="col-5">
    <table class="table">
      <tr><th>Subtotal</th><td class="text-end"><?= h((string)$h['subtotal']) ?></td></tr>
      <tr><th>Discount</th><td class="text-end"><?= h((string)$h['discount_amount']) ?></td></tr>
      <?php if ($isIntra): ?>
        <tr><th>CGST</th><td class="text-end"><?= number_format($cgst,2,'.','') ?></td></tr>
        <tr><th>SGST</th><td class="text-end"><?= number_format($sgst,2,'.','') ?></td></tr>
      <?php else: ?>
        <tr><th>IGST</th><td class="text-end"><?= number_format($igst,2,'.','') ?></td></tr>
      <?php endif; ?>
      <tr><th>Round Off</th><td class="text-end"><?= h((string)$h['round_off']) ?></td></tr>
      <tr class="table-light"><th>Grand Total</th><td class="text-end fw-bold"><?= h((string)$h['grand_total']) ?></td></tr>
    </table>
    <div class="small-muted">
      <div>Org State: <?= h($ORG_STATE) ?><?= $ORG_STATE_CODE ? ' ('.h($ORG_STATE_CODE).')' : '' ?></div>
      <div>Place of Supply: <?= h($placeState ?: '—') ?> → <?= $isIntra ? 'Intra-state (CGST+SGST)' : 'Inter-state (IGST)' ?></div>
    </div>
  </div>
</div>

<!-- Terms + Sign -->
<h2 class="h6">Terms & Conditions</h2>
<div><?= nl2br(h((string)($h['terms'] ?? ''))) ?></div>

<div class="mt-4 text-end">
  <div>For <strong><?= h($ORG_NAME) ?></strong></div>
  <div style="height:60px"></div>
  <div>Authorised Signatory</div>
</div>
</body>
</html>
