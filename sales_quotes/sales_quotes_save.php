<?php
/** PATH: /public_html/sales_quotes/sales_quotes_save.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/numbering.php';

require_login();
verify_csrf_or_die();

$pdo = db();

$id = (int)($_POST['id'] ?? 0);
$isEdit = $id > 0;
require_permission($isEdit ? 'sales.quote.edit' : 'sales.quote.create');

/* -------------------- collect header -------------------- */
$codeIn = trim((string)($_POST['code'] ?? ''));
$header = [
  'code' => ($codeIn === '' ? null : $codeIn),
  'quote_date' => (string)($_POST['quote_date'] ?? date('Y-m-d')),
  'valid_till' => (($_POST['valid_till'] ?? '') === '' ? null : (string)$_POST['valid_till']),
  'status' => (string)($_POST['status'] ?? 'Draft'),
  'party_id' => (($_POST['party_id'] ?? '') === '' ? null : (int)$_POST['party_id']),
  'party_contact_id' => (($_POST['party_contact_id'] ?? '') === '' ? null : (int)$_POST['party_contact_id']),
  'lead_id' => (($_POST['lead_id'] ?? '') === '' ? null : (int)$_POST['lead_id']),
  'currency' => (string)($_POST['currency'] ?? 'INR'),
  'notes' => trim((string)($_POST['notes'] ?? '')),
  'terms' => trim((string)($_POST['terms'] ?? '')),
  'use_site_as_bill_to' => isset($_POST['use_site_as_bill_to']) ? 1 : 0,
  'site_name' => trim((string)($_POST['site_name'] ?? '')),
  'site_gst_number' => trim((string)($_POST['site_gst_number'] ?? '')),
  'site_address_line1' => trim((string)($_POST['site_address_line1'] ?? '')),
  'site_address_line2' => trim((string)($_POST['site_address_line2'] ?? '')),
  'site_city' => trim((string)($_POST['site_city'] ?? '')),
  'site_state' => trim((string)($_POST['site_state'] ?? '')),
  'site_pincode' => trim((string)($_POST['site_pincode'] ?? '')),
];

/* -------------------- collect lines -------------------- */
$lineIds    = array_map('strval', $_POST['item_id'] ?? ($_POST['line_id'] ?? []));
$sl_no      = $_POST['sl_no'] ?? [];
$item_code  = $_POST['item_code'] ?? [];
$item_name  = $_POST['item_name'] ?? [];
$hsn_sac    = $_POST['hsn_sac'] ?? [];
$qty        = $_POST['qty'] ?? [];
$uom        = $_POST['uom'] ?? [];
$rate       = $_POST['rate'] ?? [];
$disc       = $_POST['discount_pct'] ?? [];
$tax        = $_POST['tax_pct'] ?? [];
$line_total = $_POST['line_total'] ?? [];

/* Build map for any codes present */
$codesWanted = [];
foreach ($item_code as $c) { $c = trim((string)$c); if ($c !== '') $codesWanted[$c] = true; }
$codeMap = [];
if ($codesWanted) {
  $in = implode(',', array_fill(0, count($codesWanted), '?'));
  $st = $pdo->prepare("SELECT code, name, hsn_sac, uom, rate_default, tax_pct_default
                       FROM quote_items
                       WHERE deleted_at IS NULL AND is_active=1 AND code IN ($in)");
  $st->execute(array_keys($codesWanted));
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $codeMap[(string)$r['code']] = [
      'name' => (string)$r['name'],
      'hsn'  => (string)($r['hsn_sac'] ?? ''),
      'uom'  => (string)($r['uom'] ?? ''),
      'rate' => (string)number_format((float)($r['rate_default'] ?? 0), 2, '.', ''),
      'tax'  => (string)number_format((float)($r['tax_pct_default'] ?? 0), 2, '.', ''),
    ];
  }
}

/* Build rows; keep row if name or code present (or qty*rate) */
$rows = [];
$N = max(count($sl_no), count($item_name), count($hsn_sac), count($qty),
         count($uom), count($rate), count($disc), count($tax), count($line_total), count($item_code));
