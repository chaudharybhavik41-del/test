<?php
/** PATH: /public_html/common/quote_item_get.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  require_permission('quote_items.view');

  $id = (int)($_GET['id'] ?? 0);
  $code = trim((string)($_GET['code'] ?? ''));
  if ($id <= 0 && $code === '') { echo json_encode(['ok'=>false,'message'=>'id or code required']); exit; }

  $pdo = db();
  if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM quote_items WHERE id=? AND deleted_at IS NULL");
    $st->execute([$id]);
  } else {
    $st = $pdo->prepare("SELECT * FROM quote_items WHERE code=? AND deleted_at IS NULL");
    $st->execute([$code]);
  }
  $row = $st->fetch(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true,'item'=>$row ?: null]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
