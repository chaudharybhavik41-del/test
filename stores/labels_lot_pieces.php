<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login(); require_permission('stores.labels.print');
$pdo = db();
$pieceIds = array_filter(array_map('intval', explode(',', $_GET['pieces'] ?? '')));
if (!$pieceIds) { echo "No pieces selected"; exit; }
$in = implode(',', array_fill(0, count($pieceIds), '?'));
$st = $pdo->prepare("SELECT lp.id, lp.piece_code, lp.qty_base, sl.heat_no, sl.plate_no, sl.item_id, sl.warehouse_id
                     FROM lot_pieces lp JOIN stock_lots sl ON sl.id = lp.lot_id WHERE lp.id IN ($in)");
$st->execute($pieceIds); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"/><title>Piece Labels</title>
<style>body{font-family:Arial} .label{width:90mm;height:50mm;border:1px solid #333;margin:8px;padding:8px;display:inline-block}
.h{font-weight:bold;font-size:14px}.qr{float:right;width:80px;height:80px}.meta{font-size:12px}</style>
</head><body><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<?php foreach ($rows as $r): ?><div class="label">
<div class="h">Piece: <?= htmlspecialchars($r['piece_code']) ?></div>
<div class="meta">Heat: <?= htmlspecialchars($r['heat_no'] ?? '-') ?> &nbsp; Plate: <?= htmlspecialchars($r['plate_no'] ?? '-') ?><br/>
KG: <?= number_format((float)$r['qty_base'], 3) ?><br/>Piece ID: <?= (int)$r['id'] ?></div>
<div id="qr<?= (int)$r['id'] ?>" class="qr"></div>
<script>new QRCode(document.getElementById("qr<?= (int)$r['id'] ?>"),{text:"PIECE:<?= (int)$r['id'] ?>",width:80,height:80});</script>
<div style="clear:both"></div></div><?php endforeach; ?></body></html>
