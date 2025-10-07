<?php
/** PATH: /public_html/sales_quotes/revision_restore.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_permission('sales.quote.edit');
csrf_require_token();

$qid = (int)($_POST['quote_id'] ?? 0);
$rev = (int)($_POST['rev_no'] ?? 0);
if ($qid<=0 || $rev<=0) { flash('Invalid request','danger'); redirect('/sales_quotes/sales_quotes_list.php'); }

$pdo = db();
$stm = $pdo->prepare("SELECT snapshot FROM sales_quote_revisions WHERE quote_id=:qid AND rev_no=:r");
$stm->execute([':qid'=>$qid, ':r'=>$rev]);
$row = $stm->fetch(PDO::FETCH_ASSOC);
if (!$row) { flash('Revision not found','danger'); redirect('/sales_quotes/sales_quotes_view.php?id='.$qid); }

$data  = json_decode($row['snapshot'], true);
$h     = $data['header'] ?? null;
$items = $data['items'] ?? [];

if (!$h) { flash('Invalid snapshot','danger'); redirect('/sales_quotes/sales_quotes_view.php?id='.$qid); }

$pdo->beginTransaction();
try {
    // Update header (keep id/created*)
    $fields = ['title','quote_date','party_id','contact_id','site_id','currency','fx_rate','subtotal','discount_total','tax_total','grand_total','terms','status'];
    $set = []; $params = [':id'=>$qid];
    foreach ($fields as $f) { $set[] = "$f=:$f"; $params[":$f"] = $h[$f] ?? null; }
    $pdo->prepare("UPDATE sales_quotes SET ".implode(',', $set).", updated_at=NOW() WHERE id=:id")->execute($params);

    // Replace items (MAPPED TO YOUR SCHEMA)
    $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id=:id")->execute([':id'=>$qid]);

    $ins = $pdo->prepare("
        INSERT INTO sales_quote_items
            (quote_id, sl_no, item_code, item_name, qty, uom, rate, discount_pct, tax_pct, line_total)
        VALUES
            (:qid, :sl_no, :item_code, :item_name, :qty, :uom, :rate, :discount_pct, :tax_pct, :line_total)
    ");

    $fallbackSl = 1;
    foreach ($items as $it){
        $ins->execute([
            ':qid'          => $qid,
            ':sl_no'        => (int)($it['sl_no'] ?? $fallbackSl++),
            ':item_code'    => (string)($it['item_code'] ?? ''),
            ':item_name'    => (string)($it['item_name'] ?? ($it['name'] ?? '')),
            ':qty'          => (float)($it['qty'] ?? 0),
            ':uom'          => (string)($it['uom'] ?? ''),
            ':rate'         => (float)($it['rate'] ?? ($it['price'] ?? 0)),
            ':discount_pct' => (float)($it['discount_pct'] ?? 0),
            ':tax_pct'      => (float)($it['tax_pct'] ?? ($it['tax_rate'] ?? 0)),
            ':line_total'   => (float)($it['line_total'] ?? 0),
        ]);
    }

    $pdo->commit();
    flash("Revision R{$rev} restored into the live quote.", 'success');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('Restore failed: '.h($e->getMessage()), 'danger');
}
redirect('/sales_quotes/sales_quotes_view.php?id='.$qid);
