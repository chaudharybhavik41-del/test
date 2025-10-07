<?php
/** PATH: /public_html/stores/_ajax/pa_to_indent.php */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/services/NumberingService.php';

require_permission('purchase.indent.manage'); // adjust if your RBAC key differs
header('Content-Type: application/json; charset=utf-8');

$pdo = null;
try {
  $pdo = db();
  $in = json_decode(file_get_contents('php://input') ?: '[]', true);
  if (!$in) throw new RuntimeException('No input');

  $advice_id = (int)($in['advice_id'] ?? 0);
  if ($advice_id <= 0) throw new RuntimeException('advice_id required');

  // PA header (NOTE: no project_id column in your PA table)
  $h = $pdo->prepare("
    SELECT id, advice_no, advice_date, warehouse_id, status, remarks
    FROM purchase_advice
    WHERE id = ?
  ");
  $h->execute([$advice_id]);
  $pa = $h->fetch(PDO::FETCH_ASSOC);
  if (!$pa) throw new RuntimeException('Purchase Advice not found');

  // Acceptable statuses (block only cancelled)
  if ($pa['status'] === 'cancelled') {
    throw new RuntimeException('Advice is cancelled');
  }

  // PA lines (use suggested_qty > 0)
  $ls = $pdo->prepare("
    SELECT item_id, uom_id, suggested_qty, remarks
    FROM purchase_advice_items
    WHERE advice_id = ? AND suggested_qty > 0
  ");
  $ls->execute([$advice_id]);
  $lines = $ls->fetchAll(PDO::FETCH_ASSOC);
  if (!$lines) throw new RuntimeException('No suggested lines to convert');

  $user = (int)($_SESSION['user_id'] ?? 0);
  if ($user <= 0) throw new RuntimeException('Not authenticated');

  $pdo->beginTransaction();

  // Number
  $indent_no = NumberingService::next($pdo, 'IND');

  // Insert INDENT header — match your `indents` schema
  // Columns present: indent_no, project_id (NULL), required_by (NULL), remarks, priority, delivery_location_id (NULL), status, created_by
  $pdo->prepare("
    INSERT INTO indents
      (indent_no, project_id, required_by, remarks, priority, delivery_location_id, status, created_by)
    VALUES
      (?, NULL, NULL, ?, 'normal', NULL, 'draft', ?)
  ")->execute([$indent_no, $pa['remarks'] ?: null, $user]);

  $indent_id = (int)$pdo->lastInsertId();

  // Insert lines — match your `indent_items` schema
  $ins = $pdo->prepare("
    INSERT INTO indent_items
      (indent_id, item_id, make_id, description, qty, uom_id, needed_by, remarks, sort_order)
    VALUES
      (?, ?, NULL, NULL, ?, ?, NULL, ?, ?)
  ");

  $n = 1;
  foreach ($lines as $L) {
    $item_id = (int)$L['item_id'];
    $qty     = (float)$L['suggested_qty'];
    $uom_id  = $L['uom_id'] !== null ? (int)$L['uom_id'] : null;
    if ($item_id <= 0 || $qty <= 0) continue;

    $ins->execute([$indent_id, $item_id, $qty, $uom_id, $L['remarks'] ?: null, $n++]);
  }

  // Optional: mark PA as 'approved' stays; or set another flag if you like (not changing status here)
  // $pdo->prepare("UPDATE purchase_advice SET status='approved' WHERE id=?")->execute([$advice_id]);

  // Audit trail
  if (function_exists('audit_log_add')) {
    audit_log_add($pdo, $user, 'indents', $indent_id, 'create_from_advice', ['advice_id' => $advice_id], null);
  } elseif (function_exists('audit_log')) {
    audit_log($pdo, 'indents', 'create_from_advice', $indent_id, json_encode(['advice_id' => $advice_id]));
  }

  $pdo->commit();
  echo json_encode(['ok' => true, 'indent_id' => $indent_id, 'indent_no' => $indent_no]);

} catch (Throwable $e) {
  if ($pdo?->inTransaction()) $pdo->rollBack();
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
