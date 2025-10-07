<?php
/** PATH: /public_html/workorders/pwo_list_quick_actions.php */
declare(strict_types=1);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';
require_once $ROOT . '/includes/services/WorkOrderService.php';

require_login();
require_permission('workorders.manage');
csrf_require_token();

$pdo = db();

$action = $_GET['action'] ?? '';
if ($action === 'set_status') {
    $id = (int)($_GET['id'] ?? 0);
    $status = $_GET['status'] ?? '';
    try {
        WorkOrderService::setStatus($pdo, $id, $status);
        $_SESSION['flash'] = ['type'=>'success','msg'=>"PWO #{$id} set to {$status}."];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>$e->getMessage()];
    }
    redirect('/workorders/pwo_list.php');
    exit;
}

http_response_code(400);
echo "Unknown action";
