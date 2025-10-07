<?php
/** PATH: /public_html/purchase/bom_items_options.php
 * PURPOSE: Provide Raw-Material family items for BOM (JSON)
 * RESPONSE: { ok: bool, items: [{id,label}] }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

header('Content-Type: application/json; charset=utf-8');

function out($ok, $items = []) {
  echo json_encode(['ok'=>$ok, 'items'=>$items], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // helpers
  $tbl = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
  $col = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $t = function(string $name) use($tbl){ $tbl->execute([$name]); return (bool)$tbl->fetchColumn(); };
  $c = function(string $tname, string $cn) use($col){ $col->execute([$tname,$cn]); return (bool)$col->fetchColumn(); };

  if (!$t('items')) out(true, []); // nothing to list

  $subcat_id = isset($_GET['subcategory_id']) ? (int)$_GET['subcategory_id'] : 0;

  $hasMC = $t('material_categories');
  $hasMS = $t('material_subcategories');

  // Active filter: handle various "status" shapes
  $hasItemStatus = $c('items','status');
  $whereActive = $hasItemStatus ? "(i.status IS NULL OR i.status IN ('active',1))" : "1=1";

  if ($hasMC) {
    // Base joins
    $base = "
      FROM items i
      LEFT JOIN material_categories mc ON mc.id = i.category_id
    ";
    $joinSub = $hasMS ? "LEFT JOIN material_subcategories ms ON ms.id = i.subcategory_id" : "";

    // Raw family detection:
    // - Category code/name: RM / 'raw material'
    // - Also tolerate catalogs that park plates/sections under PLATE/SECTION cats
    $rawCat = "(UPPER(mc.code)='RM' OR LOWER(mc.name) LIKE '%raw material%')";
    $rawPlus = " OR (UPPER(mc.code) IN ('PLATE','SECTION') OR LOWER(mc.name) REGEXP 'plate|section') ";
    $rawFilter = "($rawCat $rawPlus)";

    $params = [];
    $subFilter = "";
    if ($subcat_id > 0 && $hasMS) {
      $subFilter = " AND i.subcategory_id = ? ";
      $params[] = $subcat_id;
    } // subcat_id==0 means "All Raw" → no extra filter

    $sql = "
      SELECT i.id, CONCAT(i.material_code, ' — ', i.name) AS label
      $base
      $joinSub
      WHERE $whereActive
        AND $rawFilter
        $subFilter
      ORDER BY i.material_code, i.name
      LIMIT 2000
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    out(true, $items);
  }

  // Fallback: no category tables → dump all items
  $sql = "SELECT id, CONCAT(material_code,' — ',name) AS label FROM items WHERE $whereActive ORDER BY material_code, name LIMIT 2000";
  $items = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  out(true, $items);

} catch (Throwable $e) {
  out(false, []);
}
