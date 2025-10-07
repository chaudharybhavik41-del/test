<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
require_permission('purchase.quote.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$q = trim($_GET['q'] ?? '');
$where = "WHERE 1=1";
$args = [];

if ($q !== '') {
  $where .= " AND (iq.quote_no LIKE CONCAT('%', ?, '%'))";
  $args[] = $q;
}

$sql = "SELECT iq.id, iq.quote_no, iq.quote_date, iq.currency, iq.status,
               i.inquiry_no, p.name AS supplier_name, iq.total_after_tax
        FROM inquiry_quotes iq
        JOIN inquiries i ON i.id = iq.inquiry_id
        LEFT JOIN parties p ON p.id = iq.supplier_id
        $where
        ORDER BY iq.id DESC
        LIMIT 200";

$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

include __DIR__.'/../ui/layout_start.php';
?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">Quotes</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-primary" href="/purchase/quotes_form.php">New Quote</a>
    <a class="btn btn-outline-primary" href="/purchase/quotes_compare.php">Quotation Compare</a>
  </div>
</div>

<!-- Filters -->
<div class="card shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get" action="">
      <div class="col-12 col-md-6">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by Quote No">
        </div>
      </div>
      <div class="col-12 col-md-3">
        <button class="btn btn-outline-secondary w-100"><i class="bi bi-funnel me-1"></i> Search</button>
      </div>
      <div class="col-12 col-md-3 text-md-end">
        <?php if ($q !== ''): ?>
          <a class="btn btn-light" href="<?= strtok($_SERVER['REQUEST_URI'], '?') ?>"><i class="bi bi-x-circle me-1"></i> Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Results table -->
<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Quote No</th>
            <th>Date</th>
            <th>Inquiry</th>
            <th>Supplier</th>
            <th class="text-end">Amount</th>
            <th>Status</th>
            <th class="text-end" style="width:120px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($r['quote_no']) ?></td>
              <td><?= htmlspecialchars($r['quote_date']) ?></td>
              <td><span class="text-muted"><?= htmlspecialchars($r['inquiry_no']) ?></span></td>
              <td><?= htmlspecialchars($r['supplier_name'] ?? '') ?></td>
              <td class="text-end"><?= number_format((float)($r['total_after_tax'] ?? 0), 2) ?></td>
              <td>
                <?php
                  $cls = 'bg-secondary-subtle text-secondary-emphasis border';
                  if ($r['status'] === 'submitted') $cls = 'bg-success-subtle text-success-emphasis border';
                  elseif ($r['status'] === 'revised') $cls = 'bg-warning-subtle text-warning-emphasis border';
                ?>
                <span class="badge <?= $cls ?>"><?= htmlspecialchars($r['status']) ?></span>
              </td>
              <td class="text-end">
                <div class="btn-group btn-group-sm" role="group">
                  <a class="btn btn-light" href="/purchase/quotes_form.php?id=<?= (int)$r['id'] ?>" title="Open">
                    <i class="bi bi-box-arrow-up-right"></i>
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="7" class="p-0">
                <div class="text-center text-muted py-4">No quotes found.</div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__.'/../ui/layout_end.php'; ?>
