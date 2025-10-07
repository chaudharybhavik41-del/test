<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min((int)($_GET['limit'] ?? 20), 50));
if ($q === '') { echo json_encode([]); exit; }

$pdo = db();
$st = $pdo->prepare("
  SELECT name, uom, rate_default, tax_pct_default
  FROM quote_items
  WHERE deleted_at IS NULL AND is_active = 1
    AND (name LIKE :kw OR code LIKE :kw)
  ORDER BY name ASC
  LIMIT {$limit}
");
$st->execute([':kw' => "%{$q}%"]);
echo json_encode($st->fetchAll(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_UNICODE);
