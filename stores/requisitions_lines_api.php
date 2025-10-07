<?php
/** PATH: /public_html/stores/requisitions_lines_api.php */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_permission('stores.req.view');
header('Content-Type: application/json');

try {
  $pdo = db();
  $req_id = (int)($_GET['req_id'] ?? 0);
  if ($req_id<=0) throw new RuntimeException('req_id required');

  // Hard-select material_code for items; tolerate uom without code
  $sql = "SELECT mri.*,
                 i.material_code AS item_code, i.name AS item_name,
                 COALESCE(u.code, u.name) AS uom_code
          FROM material_requisition_items mri
          JOIN items i ON i.id = mri.item_id
          JOIN uom u   ON u.id = mri.uom_id
          WHERE mri.req_id = ?
          ORDER BY mri.id";
  $st = $pdo->prepare($sql);
  $st->execute([$req_id]);
  $lines = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true, 'lines'=>$lines], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
