<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();

  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

  $inquiry_id = (int)($_GET['inquiry_id'] ?? 0);
  $supplier_id = (int)($_GET['supplier_id'] ?? 0);
  if ($inquiry_id<=0 || $supplier_id<=0) {
    http_response_code(400);
    echo json_encode(['error'=>'inquiry_id and supplier_id required']); exit;
  }

  // Pull inquiry lines
  $sql = "SELECT ii.id AS inquiry_item_id, ii.item_id, ii.qty, ii.uom_id, ii.needed_by, ii.line_notes,
                 it.material_code, it.name AS item_name, u.code AS uom_code
          FROM inquiry_items ii
          JOIN items it ON it.id=ii.item_id
          JOIN uom u ON u.id=ii.uom_id
          WHERE ii.inquiry_id=?";
  $st = $pdo->prepare($sql);
  $st->execute([$inquiry_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
