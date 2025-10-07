<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login(); require_permission('sales.quote.view');
header('Content-Type: application/json; charset=utf-8');
$code = trim((string)($_GET['code'] ?? ''));
if ($code===''){ echo json_encode(['ok'=>true]); exit; }
$st = db()->prepare("SELECT item_code, min_price, max_discount_pct, warning_text FROM quote_item_policies WHERE item_code=:c");
$st->execute([':c'=>$code]); $r=$st->fetch(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>true,'policy'=>$r ?: null], JSON_UNESCAPED_UNICODE);