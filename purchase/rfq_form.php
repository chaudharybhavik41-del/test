<?php
/** PATH: /public_html/purchase/rfq_form.php */
declare(strict_types=1);

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_once __DIR__.'/../includes/numbering.php';

require_login();
require_permission('purchase.rfq.manage');

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id          = (int)($_GET['id'] ?? 0);
$inquiry_id  = (int)($_GET['inquiry_id'] ?? 0);
$is_edit     = $id > 0;

/* ---------------- Helpers ---------------- */
function table_exists(PDO $pdo, string $table): bool {
  $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $stmt->execute([$table]);
  return (bool)$stmt->fetchColumn();
}
function table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $stmt->execute([$table]);
  $cols = array_map(fn($r) => $r['COLUMN_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
  return $cache[$table] = $cols ?: [];
}
function insert_row(PDO $pdo, string $table, array $data): int {
  $cols = table_columns($pdo, $table);
  if (!$cols) throw new RuntimeException("Table not found: {$table}");
  // notes -> remarks, if supported
  if (isset($data['notes']) && !isset($data['remarks']) && in_array('remarks', $cols, true)) {
    $data['remarks'] = $data['notes'];
    unset($data['notes']);
  }
  $filtered = array_intersect_key($data, array_flip($cols));
  if (!$filtered) throw new RuntimeException("No valid columns to insert for {$table}");
  $names = array_keys($filtered);
  $sql = "INSERT INTO {$table} (".implode(',', $names).") VALUES (".implode(',', array_fill(0, count($names), '?')).")";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_values($filtered));
  return (int)$pdo->lastInsertId();
}
function update_row(PDO $pdo, string $table, int $id, array $data): void {
  $cols = table_columns($pdo, $table);
  if (!$cols) throw new RuntimeException("Table not found: {$table}");
  if (isset($data['notes']) && !isset($data['remarks']) && in_array('remarks', $cols, true)) {
    $data['remarks'] = $data['notes'];
    unset($data['notes']);
  }
  $filtered = array_intersect_key($data, array_flip($cols));
  if (!$filtered) return;
  $sets = [];
  foreach (array_keys($filtered) as $c) $sets[] = "{$c}=?";
  $sql = "UPDATE {$table} SET ".implode(',', $sets)." WHERE id=?";
  $stmt = $pdo->prepare($sql);
  $vals = array_values($filtered);
  $vals[] = $id;
  $stmt->execute($vals);
}

/** Accepts YYYY-mm-dd or dd-mm-yyyy, returns YYYY-mm-dd or null */
function normalize_date_in(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  // already yyyy-mm-dd
  if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $s)) return $s;
  // dd-mm-yyyy -> yyyy-mm-dd
  if (preg_match('~^(\d{2})-(\d{2})-(\d{4})$~', $s, $m)) {
    return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
  }
  // try strtotime fallback
  $ts = strtotime($s);
  return $ts ? date('Y-m-d', $ts) : null;
}

function quotes_table(PDO $pdo): string {
  if (table_exists($pdo, 'quotes')) return 'quotes';
  if (table_exists($pdo, 'supplier_quotes')) return 'supplier_quotes';
  return 'quotes';
}

/**
 * Returns supplier list for an Inquiry as [ ['id'=>party_id, 'name'=>party_name], ... ]
 * Auto-detects pivot table:
 *  - inquiry_suppliers (supplier_id or party_id)
 *  - inquiry_parties   (party_id + role/type in ['supplier','vendor'])
 *  - inquiry_recipients(party_id + type/recipient_type in ['supplier','vendor'])
 */
