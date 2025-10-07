<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
require_permission('purchase.quote.view');

$pdo=db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$q = trim($_GET['q'] ?? '');
$where = "WHERE 1=1";
$args = [];
if ($q !== '') {
  $where .= " AND (iq.quote_no LIKE CONCAT('%',?,'%') OR i.inquiry_no LIKE CONCAT('%',?,'%') OR p.name LIKE CONCAT('%',?,'%'))";
  $args = [$q,$q,$q];
}

$sql = "SELECT iq.id, iq.quote_no, iq.quote_date, iq.status, iq.total_after_tax,
               i.inquiry_no, p.name AS supplier_name, pr.code AS project_code
        FROM inquiry_quotes iq
        JOIN inquiries i ON i.id=iq.inquiry_id
        LEFT JOIN projects pr ON pr.id=i.project_id
        JOIN parties p ON p.id=iq.supplier_id
        $where
        ORDER BY iq.id DESC LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Inquiry Quotes</h1>
    <a href="/purchase/inquiry_quotes_form.php" class="btn btn-primary ms-auto">New Quote</a>
  </div>

  <div class="card p-3 shadow-sm">
    <div class="row g-2 mb-2">
      <div class="col-md-6">
        <input type="text" class="form-control" placeholder="Search quote no / inquiry no / supplier"
               value="<?=htmlspecialchars($q)?>" onkeydown="if(event.key==='Enter') location='?q='+encodeURIComponent(this.value)">
      </div>
      <div class="col-md-2">
        <button class="btn btn-outline-secondary w-100" onclick="location='?q='+encodeURIComponent(document.querySelector('input.form-control').value)">Search</button>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead>
          <tr>
            <th>ID</th>
            <th>Quote No</th>
            <th>Date</th>
            <th>Inquiry</th>
            <th>Project</th>
            <th>Supplier</th>
            <th>Status</th>
            <th class="text-end">Amount</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td><?=$r['id']?></td>
            <td><?=htmlspecialchars($r['quote_no']??'')?></td>
            <td><?=htmlspecialchars($r['quote_date']??'')?></td>
            <td><?=htmlspecialchars($r['inquiry_no']??'')?></td>
            <td><?=htmlspecialchars($r['project_code']??'')?></td>
            <td><?=htmlspecialchars($r['supplier_name']??'')?></td>
            <td>
              <?php
                $s=$r['status'];
                $badge= match($s){
                  'draft'=>'secondary','submitted'=>'warning','locked'=>'dark', default=>'secondary'
                };
              ?>
              <span class="badge bg-<?=$badge?>"><?=$s?></span>
            </td>
            <td class="text-end"><?=number_format((float)($r['total_after_tax']??0),2)?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="/purchase/inquiry_quotes_form.php?id=<?=$r['id']?>">Open</a>
            </td>
          </tr>
          <?php endforeach; if(!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No quotes yet.</td></tr>
          <?php endif;?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
