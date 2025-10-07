<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/** Helpers for column-aware attachment handling (MariaDB-safe) */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $sql = "SELECT 1
            FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = ?
           LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}
function first_existing(PDO $pdo, string $table, array $candidates): string {
  foreach ($candidates as $c) if ($c && col_exists($pdo,$table,$c)) return $c;
  return '';
}

$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ http_response_code(400); echo "Missing id"; exit; }

/** Header (supplier + project) */
$st=$pdo->prepare("
  SELECT po.*, p.name AS supplier_name, pr.code AS project_code, pr.name AS project_name
  FROM purchase_orders po
  LEFT JOIN parties  p  ON p.id=po.supplier_id
  LEFT JOIN projects pr ON pr.id=po.project_id
  WHERE po.id=?");
$st->execute([$id]); $po=$st->fetch(PDO::FETCH_ASSOC);
if(!$po){ http_response_code(404); echo "PO not found"; exit; }

/** Actions */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='issue') {
  require_permission('purchase.po.issue');
  $pdo->prepare("UPDATE purchase_orders SET status='issued' WHERE id=?")->execute([$id]);
  header('Location: /purchase/po_form.php?id='.$id); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='save_terms') {
  require_permission('purchase.po.manage');
  $delivery = trim((string)($_POST['delivery_terms'] ?? ''));
  $payment  = trim((string)($_POST['payment_terms'] ?? ''));
  $freight  = trim((string)($_POST['freight_terms'] ?? ''));
  $pdo->prepare("UPDATE purchase_orders SET delivery_terms=?, payment_terms=?, freight_terms=? WHERE id=?")
      ->execute([$delivery ?: null, $payment ?: null, $freight ?: null, $id]);
  header('Location: /purchase/po_form.php?id='.$id); exit;
}

