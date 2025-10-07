// /public_html/purchase/items_search.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Accept either view or manage for searching items
if (!user_has_permission('purchase.indent.manage') && !user_has_permission('purchase.indent.view')) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

/* Search by material_code or name; force unicode collation to avoid 1267 */
$sql = "
  SELECT i.id, i.material_code, i.name,
         u.code AS uom_code
  FROM items i
  LEFT JOIN uom u ON u.id = i.uom_id
  WHERE (i.material_code COLLATE utf8mb4_unicode_ci LIKE :kw
         OR i.name COLLATE utf8mb4_unicode_ci LIKE :kw)
  ORDER BY i.name ASC
  LIMIT :lim
";
$st = $pdo->prepare($sql);
$kw = "%$q%";
$st->bindValue(':kw', $kw, PDO::PARAM_STR);
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$items = array_map(function($r){
  $label = trim(($r['material_code'] ?? '').' — '.($r['name'] ?? ''));
  if (!empty($r['uom_code'])) $label .= ' ['.$r['uom_code'].']';
  return ['id' => (int)$r['id'], 'label' => $label];
}, $rows);

echo json_encode(['ok' => true, 'items' => $items]);