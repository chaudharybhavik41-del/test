<?php
declare(strict_types=1);
/** PATH: /public_html/api/machine_types_by_category.php */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
require_permission('machines.view');

$cid = (int)($_GET['category_id'] ?? 0);
$pdo = db();

// Detect the short code column on machine_types
$col = $pdo->query("SHOW COLUMNS FROM machine_types LIKE 'machine_code'")->fetch() ? 'machine_code' : 'code';

$stmt = $pdo->prepare("SELECT id, CONCAT($col,' - ',name) AS label FROM machine_types WHERE category_id=? ORDER BY $col");
$stmt->execute([$cid]);

header('Content-Type: application/json');
echo json_encode(array_map(fn($r)=>['id'=>(int)$r['id'],'label'=>$r['label']], $stmt->fetchAll(PDO::FETCH_ASSOC)));
