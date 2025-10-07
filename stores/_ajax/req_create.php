<?php
/** PATH: /public_html/stores/_ajax/req_create.php */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/services/NumberingService.php';

header('Content-Type: application/json; charset=utf-8');

function read_input_mixed(): array {
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    $raw = file_get_contents('php://input') ?: '';

    // Try JSON first when Content-Type hints JSON or raw body exists
    if ($raw !== '') {
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($data)) return $data;
        } catch (Throwable $e) {
            // fall through to form parsing
        }
    }

    // Fallback to application/x-www-form-urlencoded or form POST
    if (!empty($_POST)) {
        // Expect flat fields + items[] JSON or parallel arrays
        $data = $_POST;

        // If items is string JSON, decode; else try to build from arrays
        if (isset($_POST['items']) && is_string($_POST['items'])) {
            $items = json_decode($_POST['items'], true);
            if (is_array($items)) $data['items'] = $items;
        } elseif (isset($_POST['item_id'], $_POST['uom_id'], $_POST['qty_requested'])) {
            // Parallel arrays: item_id[], uom_id[], qty_requested[], remarks[]
            $items = [];
            $n = max(count((array)$_POST['item_id']), count((array)$_POST['uom_id']), count((array)$_POST['qty_requested']));
            for ($i=0; $i<$n; $i++) {
                $items[] = [
                    'item_id' => (int)($_POST['item_id'][$i] ?? 0),
                    'uom_id'  => (int)($_POST['uom_id'][$i] ?? 0),
                    'qty_requested' => (float)($_POST['qty_requested'][$i] ?? 0),
                    'remarks' => trim((string)($_POST['remarks'][$i] ?? '')),
                ];
            }
            $data['items'] = $items;
        }
        return $data;
    }

    // Nothing usable
    return [];
}

try {
    require_permission('stores.req.manage');
    $pdo = db();

    $data = read_input_mixed();
    if (!$data) {
        throw new RuntimeException('No input received. Send JSON or form fields.');
    }

    $project_id = isset($data['project_id']) && (int)$data['project_id'] > 0 ? (int)$data['project_id'] : null;
    $requested_by_type = ($data['requested_by_type'] ?? 'staff') === 'contractor' ? 'contractor' : 'staff';
    $requested_by_id = (int)($data['requested_by_id'] ?? 0);
    if ($requested_by_id <= 0) throw new RuntimeException('requested_by_id required (staff/contractor id).');

    $items = $data['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        throw new RuntimeException('No items provided (items[] required).');
    }

    $req_no = NumberingService::next($pdo, 'REQ');
    $user_id = (int)($_SESSION['user_id'] ?? 0);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
      INSERT INTO material_requisitions
      (req_no, project_id, requested_by_type, requested_by_id, requested_date, status, remarks, created_by)
      VALUES (?, ?, ?, ?, CURRENT_DATE, 'requested', ?, ?)
    ");
    $stmt->execute([
        $req_no,
        $project_id,
        $requested_by_type,
        $requested_by_id,
        trim((string)($data['remarks'] ?? '')),
        $user_id
    ]);
    $req_id = (int)$pdo->lastInsertId();

    $lineIns = $pdo->prepare("
      INSERT INTO material_requisition_items
      (req_id, item_id, uom_id, qty_requested, qty_issued, remarks)
      VALUES (?, ?, ?, ?, 0, ?)
    ");
    foreach ($items as $it) {
        $item_id = (int)($it['item_id'] ?? 0);
        $uom_id  = (int)($it['uom_id'] ?? 0);
        $qty_req = (float)($it['qty_requested'] ?? 0);
        $lrmk    = trim((string)($it['remarks'] ?? ''));
        if ($item_id <= 0 || $uom_id <= 0 || $qty_req <= 0) {
            throw new RuntimeException('Invalid line (item_id/uom_id/qty_requested).');
        }
        $lineIns->execute([$req_id, $item_id, $uom_id, $qty_req, $lrmk]);
    }

    audit_log($pdo, 'material_requisitions', 'create', $req_id, json_encode($data));
    $pdo->commit();

    echo json_encode(['ok' => true, 'req_id' => $req_id, 'req_no' => $req_no], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
