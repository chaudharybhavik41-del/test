<?php
/** PATH: /public_html/sales_quotes/quote_pdf.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_permission('sales.quote.view');

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { http_response_code(400); echo "Invalid id"; exit; }

// optional template variant: standard | compact | with-photos (kept for future)
$tpl = preg_replace('/[^a-z\-]/','', (string)($_GET['tpl'] ?? 'standard'));
if (!in_array($tpl, ['standard','compact','with-photos'], true)) $tpl = 'standard';

$pdo = db();
$qh = $pdo->prepare("
    SELECT q.*, p.name AS party_name, p.gstin AS party_gstin, p.address AS party_address
      FROM sales_quotes q
      LEFT JOIN parties p ON p.id = q.party_id
     WHERE q.id=:id AND q.deleted_at IS NULL
");
$qh->execute([':id'=>$id]);
$quote = $qh->fetch(PDO::FETCH_ASSOC);
if (!$quote) { http_response_code(404); echo "Quote not found"; exit; }

// ITEMS MAPPED TO YOUR SCHEMA
$qi = $pdo->prepare("
    SELECT sl_no, item_code, item_name, qty, uom, rate, discount_pct, tax_pct, line_total
      FROM sales_quote_items
     WHERE quote_id=:id
  ORDER BY sl_no
");
$qi->execute([':id'=>$id]);
$items = $qi->fetchAll(PDO::FETCH_ASSOC);

$org = function_exists('org_profile') ? org_profile() : ['name'=>'Company', 'address'=>'', 'gstin'=>'', 'logo_url'=>''];

ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #999; padding: 6px; }
th { background: #eee; }
.text-right { text-align: right; }
.small { font-size: 11px; color: #555; }
<?php if ($tpl==='compact'): ?> th, td { padding:4px; font-size:11px; } <?php endif; ?>
</style>
</head>
<body>
  <table style="border:none">
    <tr>
      <td style="border:none">
        <h2><?=h($org['name'] ?? 'Organization')?></h2>
        <div class="small"><?=nl2br(h($org['address'] ?? ''))?></div>
        <div class="small">GSTIN: <?=h($org['gstin'] ?? '-')?></div>
      </td>
      <td style="border:none; text-align:right">
        <?php if (!empty($org['logo_url'])): ?>
          <img src="<?=h($org['logo_url'])?>" alt="Logo" style="max-height:70px">
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <h3 style="margin-top:0">Quotation: <?=h($quote['code'])?></h3>
  <table>
    <tr><th>Quote Date</th><td><?=h($quote['quote_date'])?></td><th>Client</th><td><?=h($quote['party_name'])?></td></tr>
    <tr><th>Client GSTIN</th><td><?=h($quote['party_gstin'] ?? '-')?></td><th>Title</th><td><?=h($quote['title'] ?? '')?></td></tr>
  </table>

  <br>
  <table>
    <thead>
      <tr>
        <th>Sl</th>
        <th>Item</th>
        <th class="text-right">Qty</th>
        <th>UOM</th>
        <th class="text-right">Rate</th>
        <th class="text-right">Disc %</th>
        <th class="text-right">Tax %</th>
        <th class="text-right">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td><?= (int)$it['sl_no'] ?></td>
        <td><?= h(($it['item_code'] ? $it['item_code'].' - ' : '').$it['item_name']) ?></td>
        <td class="text-right"><?= h($it['qty']) ?></td>
        <td><?= h($it['uom']) ?></td>
        <td class="text-right"><?= number_format((float)$it['rate'], 2) ?></td>
        <td class="text-right"><?= number_format((float)($it['discount_pct'] ?? 0), 2) ?></td>
        <td class="text-right"><?= number_format((float)($it['tax_pct'] ?? 0), 2) ?></td>
        <td class="text-right"><?= number_format((float)$it['line_total'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="7" class="text-right"><b>Subtotal</b></td><td class="text-right"><?=number_format((float)$quote['subtotal'],2)?></td></tr>
      <tr><td colspan="7" class="text-right"><b>Discount</b></td><td class="text-right"><?=number_format((float)$quote['discount_total'],2)?></td></tr>
      <tr><td colspan="7" class="text-right"><b>Tax</b></td><td class="text-right"><?=number_format((float)$quote['tax_total'],2)?></td></tr>
      <tr><td colspan="7" class="text-right"><b>Grand Total</b></td><td class="text-right"><?=number_format((float)$quote['grand_total'],2)?></td></tr>
    </tbody>
  </table>

  <?php if (!empty($quote['terms'])): ?>
    <h4>Terms & Conditions</h4>
    <div class="small"><?=nl2br(h($quote['terms']))?></div>
  <?php endif; ?>
</body>
</html>
<?php
$html = ob_get_clean();

$pdfFileName = 'Quote-' . preg_replace('/[^A-Za-z0-9\-]/','', (string)$quote['code']) . '.pdf';

if (class_exists('\\Mpdf\\Mpdf')) {
    $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
    $mpdf->WriteHTML($html);
    $mpdf->Output($pdfFileName, 'I');
    exit;
} elseif (class_exists('\\Dompdf\\Dompdf')) {
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4');
    $dompdf->render();
    $dompdf->stream($pdfFileName, ['Attachment' => false]);
    exit;
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<p><b>PDF library not found.</b> Showing HTML instead.</p>";
    echo $html;
}
