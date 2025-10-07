<?php
/** PATH: /public_html/purchase/plate_lot_scrap.php
 * PURPOSE: Mark a lot/remnant as scrapped with reason (keeps trace intact)
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$lot_id = isset($_GET['lot_id']) ? (int)$_GET['lot_id'] : 0;
if ($lot_id<=0) { http_response_code(400); echo "Missing lot_id"; exit; }

$st = $pdo->prepare("SELECT id, internal_lot_no, plate_no, heat_no, status, available_area_mm2 FROM stock_lots WHERE id=?");
$st->execute([$lot_id]); $lot = $st->fetch();
if (!$lot) { echo "Lot not found."; exit; }

$err=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $reason = trim($_POST['reason'] ?? 'scrapped');
  // Allow scrap from available/partial; block if already consumed/scrapped
  if (!in_array($lot['status'], ['available','partial'], true)) {
    $err = "This lot is {$lot['status']} and cannot be scrapped.";
  } else {
    $upd = $pdo->prepare("UPDATE stock_lots
                          SET status='scrapped', available_area_mm2=0, scrap_reason=?, scrap_at=NOW()
                          WHERE id=?");
    $upd->execute([$reason, $lot_id]);
    header('Location: remnant_list.php?status=scrapped');
    exit;
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Scrap Lot</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:18px}
    .wrap{max-width:700px;margin:0 auto}
    .row{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}
    .btn{display:inline-block;padding:7px 12px;border:1px solid #ccc;border-radius:8px;background:#fff;text-decoration:none;cursor:pointer}
  </style>
</head>
<body>
<div class="wrap">
  <h3>Scrap Lot <?= h($lot['internal_lot_no'] ?: ('LOT-'.$lot['id'])) ?></h3>
  <?php if ($err): ?><div style="color:#b00;"><?= h($err) ?></div><?php endif; ?>

  <div>Plate / Heat: <b><?= h(($lot['plate_no'] ?: '—').' / '.($lot['heat_no'] ?: '—')) ?></b></div>
  <div>Status: <b><?= h($lot['status']) ?></b></div>
  <div>Available area: <b><?= number_format((int)$lot['available_area_mm2']) ?> mm²</b></div>

  <form method="post" class="row" style="margin-top:12px">
    <input name="reason" placeholder="Reason for scrapping" style="min-width:420px">
    <button class="btn">Confirm Scrap</button>
    <a class="btn" href="remnant_list.php" style="margin-left:6px">Cancel</a>
  </form>
</div>
</body>
</html>