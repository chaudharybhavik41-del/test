<?php
/** PATH: /public_html/purchase/items_options.php — returns items list (by subcategory or all), schema-tolerant */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('purchase.indent.manage');

header('Content-Type: application/json; charset=utf-8');

function col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

  // 0 or missing => no subcategory filter (All Items)
  $subcatId = (int)($_GET['subcategory_id'] ?? 0);

  // Active/status filter if present
  $activeWhere = '1=1';
  if (col_exists($pdo, 'items', 'status')) {
    $activeWhere = "COALESCE(items.status,'active')='active'";
  } elseif (col_exists($pdo, 'items', 'active')) {
    $activeWhere = "COALESCE(items.active,1)=1";
  }

  $where = [$activeWhere];
  $params = [];

  if ($subcatId > 0 && col_exists($pdo, 'items', 'subcategory_id')) {
    $where[] = 'items.subcategory_id = ?';
    $params[] = $subcatId;
  }

  $codeCol = col_exists($pdo, 'items', 'code') ? 'items.code' : "''";
  $nameCol = col_exists($pdo, 'items', 'name') ? 'items.name' : "''";
  $label   = "CONCAT(COALESCE($codeCol,''), CASE WHEN COALESCE($codeCol,'')<>'' AND COALESCE($nameCol,'')<>'' THEN ' — ' ELSE '' END, COALESCE($nameCol,''))";

  $orderParts = [];
  if (col_exists($pdo,'items','code')) $orderParts[] = 'items.code';
  if (col_exists($pdo,'items','name')) $orderParts[] = 'items.name';
  if (!$orderParts) $orderParts[] = 'items.id';
  $order = implode(', ', $orderParts);

  $sql = "SELECT items.id, $label AS label
          FROM items
          WHERE ".implode(' AND ', $where)."
          ORDER BY $order
          LIMIT 1000";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true,'items'=>$items]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