for ($i=0; $i<$N; $i++) {
  $code = trim((string)($item_code[$i] ?? ''));
  $name = trim((string)($item_name[$i] ?? ''));
  $q = (float)($qty[$i] ?? 0);
  $rt = (float)($rate[$i] ?? 0);
  if ($name === '' && $code === '' && ($q*$rt) <= 0) continue;

  if ($name === '' && $code !== '' && isset($codeMap[$code])) {
    $name = $codeMap[$code]['name'];
    if (($hsn_sac[$i] ?? '') === '') $hsn_sac[$i] = $codeMap[$code]['hsn'];
    if (($uom[$i] ?? '') === '')     $uom[$i]     = $codeMap[$code]['uom'];
    if (($rate[$i] ?? '') === '' || (float)$rate[$i] == 0) $rate[$i] = $codeMap[$code]['rate'];
    if (($tax[$i]  ?? '') === '' )   $tax[$i]     = $codeMap[$code]['tax'];
  }

  $rows[] = [
    'id' => (int)($lineIds[$i] ?? 0),
    'sl_no' => (int)($sl_no[$i] ?? ($i+1)),
    'item_code' => ($code === '' ? null : $code),
    'item_name' => ($name === '' ? 'Item' : $name),
    'hsn_sac' => trim((string)($hsn_sac[$i] ?? '')),
    'qty' => (string)($qty[$i] ?? '1.000'),
    'uom' => trim((string)($uom[$i] ?? 'Nos')),
    'rate' => (string)($rate[$i] ?? '0.00'),
    'discount_pct' => (string)($disc[$i] ?? '0.00'),
    'tax_pct' => (string)($tax[$i] ?? '0.00'),
    'line_total' => (string)($line_total[$i] ?? '0.00'),
  ];
}

/* -------------------- totals (server-side) -------------------- */
$subtotal=0.00; $totalTax=0.00;
foreach ($rows as $r) {
  $base = (float)$r['qty'] * (float)$r['rate'];
  $afterDisc = $base * (1 - ((float)$r['discount_pct']/100));
  $lineTax = $afterDisc * ((float)$r['tax_pct']/100);
  $subtotal += $afterDisc; $totalTax += $lineTax;
}
$discountAbs = (float)($_POST['discount_amount'] ?? 0.00);
$roundOff    = (float)($_POST['round_off'] ?? 0.00);
$grandTotal  = $subtotal - $discountAbs + $totalTax + $roundOff;

$headerTotals = [
  'subtotal' => number_format($subtotal, 2, '.', ''),
  'discount_amount' => number_format($discountAbs, 2, '.', ''),
  'tax_amount' => number_format($totalTax, 2, '.', ''),
  'round_off' => number_format($roundOff, 2, '.', ''),
  'grand_total' => number_format($grandTotal, 2, '.', ''),
];