function get_inquiry_suppliers(PDO $pdo, int $inquiry_id): array {
  if ($inquiry_id <= 0) return [];

  if (table_exists($pdo, 'inquiry_suppliers')) {
    $cols = table_columns($pdo, 'inquiry_suppliers');
    $sid  = in_array('supplier_id', $cols, true) ? 'supplier_id' : (in_array('party_id', $cols, true) ? 'party_id' : null);
    if ($sid) {
      $sql = "SELECT s.{$sid} AS party_id, p.name 
              FROM inquiry_suppliers s 
              JOIN parties p ON p.id = s.{$sid}
              WHERE s.inquiry_id = ?
              ORDER BY p.name";
      $st = $pdo->prepare($sql);
      $st->execute([$inquiry_id]);
      return array_map(fn($r)=>['id'=>(int)$r['party_id'],'name'=>$r['name']], $st->fetchAll(PDO::FETCH_ASSOC));
    }
  }

  if (table_exists($pdo, 'inquiry_parties')) {
    $cols = table_columns($pdo, 'inquiry_parties');
    if (in_array('party_id', $cols, true)) {
      $roleCol = in_array('role', $cols, true) ? 'role' : (in_array('type', $cols, true) ? 'type' : null);
      $whereRole = $roleCol ? "AND ip.{$roleCol} IN ('supplier','vendor')" : '';
      $sql = "SELECT ip.party_id, p.name
              FROM inquiry_parties ip 
              JOIN parties p ON p.id = ip.party_id
              WHERE ip.inquiry_id = ? {$whereRole}
              ORDER BY p.name";
      $st = $pdo->prepare($sql);
      $st->execute([$inquiry_id]);
      return array_map(fn($r)=>['id'=>(int)$r['party_id'],'name'=>$r['name']], $st->fetchAll(PDO::FETCH_ASSOC));
    }
  }

  if (table_exists($pdo, 'inquiry_recipients')) {
    $cols = table_columns($pdo, 'inquiry_recipients');
    if (in_array('party_id', $cols, true)) {
      $typeCol = in_array('type', $cols, true) ? 'type' : (in_array('recipient_type', $cols, true) ? 'recipient_type' : null);
      $whereType = $typeCol ? "AND ir.{$typeCol} IN ('supplier','vendor')" : '';
      $sql = "SELECT ir.party_id, p.name
              FROM inquiry_recipients ir
              JOIN parties p ON p.id = ir.party_id
              WHERE ir.inquiry_id = ? {$whereType}
              ORDER BY p.name";
      $st = $pdo->prepare($sql);
      $st->execute([$inquiry_id]);
      return array_map(fn($r)=>['id'=>(int)$r['party_id'],'name'=>$r['name']], $st->fetchAll(PDO::FETCH_ASSOC));
    }
  }

  return [];
}

/** Open or create a supplier quote shell for this RFQ (header only) */
function open_or_create_quote(PDO $pdo, int $rfq_id, int $supplier_id, ?string $remarks = null): int {
  $qt = quotes_table($pdo);

  // existing?
  $find = $pdo->prepare("SELECT id FROM {$qt} WHERE rfq_id=? AND supplier_id=? LIMIT 1");
  $find->execute([$rfq_id, $supplier_id]);
  $qid = (int)($find->fetchColumn() ?: 0);
  if ($qid) return $qid;

  // create
  $data = [
    'rfq_id'      => $rfq_id,
    'supplier_id' => $supplier_id,
    'status'      => 'draft',
    'remarks'     => $remarks,                  // silently dropped if column absent
    'created_at'  => date('Y-m-d H:i:s'),
  ];
  return insert_row($pdo, $qt, $data);
}

/* ------------- Create RFQ if new ------------- */
if (!$is_edit) {
  if ($inquiry_id <= 0) { http_response_code(400); exit('inquiry_id required'); }
  $inq = $pdo->prepare("SELECT id, project_id, inquiry_no FROM inquiries WHERE id=?");
  $inq->execute([$inquiry_id]);
  $INQ = $inq->fetch(PDO::FETCH_ASSOC);
  if (!$INQ) { http_response_code(404); exit('Inquiry not found'); }

  $rfq_no = next_no('RFQ');
  $id = insert_row($pdo, 'rfqs', [
    'rfq_no'     => $rfq_no,
    'inquiry_id' => (int)$INQ['id'],
    'project_id' => (int)$INQ['project_id'],
    'rfq_date'   => date('Y-m-d'),
    'status'     => 'draft',
  ]);
  header("Location: rfq_form.php?id=".$id);
  exit;
}

/* ------------- Load RFQ ------------- */
$rfq = $pdo->prepare("SELECT * FROM rfqs WHERE id=?");
$rfq->execute([$id]);
$RFQ = $rfq->fetch(PDO::FETCH_ASSOC);
if (!$RFQ) { http_response_code(404); exit('RFQ not found'); }

