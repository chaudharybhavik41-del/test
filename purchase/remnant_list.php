<?php
/** PATH: /public_html/purchase/remnant_list.php
 * BUILD: 2025-10-03T09:30:37 IST (Fix: qualify columns to avoid ambiguous 'status')
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_login(); $pdo = db();

$plate_id = (int)($_GET['plate_id'] ?? 0);
$item_id  = (int)($_GET['item_id']  ?? 0);
$min_t    = isset($_GET['min_t']) ? (float)$_GET['min_t'] : null;
$max_t    = isset($_GET['max_t']) ? (float)$_GET['max_t'] : null;
$heat     = trim((string)($_GET['heat'] ?? ''));
$hideDup  = isset($_GET['hide_dup']) ? (int)$_GET['hide_dup'] : 1;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

echo "<h2>Remnants</h2>";
echo "<form method='get'><input type='hidden' name='plate_id' value='".(int)$plate_id."'>";
echo "Item ID: <input name='item_id' value='".($item_id?:'')."'> ";
echo "Thk min: <input name='min_t' value='".($min_t!==null?$min_t:'')."'> ";
echo "Thk max: <input name='max_t' value='".($max_t!==null?$max_t:'')."'> ";
echo "Heat: <input name='heat' value='".h($heat)."'> ";
echo "Hide duplicate internal lot no <input type='checkbox' name='hide_dup' value='1' ".($hideDup?'checked':'')."> ";
echo "<button type='submit'>Filter</button></form>";

$where = ["s.status IN ('available','partial')"];
$params = [];
if ($item_id>0) { $where[]="s.item_id=?"; $params[]=$item_id; }
if ($min_t!==null) { $where[]="s.thickness_mm>=?"; $params[]=$min_t; }
if ($max_t!==null) { $where[]="s.thickness_mm<=?"; $params[]=$max_t; }
if ($heat!=='') { $where[]="(s.heat_no LIKE ?)"; $params[]='%'.$heat.'%'; }

$baseSql = "SELECT s.* FROM stock_lots s";
if ($hideDup) {
  // keep only latest row per internal_lot_no when duplicate internal numbers exist
  $baseSql .= " LEFT JOIN stock_lots newer ON newer.internal_lot_no = s.internal_lot_no AND newer.id > s.id";
  $where[] = "newer.id IS NULL";
}
$sql = $baseSql . " WHERE " . implode(" AND ", $where) . " ORDER BY s.id DESC LIMIT 300";

try {
  $st = $pdo->prepare($sql); $st->execute($params);
  echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Item</th><th>Thk</th><th>L</th><th>W</th><th>Avail mm²</th><th>Heat</th><th>Lot</th><th>Pick</th></tr>";
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr>";
    echo "<td>".(int)$r['id']."</td>";
    echo "<td>".(int)$r['item_id']."</td>";
    echo "<td>".(float)$r['thickness_mm']."</td>";
    echo "<td>".(float)$r['length_mm']."</td>";
    echo "<td>".(float)$r['width_mm']."</td>";
    echo "<td>".number_format((int)$r['available_area_mm2'])."</td>";
    echo "<td>".h((string)$r['heat_no'])."</td>";
    echo "<td>".h((string)$r['internal_lot_no'])."</td>";
    if ($plate_id>0) {
      echo "<td><a class='btn' href='plate_link_remnant.php?plate_id=".$plate_id."&lot_id=".(int)$r['id']."'>Pick</a></td>";
    } else {
      echo "<td>—</td>";
    }
    echo "</tr>";
  }
  echo "</table>";
} catch (Throwable $e) {
  echo "<div style='color:#b00'><b>Error:</b> ".h($e->getMessage())."</div>";
}
