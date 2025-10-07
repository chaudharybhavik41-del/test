<?php
/** PATH: /public_html/sales_quotes/revisions_compare.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_permission('sales.quote.view');

$qid = (int)($_GET['quote_id'] ?? 0);
$A   = (int)($_GET['a'] ?? 0);
$B   = (int)($_GET['b'] ?? 0);
if ($qid<=0 || $A<=0 || $B<=0) { http_response_code(400); echo 'Bad request'; exit; }

$pdo = db();
$load = $pdo->prepare("
    SELECT rev_no, snapshot, created_at
      FROM sales_quote_revisions
     WHERE quote_id=:qid AND rev_no IN (:a,:b)
     ORDER BY rev_no
");
$load->execute([':qid'=>$qid, ':a'=>$A, ':b'=>$B]);
$revs = $load->fetchAll(PDO::FETCH_ASSOC);

$pick = function(array $rows, int $revNo) {
    foreach ($rows as $r) if ((int)$r['rev_no'] === $revNo) return json_decode($r['snapshot'] ?? '{}', true);
    return [];
};
$rA = $pick($revs, $A);
$rB = $pick($revs, $B);

function diff_cell($av, $bv){
    if ($av === $bv) return '<td colspan="2">'.h((string)$av).'</td>';
    return '<td><del>'.h((string)$av).'</del></td><td><ins>'.h((string)$bv).'</ins></td>';
}

$UI_PATH = dirname(__DIR__).'/ui';
$PAGE_TITLE = "Compare R{$A} vs R{$B}";
$ACTIVE_MENU = 'sales.quotes';
require_once $UI_PATH.'/init.php';
require_once $UI_PATH.'/layout_start.php';
?>
<h3>Compare Revisions R<?=$A?> ↔ R<?=$B?></h3>

<h5 class="mt-3">Header</h5>
<table class="table table-sm">
  <thead><tr><th>Field</th><th>Rev <?=$A?></th><th>Rev <?=$B?></th></tr></thead>
  <tbody>
  <?php
  $fields = ['title','quote_date','party_id','contact_id','subtotal','discount_total','tax_total','grand_total','terms','status'];
  foreach ($fields as $f) {
    $av = $rA['header'][$f] ?? '';
    $bv = $rB['header'][$f] ?? '';
    echo '<tr><th>'.h($f).'</th>'.diff_cell($av,$bv).'</tr>';
  }
  ?>
  </tbody>
</table>

<h5 class="mt-3">Lines</h5>
<table class="table table-sm">
  <thead>
    <tr>
      <th>Sl</th><th>Item Code</th><th>Item Name</th>
      <th class="text-end">Qty</th><th>UOM</th>
      <th class="text-end">Rate</th><th class="text-end">Disc %</th>
      <th class="text-end">Tax %</th><th class="text-end">Amount</th>
    </tr>
  </thead>
  <tbody>
  <?php
  $la = $rA['items'] ?? [];
  $lb = $rB['items'] ?? [];
  $max = max(count($la), count($lb));
  for ($i=0;$i<$max;$i++){
    $a = $la[$i] ?? [];
    $b = $lb[$i] ?? [];
    $slA = $a['sl_no'] ?? ($i+1);
    $slB = $b['sl_no'] ?? ($i+1);
    $chg = ($a != $b) ? 'table-warning' : '';
    echo "<tr class='{$chg}'>";
    echo "<td>".h($slA)." → ".h($slB)."</td>";
    echo "<td>".h($a['item_code'] ?? '')." → ".h($b['item_code'] ?? '')."</td>";
    echo "<td>".h($a['item_name'] ?? ($a['name'] ?? ''))."</td>";
    echo "<td class='text-end'>".h($a['qty'] ?? '')." → ".h($b['qty'] ?? '')."</td>";
    echo "<td>".h($a['uom'] ?? '')." → ".h($b['uom'] ?? '')."</td>";
    echo "<td class='text-end'>".h($a['rate'] ?? ($a['price'] ?? ''))." → ".h($b['rate'] ?? ($b['price'] ?? ''))."</td>";
    echo "<td class='text-end'>".h($a['discount_pct'] ?? '')." → ".h($b['discount_pct'] ?? '')."</td>";
    echo "<td class='text-end'>".h($a['tax_pct'] ?? ($a['tax_rate'] ?? ''))." → ".h($b['tax_pct'] ?? ($b['tax_rate'] ?? ''))."</td>";
    echo "<td class='text-end'>".h($a['line_total'] ?? '')." → ".h($b['line_total'] ?? '')."</td>";
    echo "</tr>";
  }
  ?>
  </tbody>
</table>
<?php require_once $UI_PATH.'/layout_end.php';