/** Lines (LEFT JOIN so rows always render) */
$qLines=$pdo->prepare("SELECT li.*,
                              it.material_code, it.name AS item_name,
                              u.code AS uom_code
                       FROM purchase_order_items li
                       LEFT JOIN items it ON it.id = li.item_id
                       LEFT JOIN uom   u  ON u.id  = li.uom_id
                       WHERE li.po_id=?
                       ORDER BY li.id");
$qLines->execute([$id]); 
$lines=$qLines->fetchAll(PDO::FETCH_ASSOC);

/** Attachments: detect actual columns and only select present ones */
$attTable = 'attachments';
$pathCol  = first_existing($pdo,$attTable, ['path','file_path','filepath','url']);                   // optional
$nameCol  = first_existing($pdo,$attTable, ['original_name','filename','name']);                     // optional (fallback to #id)
$mimeCol  = first_existing($pdo,$attTable, ['mime','mime_type','content_type']);                    // optional
$sizeCol  = first_existing($pdo,$attTable, ['size','bytes','file_size']);                           // optional
$timeCol  = first_existing($pdo,$attTable, ['uploaded_at','created_at','createdOn','created_on']); // optional

$cols = ["a.id"];
if ($nameCol) $cols[] = "a.`$nameCol` AS original_name";
if ($pathCol) $cols[] = "a.`$pathCol` AS path";
if ($mimeCol) $cols[] = "a.`$mimeCol` AS mime";
if ($sizeCol) $cols[] = "a.`$sizeCol` AS size";
if ($timeCol) $cols[] = "a.`$timeCol` AS uploaded_at";
$selectCols = implode(", ", $cols);

$attachments = [];
if ($selectCols !== "a.id") {
  $att=$pdo->prepare("
    SELECT $selectCols
    FROM attachment_links al
    JOIN attachments a ON a.id = al.attachment_id
    WHERE al.entity_type='purchase_order' AND al.entity_id=?
    ORDER BY a.id DESC");
  $att->execute([$id]); 
  $attachments=$att->fetchAll(PDO::FETCH_ASSOC);
}

function badge_for_status(string $s): string {
  return match($s){
    'issued'   => 'success',
    'approved' => 'primary',
    'cancelled'=> 'danger',
    'closed'   => 'dark',
    default    => 'secondary'
  };
}

include __DIR__.'/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Purchase Order — <?=htmlspecialchars((string)$po['po_no'])?></h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="/purchase/po_list.php">Back</a>
      <a class="btn btn-outline-dark btn-sm" target="_blank" href="/purchase/po_print.php?id=<?=$id?>">Print</a>
      <a class="btn btn-outline-secondary btn-sm" target="_blank" href="/purchase/po_pdf.php?id=<?=$id?>">PDF</a>
      <?php if ($po['status']==='draft'): ?>
        <form method="post" onsubmit="return confirm('Issue this PO?');">
          <input type="hidden" name="_action" value="issue">
          <button class="btn btn-primary btn-sm">Issue</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Summary -->
  <div class="card mb-3 shadow-sm">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3"><strong>Supplier</strong><br><?=htmlspecialchars((string)($po['supplier_name']??''))?></div>
        <div class="col-md-2"><strong>Date</strong><br><?=htmlspecialchars((string)$po['po_date'])?></div>
        <div class="col-md-2"><strong>Status</strong><br><span class="badge bg-<?= badge_for_status((string)$po['status']) ?>"><?=htmlspecialchars((string)$po['status'])?></span></div>
        <div class="col-md-2"><strong>Currency</strong><br><?=htmlspecialchars((string)$po['currency'])?></div>
        <div class="col-md-3"><strong>Inquiry Ref</strong><br>#<?= (int)$po['inquiry_id'] ?></div>
      </div>
      <div class="row g-3 mt-1">
        <div class="col-md-6">
          <strong>Project</strong><br>
          <?=htmlspecialchars((string)($po['project_code'] ?? ''))?>
          <?php if (!empty($po['project_name'])): ?>
            — <span class="text-muted"><?=htmlspecialchars((string)$po['project_name'])?></span>
          <?php endif; ?>
        </div>
        <div class="col-md-6">
          <strong>GST Inclusive</strong><br><?=((int)$po['gst_inclusive']===1 ? 'Yes' : 'No')?>
        </div>
      </div>
    </div>
  </div>

  <!-- ITEMS TABLE -->
  <div class="table-responsive mb-3">
    <table class="table table-striped align-middle">
      <thead><tr>
        <th>Item</th>
        <th class="text-end">Qty</th>
        <th>UOM</th>
        <th class="text-end">Unit</th>
        <th class="text-end">Disc %</th>
        <th class="text-end">Tax %</th>
        <th class="text-end">Line Total</th>
      </tr></thead>
      <tbody>
        <?php foreach($lines as $ln): ?>
          <tr>
            <td><?=htmlspecialchars((string)(($ln['material_code'] ?? '').' — '.($ln['item_name'] ?? ''))) ?></td>
            <td class="text-end"><?= number_format((float)($ln['qty'] ?? 0), 3) ?></td>
            <td><?=htmlspecialchars((string)($ln['uom_code'] ?? ''))?></td>
            <td class="text-end"><?= number_format((float)($ln['unit_price'] ?? 0), 2) ?></td>
            <td class="text-end"><?= number_format((float)($ln['discount_percent'] ?? 0), 2) ?></td>
            <td class="text-end"><?= number_format((float)($ln['tax_percent'] ?? 0), 2) ?></td>
            <td class="text-end"><?= number_format((float)($ln['line_total_after_tax'] ?? 0), 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lines): ?>
          <tr><td colspan="7" class="text-center text-muted">No lines</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="6" class="text-end">Subtotal</th><th class="text-end"><?= number_format((float)($po['total_before_tax'] ?? 0), 2) ?></th></tr>
        <tr><th colspan="6" class="text-end">Tax</th><th class="text-end"><?= number_format((float)($po['total_tax'] ?? 0), 2) ?></th></tr>
        <tr><th colspan="6" class="text-end">Total</th><th class="text-end"><?= number_format((float)($po['total_after_tax'] ?? 0), 2) ?></th></tr>
      </tfoot>
    </table>
  </div>

  <!-- COMMERCIAL TERMS -->
  <div class="card mb-3">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong>Commercial Terms</strong>
      <span class="text-muted small">Payment, Transport/Freight, Delivery</span>
    </div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="_action" value="save_terms">
        <div class="col-md-4">
          <label class="form-label">Payment Terms</label>
          <input class="form-control" name="payment_terms" value="<?=htmlspecialchars((string)($po['payment_terms'] ?? ''))?>" placeholder="e.g. 30% advance, 70% against delivery">
        </div>
        <div class="col-md-4">
          <label class="form-label">Transport / Freight Terms</label>
          <input class="form-control" name="freight_terms" value="<?=htmlspecialchars((string)($po['freight_terms'] ?? ''))?>" placeholder="e.g. Ex-works / To-Pay / CIF site">
        </div>
        <div class="col-md-4">
          <label class="form-label">Delivery Terms</label>
          <input class="form-control" name="delivery_terms" value="<?=htmlspecialchars((string)($po['delivery_terms'] ?? ''))?>" placeholder="e.g. 10–12 days from PO">
        </div>
        <div class="col-12">
          <button class="btn btn-outline-primary">Save Terms</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ATTACHMENTS CARD (separate table) -->
  <div class="card">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
      <strong>Attachments</strong>
      <span class="text-muted small">Attach supplier quote, drawings, etc.</span>
    </div>
    <div class="card-body">
      <form action="/purchase/po_attach_upload.php" method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <input type="hidden" name="po_id" value="<?=$id?>">
        <div class="col-md-6">
          <label class="form-label">File</label>
          <input type="file" class="form-control" name="file" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Notes (optional)</label>
          <input class="form-control" name="notes" placeholder="Supplier quotation / spec sheet">
        </div>
        <div class="col-md-2">
          <button class="btn btn-secondary w-100">Upload</button>
        </div>
      </form>

      <div class="table-responsive mt-3">
        <table class="table table-sm align-middle">
          <thead class="table-light"><tr>
            <th>File</th><th>MIME</th><th class="text-end">Size</th><th>Uploaded</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($attachments as $a): 
              $href = (string)($a['path'] ?? '');
              $name = (string)($a['original_name'] ?? ('Attachment #'.(int)$a['id']));
              $mime = (string)($a['mime'] ?? '');
              $size = isset($a['size']) ? (float)$a['size'] : null;
              $when = (string)($a['uploaded_at'] ?? '');
            ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <?php if ($href): ?>
                      <a href="<?=htmlspecialchars($href)?>" target="_blank"><?=htmlspecialchars($name)?></a>
                    <?php else: ?>
                      <?=htmlspecialchars($name)?>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="/purchase/po_attach_download.php?po_id=<?=$id?>&attachment_id=<?=(int)$a['id']?>">Download</a>
                  </div>
                </td>
                <td><?=htmlspecialchars($mime)?></td>
                <td class="text-end"><?= $size!==null ? number_format($size).' bytes' : '—' ?></td>
                <td><?=htmlspecialchars($when)?></td>
                <td>
                  <form action="/purchase/po_attach_delete.php" method="post" onsubmit="return confirm('Delete attachment?');">
                    <input type="hidden" name="po_id" value="<?=$id?>">
                    <input type="hidden" name="attachment_id" value="<?=$a['id']?>">
                    <button class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$attachments): ?>
              <tr><td colspan="5" class="text-muted text-center">No attachments yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__.'/../ui/layout_end.php';
