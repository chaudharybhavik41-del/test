<?php
// PATH: /public_html/purchase/item_makes.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('purchase.indent.manage');

header('Content-Type: application/json; charset=utf-8');

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) { echo json_encode(['ok'=>true,'makes'=>[]]); exit; }

$pdo = db();
$sql = "
  SELECT m.id, m.name
  FROM item_makes im
  JOIN makes m ON m.id = im.make_id
  WHERE im.item_id = ? AND m.status = 'active'
  ORDER BY m.name
";
$st = $pdo->prepare($sql);
$st->execute([$itemId]);
echo json_encode(['ok'=>true,'makes'=>$st->fetchAll(PDO::FETCH_ASSOC)]);