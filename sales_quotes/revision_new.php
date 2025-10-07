<?php
/** PATH: /public_html/sales_quotes/revision_new.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login(); require_permission('sales.quote.edit'); csrf_require_token();

$qid = (int)($_POST['quote_id'] ?? 0);
if ($qid<=0) { flash('Invalid quote id','danger'); redirect('/sales_quotes/sales_quotes_list.php'); }

$pdo = db();

$h = $pdo->prepare("SELECT * FROM sales_quotes WHERE id=:id AND deleted_at IS NULL");
$h->execute([':id'=>$qid]);
$header = $h->fetch(PDO::FETCH_ASSOC);
if (!$header) { flash('Quote not found','danger'); redirect('/sales_quotes/sales_quotes_list.php'); }

$i = $pdo->prepare("SELECT * FROM sales_quote_items WHERE quote_id=:id ORDER BY sl");
$i->execute([':id'=>$qid]);
$items = $i->fetchAll(PDO::FETCH_ASSOC);

$revNo = (int)$pdo->query("SELECT COALESCE(MAX(rev_no),0)+1 AS n FROM sales_quote_revisions WHERE quote_id={$qid}")->fetchColumn();
$payload = json_encode(['header'=>$header,'items'=>$items], JSON_UNESCAPED_UNICODE);

$s = $pdo->prepare("INSERT INTO sales_quote_revisions(quote_id,rev_no,snapshot,created_by,created_at) VALUES(?,?,?,?,NOW())");
$s->execute([$qid,$revNo,$payload,(int)current_user_id()]);

flash("Revision R{$revNo} saved.", 'success');
redirect('/sales_quotes/revisions_list.php?quote_id='.$qid);
