<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';

require_login();
require_permission('purchase.quote.lock');

if (($_POST['_action'] ?? '') !== 'lock') { http_response_code(405); echo "Method not allowed"; exit; }

$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$inquiry_id = (int)($_POST['inquiry_id'] ?? 0);
$selection = json_decode($_POST['selection_json'] ?? '[]', true) ?: [];
if ($inquiry_id<=0) { http_response_code(400); echo "Missing inquiry_id"; exit; }

$now = date('Y-m-d H:i:s');

try {
  $pdo->beginTransaction();

  // Fetch stamp values per line from quote items
  $fetchCI = $pdo->prepare("SELECT unit_price, discount_percent, tax_percent, delivery_days
                            FROM inquiry_quote_items
                            WHERE quote_id=? AND (src IS NULL OR src='CI') AND inquiry_item_id=?");
  $fetchRM = $pdo->prepare("SELECT unit_price, discount_percent, tax_percent, delivery_days
                            FROM inquiry_quote_items
                            WHERE quote_id=? AND src='RMI' AND inquiry_line_id=?");

  // Upsert (requires the tiny SQL change to unique key on selections)
  $upsert = $pdo->prepare("
    INSERT INTO inquiry_quote_selections
      (inquiry_id, src, inquiry_item_id, inquiry_line_id, quote_id, supplier_id,
       unit_price, tax_percent, discount_percent, delivery_days, locked_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      quote_id=VALUES(quote_id),
      supplier_id=VALUES(supplier_id),
      unit_price=VALUES(unit_price),
      tax_percent=VALUES(tax_percent),
      discount_percent=VALUES(discount_percent),
      delivery_days=VALUES(delivery_days),
      locked_at=VALUES(locked_at)
  ");

  foreach ($selection as $s) {
    $src = (string)($s['src'] ?? 'CI');
    $qid = (int)($s['quote_id'] ?? 0);
    $sid = (int)($s['supplier_id'] ?? 0);
    $ii  = isset($s['inquiry_item_id']) ? (int)$s['inquiry_item_id'] : null;
    $il  = isset($s['inquiry_line_id']) ? (int)$s['inquiry_line_id'] : null;
    if ($qid<=0 || $sid<=0) continue;

    if ($src==='RMI') {
      if (!$il) continue;
      $fetchRM->execute([$qid,$il]);
      $row=$fetchRM->fetch(PDO::FETCH_ASSOC);
    } else {
      if (!$ii) continue;
      $fetchCI->execute([$qid,$ii]);
      $row=$fetchCI->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) continue;

    $upsert->execute([
      $inquiry_id, $src, ($src==='CI'?$ii:null), ($src==='RMI'?$il:null), $qid, $sid,
      (float)$row['unit_price'], (float)$row['tax_percent'], (float)$row['discount_percent'],
      !empty($row['delivery_days'])?(int)$row['delivery_days']:null, $now
    ]);
  }

  $pdo->commit();
  header('Location: /purchase/quotes_compare.php?inquiry_id='.$inquiry_id); exit;

} catch(Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500); echo "Lock failed: ".$e->getMessage();
}