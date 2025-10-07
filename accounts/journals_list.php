<?php
declare(strict_types=1);
/** PATH: /public_html/accounts/journals_list.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('accounts.view');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$q   = trim($_GET['q'] ?? '');
$vt  = trim($_GET['vt'] ?? '');
$df  = trim($_GET['df'] ?? ''); // date from
$dt  = trim($_GET['dt'] ?? ''); // date to
$pg  = max(1, (int)($_GET['page'] ?? 1));
$pp  = 20;
$off = ($pg-1)*$pp;

$where=[]; $P=[];
if ($q  !== '') { $where[]="(j.voucher_no LIKE ? OR j.ref_doc_type LIKE ? OR j.ref_doc_id LIKE ? OR j.narration LIKE ?)"; array_push($P,"%$q%","%$q%","%$q%","%$q%"); }
if ($vt !== '') { $where[]="j.voucher_type=?"; $P[]=$vt; }
if ($df !== '') { $where[]="j.voucher_date>=?"; $P[]=$df; }
if ($dt !== '') { $where[]="j.voucher_date<=?"; $P[]=$dt; }
$W = $where ? 'WHERE '.implode(' AND ',$where) : '';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM journals j $W");
$cnt->execute($P);
$total = (int)$cnt->fetchColumn();

$sql = "SELECT j.* FROM journals j $W ORDER BY j.voucher_date DESC, j.id DESC LIMIT $pp OFFSET $off";
$st  = $pdo->prepare($sql);
$st->execute($P);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function h(?string $v){ return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8'); }

$types = ['JV'=>'Journal', 'CPV'=>'Cash Pay', 'CRV'=>'Cash Receive', 'CNV'=>'Credit Note', 'APB'=>'AP Bill', 'ARV'=>'AR Receipt'];
$typeColors = [
  'JV'=>'secondary', 'CPV'=>'warning', 'CRV'=>'info',
  'CNV'=>'dark', 'APB'=>'primary', 'ARV'=>'success'
];

$pages = (int)ceil($total / $pp);
$from  = $total ? ($off + 1) : 0;
$to    = min($off + $pp, $total);

/** UI includes for sidebar/layout */
$UI_PATH     = __DIR__ . '/../ui';
$PAGE_TITLE  = 'Journals';
$ACTIVE_MENU = 'accounts'; // optional, for highlight if your layout uses it

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="container py-4">

  <!-- Header + Actions -->
  <div class="d-flex align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-journal-text me-2"></i>Journals</h1>
    <span class="text-muted small ms-1"><?= $total ? "{$from}–{$to} of {$total}" : "No records" ?></span>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="journals_list.php"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
      <a class="btn btn-primary btn-sm" href="journals_form.php"><i class="bi bi-plus-lg"></i> New Journal</a>
    </div>
  </div>

  <!-- Filters (Sticky) -->
  <div class="card shadow-sm rounded-4 sticky-top mb-3" style="top: 72px; z-index: 1030;">
    <div class="card-body py-2">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-12 col-md-3">
          <label class="form-label small mb-1">Search</label>
          <input class="form-control" name="q" value="<?=h($q)?>" placeholder="Voucher/Narration/Ref">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Type</label>
          <select class="form-select" name="vt">
            <option value="">All Types</option>
            <?php foreach(array_keys($types) as $opt): ?>
              <option value="<?=$opt?>" <?=$vt===$opt?'selected':''?>><?=$opt?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">From</label>
          <input type="date" class="form-control" name="df" value="<?=h($df)?>">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">To</label>
          <input type="date" class="form-control" name="dt" value="<?=h($dt)?>">
        </div>
        <div class="col-6 col-md-3 d-grid">
          <button class="btn btn-primary"><i class="bi bi-funnel"></i> Apply Filters</button>
        </div>
      </form>

      <?php if ($q!=='' || $vt!=='' || $df!=='' || $dt!==''): ?>
        <div class="mt-2">
          <span class="text-muted small me-1">Active:</span>
          <?php if($q!==''): ?><span class="badge rounded-pill text-bg-secondary me-1">q: <?=h($q)?></span><?php endif; ?>
          <?php if($vt!==''): ?><span class="badge rounded-pill text-bg-secondary me-1">type: <?=h($vt)?></span><?php endif; ?>
          <?php if($df!==''): ?><span class="badge rounded-pill text-bg-secondary me-1">from: <?=h($df)?></span><?php endif; ?>
          <?php if($dt!==''): ?><span class="badge rounded-pill text-bg-secondary me-1">to: <?=h($dt)?></span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Table -->
  <div class="card shadow-sm rounded-4">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:160px">Voucher</th>
            <th style="width:120px">Type</th>
            <th style="width:120px">Date</th>
            <th>Reference</th>
            <th>Narration</th>
            <th style="width:110px" class="text-center">Status</th>
            <th style="width:80px" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No vouchers found</td></tr>
        <?php else: foreach($rows as $r):
          $t  = (string)($r['voucher_type'] ?? '');
          $tc = $typeColors[$t] ?? 'secondary';
          $ts = $types[$t]      ?? $t;
          $status = (string)($r['status'] ?? '');
          $sb = $status==='posted' ? 'success' : ($status==='draft' ? 'warning' : 'secondary');
          $ref = trim(($r['ref_doc_type']??'').' #'.($r['ref_doc_id']??''));
        ?>
          <tr>
            <td>
              <a href="journals_view.php?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none">
                <?= h($r['voucher_no'] ?: ('#'.$r['id'])) ?>
              </a>
              <div class="small text-muted">#<?= (int)$r['id'] ?></div>
            </td>
            <td><span class="badge text-bg-<?= $tc ?>"><?= h($ts) ?></span></td>
            <td><?= h($r['voucher_date'] ?? '') ?></td>
            <td class="text-muted"><?= h($ref) ?></td>
            <td style="max-width:520px;"><div class="text-truncate"><?= h($r['narration'] ?? '') ?></div></td>
            <td class="text-center">
              <span class="badge text-bg-<?= $sb ?>"><?= h($status ?: '—') ?></span>
            </td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary" href="journals_view.php?id=<?= (int)$r['id'] ?>" title="View"><i class="bi bi-eye"></i></a>
                <a class="btn btn-outline-secondary" href="journals_print.php?id=<?= (int)$r['id'] ?>" title="Print" target="_blank" rel="noopener"><i class="bi bi-printer"></i></a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Footer / Pagination -->
    <div class="card-footer d-flex align-items-center justify-content-between">
      <div class="small text-muted">
        <?= $total ? "Showing {$from}–{$to} of {$total}" : "Nothing to show" ?>
      </div>
      <?php if($pages>1): ?>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <?php $u = $_GET; $u['page'] = max(1, $pg-1); ?>
            <li class="page-item <?= $pg<=1?'disabled':'' ?>">
              <a class="page-link" href="?<?= http_build_query($u) ?>" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a>
            </li>
            <?php for($i=1;$i<=$pages;$i++): $u=$_GET; $u['page']=$i; ?>
              <li class="page-item <?= $i===$pg?'active':'' ?>">
                <a class="page-link" href="?<?= http_build_query($u) ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <?php $u = $_GET; $u['page'] = min($pages, $pg+1); ?>
            <li class="page-item <?= $pg>=$pages?'disabled':'' ?>">
              <a class="page-link" href="?<?= http_build_query($u) ?>" aria-label="Next"><span aria-hidden="true">&raquo;</span></a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </div>

</div>
<?php require_once $UI_PATH . '/layout_end.php'; ?>
