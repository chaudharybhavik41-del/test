<?php
/** PATH: /public_html/sales_quotes/revisions_list.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login(); require_permission('sales.quote.view');

$qid = (int)($_GET['quote_id'] ?? 0);
if ($qid<=0) { flash('Invalid quote id','danger'); redirect('/sales_quotes/sales_quotes_list.php'); }

$pdo = db();
$qh = $pdo->prepare("SELECT id, code, title FROM sales_quotes WHERE id=:id");
$qh->execute([':id'=>$qid]);
$q = $qh->fetch(PDO::FETCH_ASSOC);
if (!$q) { flash('Quote not found','danger'); redirect('/sales_quotes/sales_quotes_list.php'); }

$revs = $pdo->prepare("SELECT id, rev_no, created_by, created_at FROM sales_quote_revisions WHERE quote_id=:id ORDER BY rev_no DESC");
$revs->execute([':id'=>$qid]);
$rows = $revs->fetchAll(PDO::FETCH_ASSOC);

$UI_PATH = dirname(__DIR__).'/ui';
$PAGE_TITLE = 'Revisions - '.$q['code'];
$ACTIVE_MENU = 'sales.quotes';
require_once $UI_PATH.'/init.php';
require_once $UI_PATH.'/layout_start.php';
?>
<h3 class="mb-3">Revisions for <?=h($q['code'])?> - <?=h($q['title']??'')?></h3>

<form class="row g-2 mb-3" method="get" action="/sales_quotes/revisions_compare.php">
  <input type="hidden" name="quote_id" value="<?=$qid?>">
  <div class="col-auto">
    <select name="a" class="form-select" required>
      <option value="">Select Rev A</option>
      <?php foreach ($rows as $r): ?>
        <option value="<?=$r['rev_no']?>">R<?=$r['rev_no']?> (<?=$r['created_at']?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <select name="b" class="form-select" required>
      <option value="">Select Rev B</option>
      <?php foreach ($rows as $r): ?>
        <option value="<?=$r['rev_no']?>">R<?=$r['rev_no']?> (<?=$r['created_at']?>)</option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-outline-primary">Compare</button>
  </div>
</form>

<table class="table table-sm table-striped">
  <thead><tr><th>Rev</th><th>Created</th><th>By</th><th class="text-end">Actions</th></tr></thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td>R<?=$r['rev_no']?></td>
        <td><?=h($r['created_at'])?></td>
        <td>#<?=h($r['created_by'])?></td>
        <td class="text-end">
          <?php if (rbac_can('sales.quote.edit')): ?>
          <form action="/sales_quotes/revision_restore.php" method="post" class="d-inline" onsubmit="return confirm('Restore this revision into the live quote?');">
            <?=csrf_hidden_input()?>
            <input type="hidden" name="quote_id" value="<?=$qid?>">
            <input type="hidden" name="rev_no" value="<?=$r['rev_no']?>">
            <button class="btn btn-sm btn-outline-warning">Restore</button>
          </form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline-secondary" href="/sales_quotes/revisions_compare.php?quote_id=<?=$qid?>&a=<?=$r['rev_no']?>&b=<?=$r['rev_no']?>">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require_once $UI_PATH.'/layout_end.php';