$inquiry_id = (int)$RFQ['inquiry_id'];
$inqNoStmt = $pdo->prepare("SELECT inquiry_no FROM inquiries WHERE id=?");
$inqNoStmt->execute([$inquiry_id]);
$inquiry_no = (string)($inqNoStmt->fetchColumn() ?: '');

/* suppliers allowed from inquiry */
$allowedSuppliers = get_inquiry_suppliers($pdo, $inquiry_id);
$allowedIds = array_map(fn($r)=>(int)$r['id'], $allowedSuppliers);

/* ------------- POST handlers ------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // Save RFQ header
  if (isset($_POST['save_rfq'])) {
    $payload = [
      'rfq_date'   => normalize_date_in($_POST['rfq_date'] ?? date('Y-m-d')) ?? date('Y-m-d'),
      'valid_till' => normalize_date_in($_POST['valid_till'] ?? '') ?: null,
      'status'     => $_POST['status'] ?? 'draft',
      'remarks'    => $_POST['remarks'] ?? ($_POST['notes'] ?? null),
    ];
    update_row($pdo, 'rfqs', $id, $payload);
    header("Location: rfq_form.php?id=".$id."&ok=Saved");
    exit;
  }

  // Add supplier & open quote (restricted to inquiry recipients)
  if (isset($_POST['add_supplier'])) {
    $supplier_id  = (int)($_POST['supplier_id'] ?? 0);
    $supplier_note = trim((string)($_POST['supplier_note'] ?? ''));

    if ($supplier_id <= 0) {
      header("Location: rfq_form.php?id=".$id."&err=supplier_required");
      exit;
    }
    if (!in_array($supplier_id, $allowedIds, true)) {
      header("Location: rfq_form.php?id=".$id."&err=supplier_not_in_inquiry");
      exit;
    }

    try {
      // Link in rfq_suppliers if exists (idempotent)
      if (table_exists($pdo, 'rfq_suppliers')) {
        $chk = $pdo->prepare("SELECT id FROM rfq_suppliers WHERE rfq_id=? AND supplier_id=? LIMIT 1");
        $chk->execute([$id, $supplier_id]);
        if (!$chk->fetchColumn()) {
          insert_row($pdo, 'rfq_suppliers', [
            'rfq_id'      => $id,
            'supplier_id' => $supplier_id,
            'remarks'     => $supplier_note,
            'created_at'  => date('Y-m-d H:i:s'),
          ]);
        }
      }

      // Create or open quote
      $quote_id = open_or_create_quote($pdo, $id, $supplier_id, $supplier_note);
      header("Location: quotes_form.php?id=".$quote_id."&ok=Quote+ready");
      exit;

    } catch (Throwable $e) {
      $msg = 'Failed to open/create quote: '.substr($e->getMessage(), 0, 160);
      header("Location: rfq_form.php?id=".$id."&err=".rawurlencode($msg));
      exit;
    }
  }
}

/* ------------- Data for view ------------- */
$rfqSuppliers = [];
if (table_exists($pdo, 'rfq_suppliers')) {
  $rs = $pdo->prepare("SELECT rs.*, p.name AS supplier_name 
                       FROM rfq_suppliers rs 
                       JOIN parties p ON p.id = rs.supplier_id
                       WHERE rs.rfq_id=? ORDER BY rs.id DESC");
  $rs->execute([$id]);
  $rfqSuppliers = $rs->fetchAll(PDO::FETCH_ASSOC);
}

/* format dates for <input type="date"> (needs YYYY-mm-dd) */
$rfq_date_val   = normalize_date_in($RFQ['rfq_date'] ?? '') ?? date('Y-m-d');
$valid_till_val = normalize_date_in($RFQ['valid_till'] ?? '') ?? '';

include __DIR__.'/../ui/layout_start.php';
?>
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">RFQ: <?= htmlspecialchars($RFQ['rfq_no'] ?? ('#'.$id)) ?></h3>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="rfq_list.php">Back to RFQs</a>
      <span class="text-muted small">Inquiry: <?= htmlspecialchars($inquiry_no) ?></span>
    </div>
  </div>

  <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success py-2"><?= htmlspecialchars($_GET['ok']) ?></div>
  <?php elseif (isset($_GET['err'])): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($_GET['err']) ?></div>
  <?php endif; ?>

  <form method="post" class="card mb-4">
    <div class="card-header"><strong>Header</strong></div>
    <div class="card-body row g-3">
      <div class="col-md-3">
        <label class="form-label">RFQ Date</label>
        <input type="date" name="rfq_date" class="form-control" value="<?= htmlspecialchars($rfq_date_val) ?>">
        <div class="form-text">You can also type <code>dd-mm-yyyy</code>; it will be saved correctly.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Valid Till</label>
        <input type="date" name="valid_till" class="form-control" value="<?= htmlspecialchars($valid_till_val) ?>">
        <div class="form-text">Optional. Accepts <code>dd-mm-yyyy</code> too.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php
          $status = $RFQ['status'] ?? 'draft';
          foreach (['draft','issued','closed','cancelled'] as $opt) {
            $sel = $opt === $status ? 'selected' : '';
            echo "<option value=\"{$opt}\" {$sel}>".ucfirst($opt)."</option>";
          }
          ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label">Remarks</label>
        <textarea name="remarks" class="form-control" rows="2"><?= htmlspecialchars($RFQ['remarks'] ?? '') ?></textarea>
        <div class="form-text">Saved to <code>remarks</code> if present in DB; otherwise ignored.</div>
      </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
      <button class="btn btn-primary" name="save_rfq" value="1">Save</button>
    </div>
  </form>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Suppliers (from Inquiry recipients)</strong>
      <?php if (!$allowedSuppliers): ?>
        <a class="btn btn-sm btn-outline-primary" href="inquiry_build.php?id=<?= (int)$inquiry_id ?>">Add Suppliers in Inquiry</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="post" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="add_supplier" value="1">
        <div class="col-md-6">
          <label class="form-label">Supplier</label>
          <select name="supplier_id" class="form-select" <?= $allowedSuppliers ? '' : 'disabled' ?>>
            <option value="">-- Select from Inquiry --</option>
            <?php foreach ($allowedSuppliers as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (ID <?= (int)$s['id'] ?>)</option>
            <?php endforeach; ?>
          </select>
          <?php if (!$allowedSuppliers): ?>
            <div class="form-text text-danger">No suppliers found in Inquiry. Add them in Inquiry first.</div>
          <?php else: ?>
            <div class="form-text">Only suppliers you sent the Inquiry to are listed.</div>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Supplier Note</label>
          <input type="text" name="supplier_note" class="form-control" placeholder="Optional note for supplier/quote">
        </div>
        <div class="col-md-2">
          <button class="btn btn-success w-100" <?= $allowedSuppliers ? '' : 'disabled' ?>>Add & Open Quote</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Supplier</th>
              <th>Remarks</th>
              <th>Added</th>
              <th>Quote</th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$rfqSuppliers): ?>
              <tr><td colspan="5" class="text-muted">No suppliers linked yet.</td></tr>
            <?php else: foreach($rfqSuppliers as $row): ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= htmlspecialchars($row['supplier_name'] ?? ('ID '.$row['supplier_id'])) ?></td>
                <td><?= htmlspecialchars($row['remarks'] ?? '') ?></td>
                <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                <td>
                  <?php
                  $qt = quotes_table($pdo);
                  $q = $pdo->prepare("SELECT id FROM {$qt} WHERE rfq_id=? AND supplier_id=? LIMIT 1");
                  $q->execute([$id, (int)$row['supplier_id']]);
                  $qid = (int)($q->fetchColumn() ?: 0);
                  if ($qid): ?>
                    <a class="btn btn-sm btn-outline-primary" href="quotes_form.php?id=<?= $qid ?>">Open Quote</a>
                  <?php else: ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="add_supplier" value="1">
                      <input type="hidden" name="supplier_id" value="<?= (int)$row['supplier_id'] ?>">
                      <button class="btn btn-sm btn-outline-secondary">Create Quote</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
<?php include __DIR__.'/../ui/layout_end.php';
