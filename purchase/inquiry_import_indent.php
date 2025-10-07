<?php
/** PATH: /public_html/purchase/inquiry_import_indent.php — export indent lines for Inquiry import */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('purchase.inquiry.manage');

header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

  $indent_id = (int)($_GET['indent_id'] ?? 0);
  if ($indent_id <= 0) { echo json_encode([]); exit; }

  // Pull indent header (to get project_id, optional)
  $h = $pdo->prepare("SELECT id, project_id, indent_no FROM indents WHERE id=?");
  $h->execute([$indent_id]);
  $hdr = $h->fetch(PDO::FETCH_ASSOC) ?: ['project_id'=>null, 'indent_no'=>null];

  // Lines with item + uom and subcategory_id
  $sql = "
    SELECT 
      li.id              AS indent_item_id,
      li.indent_id,
      li.item_id,
      it.subcategory_id,              -- << add subcategory for cascading item select
      li.make_id,
      li.qty,
      li.uom_id,
      li.needed_by,
      li.remarks         AS line_notes
    FROM indent_items li
    JOIN items it ON it.id = li.item_id
    WHERE li.indent_id = ?
    ORDER BY li.sort_order, li.id
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$indent_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // Attach project info on each row for convenience
  foreach ($rows as &$r) {
    $r['project_id'] = $hdr['project_id'] ?? null;
    $r['indent_no']  = $hdr['indent_no']  ?? null;
  }

  echo json_encode($rows);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([]);
}
