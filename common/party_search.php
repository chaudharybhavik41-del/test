<?php
/** PATH: /public_html/common/party_search.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  require_permission('parties.view');

  $q = trim((string)($_GET['q'] ?? ''));
  $type = trim((string)($_GET['type'] ?? '')); // optional: 'client','supplier',...

  $pdo = db();
  $params = [];
  $where = "WHERE deleted_at IS NULL";
  if ($q !== '') {
    $where .= " AND (name LIKE :q OR code LIKE :q)";
    $params[':q'] = "%{$q}%";
  }
  if ($type !== '') {
    $where .= " AND type = :t";
    $params[':t'] = $type;
  }

  $sql = "SELECT id, code, name, type, city, state FROM parties $where ORDER BY name ASC LIMIT 20";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true, 'items'=>$rows]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}