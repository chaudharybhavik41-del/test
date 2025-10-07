<?php
/** PATH: /public_html/bom/routing_pwo_actions.php
 * Small handler to ensure a PWO exists for a routing op.
 */
declare(strict_types=1);
$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';
require_once $ROOT . '/includes/rbac.php';
require_once $ROOT . '/includes/csrf.php';
require_once $ROOT . '/includes/helpers.php';
require_once $ROOT . '/includes/services/WorkOrderService.php';

/* ---- Polyfills ---- */
if (!function_exists('redirect')) { function redirect(string $url): void { header('Location: '.$url); exit; } }
if (!function_exists('csrf_token')) { function csrf_token(): string { return $_GET['csrf'] ?? ''; } } // best-effort

require_login();
require_permission('workorders.manage');

$pdo = db();

$action = $_GET['action'] ?? '';
if ($action === 'ensure') {
    // best-effort CSRF
    if (function_exists('csrf_token')) {
        $tok = $_GET['csrf'] ?? '';
        if ($tok !== csrf_token()) { http_response_code(400); echo "Invalid CSRF token"; exit; }
    }
    $routing_op_id = (int)($_GET['p'] ?? 0);
    $bom_id        = (int)($_GET['bom_id'] ?? 0);
    $component_id  = (int)($_GET['component_id'] ?? 0);

    try {
        $res = WorkOrderService::ensurePwoForRouting($pdo, $routing_op_id, []);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'PWO #'.$res['pwo_id'].' ensured for op.'];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>$e->getMessage()];
    }
    redirect('/bom/routing_form.php?bom_id='.$bom_id.'&component_id='.$component_id);
    exit;
}

http_response_code(400);
echo "Unknown action";