/* -------------------- TX guard -------------------- */
$_OWN_TX = !$pdo->inTransaction();
try {
  if ($_OWN_TX) $pdo->beginTransaction();

  /* -------- header upsert -------- */
  if (!$isEdit) {
    if ($header['code'] === null) $header['code'] = next_no('QO');
    $sql = "INSERT INTO sales_quotes
      (code, quote_date, valid_till, status, party_id, party_contact_id, lead_id,
       currency, notes, terms,
       use_site_as_bill_to, site_name, site_gst_number, site_address_line1, site_address_line2,
       site_city, site_state, site_pincode,
       subtotal, discount_amount, tax_amount, round_off, grand_total, created_at)
      VALUES
      (:code, :quote_date, :valid_till, :status, :party_id, :party_contact_id, :lead_id,
       :currency, :notes, :terms,
       :use_site_as_bill_to, :site_name, :site_gst_number, :site_address_line1, :site_address_line2,
       :site_city, :site_state, :site_pincode,
       :subtotal, :discount_amount, :tax_amount, :round_off, :grand_total, NOW())";
    $pdo->prepare($sql)->execute(array_merge($header, $headerTotals));
    $id = (int)$pdo->lastInsertId();
    audit_log($pdo, 'sales_quotes', 'create', $id, array_merge($header, $headerTotals));
    set_flash('success', 'Quote created.');
  } else {
    if ($header['code'] === null) {
      $cur = $pdo->prepare("SELECT code FROM sales_quotes WHERE id=?");
      $cur->execute([$id]);
      $curCode = (string)($cur->fetchColumn() ?: '');
      $header['code'] = $curCode !== '' ? $curCode : next_no('QO');
    }
    $header['id'] = $id;
    $sql = "UPDATE sales_quotes SET
      code=:code, quote_date=:quote_date, valid_till=:valid_till, status=:status,
      party_id=:party_id, party_contact_id=:party_contact_id, lead_id=:lead_id,
      currency=:currency, notes=:notes, terms=:terms,
      use_site_as_bill_to=:use_site_as_bill_to, site_name=:site_name, site_gst_number=:site_gst_number,
      site_address_line1=:site_address_line1, site_address_line2=:site_address_line2,
      site_city=:site_city, site_state=:site_state, site_pincode=:site_pincode,
      subtotal=:subtotal, discount_amount=:discount_amount, tax_amount=:tax_amount,
      round_off=:round_off, grand_total=:grand_total
      WHERE id=:id";
    $pdo->prepare($sql)->execute(array_merge($header, $headerTotals));
    audit_log($pdo, 'sales_quotes', 'update', $id, array_merge($header, $headerTotals));
    set_flash('success', 'Quote updated.');
  }

  /* -------- lines upsert (hard-sync) -------- */
  $st = $pdo->prepare("SELECT id FROM sales_quote_items WHERE quote_id=?");
  $st->execute([$id]);
  $existing = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
  $keepIds = [];

  $ins = $pdo->prepare("INSERT INTO sales_quote_items
    (quote_id, sl_no, item_code, item_name, hsn_sac, qty, uom, rate, discount_pct, tax_pct, line_total)
    VALUES
    (:quote_id, :sl_no, :item_code, :item_name, :hsn_sac, :qty, :uom, :rate, :discount_pct, :tax_pct, :line_total)");

  $upd = $pdo->prepare("UPDATE sales_quote_items SET
    sl_no=:sl_no, item_code=:item_code, item_name=:item_name, hsn_sac=:hsn_sac, qty=:qty, uom=:uom,
    rate=:rate, discount_pct=:discount_pct, tax_pct=:tax_pct, line_total=:line_total
    WHERE id=:id AND quote_id=:quote_id");

  foreach ($rows as $r) {
    $payload = [
      'quote_id' => $id,
      'sl_no' => (int)$r['sl_no'],
      'item_code' => $r['item_code'],
      'item_name' => $r['item_name'],
      'hsn_sac' => $r['hsn_sac'],
      'qty' => (string)$r['qty'],
      'uom' => $r['uom'],
      'rate' => (string)$r['rate'],
      'discount_pct' => (string)$r['discount_pct'],
      'tax_pct' => (string)$r['tax_pct'],
      'line_total' => (string)$r['line_total'],
    ];

    if ($r['id'] > 0 && in_array($r['id'], $existing, true)) {
      $payloadUpd = $payload; $payloadUpd['id'] = (int)$r['id'];
      $upd->execute($payloadUpd);
      $keepIds[] = (int)$r['id'];
    } else {
      $ins->execute($payload);
      $keepIds[] = (int)$pdo->lastInsertId();
    }
  }

  $toDelete = array_diff($existing, $keepIds);
  if ($toDelete) {
    $in = implode(',', array_fill(0, count($toDelete), '?'));
    $pdo->prepare("DELETE FROM sales_quote_items WHERE quote_id=? AND id IN ($in)")
        ->execute(array_merge([$id], array_values($toDelete)));
  }

  if ($_OWN_TX) $pdo->commit();
  header('Location: sales_quotes_form.php?id='.$id);
  exit;

} catch (Throwable $e) {
  if ($_OWN_TX && $pdo->inTransaction()) $pdo->rollBack();
  set_flash('danger', $e->getMessage());
  header('Location: sales_quotes_form.php'.($isEdit?('?id='.$id):'')); exit;
}
