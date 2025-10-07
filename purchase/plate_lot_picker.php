<?php
/** PATH: /public_html/purchase/plate_lot_picker.php
 * PURPOSE: Pick a remnant lot (stock_lots) and link it to a plate (plate_plan_plates).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();
$pdo = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$plate_id = isset($_GET['plate_id']) ? (int)$_GET['plate_id'] : 0;
if ($plate_id <= 0) { http_response_code(400); echo "Missing plate_id"; exit; }

/* Load plate header (needs thickness, item and plan) */
$st = $pdo->prepare("SELECT p.id, p.plan_id, p.item_id, p.thickness_mm, COALESCE(pl.plan_no,'') AS plan_no, pl.project_id
                     FROM plate_plan_plates p
                     LEFT JOIN plate_plans pl ON pl.id = p.plan_id
                     WHERE p.id=?");
$st->execute([$plate_id]);
$plate = $st->fetch();
if (!$plate) { echo "Plate not found."; exit; }

$plan_id   = (int)$plate['plan_id'];
$plan_no   = (string)$plate['plan_no'];
$item_id   = $plate['item_id'] ? (int)$plate['item_id'] : null;
$thickness = (float)$plate['thickness_mm'];

/* Filters */
$q        = trim($_GET['q'] ?? '');           // free text in grade/plate/heat
$min_area = (int)($_GET['min_area'] ?? 0);
$status   = $_GET['status'] ?? 'available';   // available/partial/all

/* Query lots (match thickness & optional same item/grade) */
$sql = "SELECT id, internal_lot_no, plate_no, heat_no, item_name, item_id,
               thickness_mm, available_area_mm2, origin_plan_no, status
        FROM stock_lots
        WHERE status IN ('available','partial')
          AND ABS(thickness_mm - ?) < 0.001";
$bind = [$thickness];

if ($item_id) { $sql .= " AND item_id = ?"; $bind[] = $item_id; }
if ($min_area > 0) { $sql .= " AND available_area_mm2 >= ?"; $bind[] = $min_area; }
if ($q !== '') {
  $sql .= " AND (item_name LIKE ? OR plate_no LIKE ? OR heat_no LIKE ?)";
  $like = "%{$q}%";
  array_push($bind, $like, $like, $like);
}
$sql .= " ORDER BY available_area_mm2 DESC, id DESC LIMIT 200";

$lots = $pdo->prepare($sql);
$lots->execute($bind);
$rows = $lots->fetchAll();

/* POST: link selected lot to plate */
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $chosen = (int)($_POST['lot_id'] ?? 0);
  if ($chosen > 0) {
    // sanity: ensure lot is still available
    $check = $pdo->prepare("SELECT id FROM stock_lots WHERE id=? AND status IN ('available','partial')");
    $check->execute([$chosen]);
    if ($check->fetchColumn()) {
      $upd = $pdo->prepare("UPDATE plate_plan_plates SET source_type='remnant', source_lot_id=? WHERE id=?");
      $upd->execute([$chosen, $plate_id]);
      header('Location: plate_plan_form.php?id='.$plan_id.'&ok=1');
      exit;
    } else {
      $err = "Selected lot is no longer available.";
    }
  } else {
    $err = "Please choose a lot to link.";
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Select Remnant Lot</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:18px}
    .wrap{max-width:1100px;margin:0 auto}
    table{border-collapse:collapse;width:100%}
    th,td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}
    .row{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}
    .btn{display:inline-block;padding:7px 12px;border:1px solid #ccc;border-radius:8px;background:#fff;text-decoration:none;cursor:pointer}
    .small{font-size:12px;color:#666}
  </style>
</head>
<body>
<div class="wrap">
  <h3>Select Remnant Lot for Plate #<?= (int)$plate_id ?> &nbsp;<span class="small">(Plan <?= h($plan_no ?: (string)$plan_id) ?>, Thk <?= (float)$thickness ?>)</span></h3>

  <form method="get" class="row">
    <input type="hidden" name="plate_id" value="<?= (int)$plate_id ?>">
    <input name="q" placeholder="search grade / plate / heat" value="<?= h($q) ?>" style="min-width:240px">
    <input name="min_area" type="number" placeholder="min area (mm²)" value="<?= $min_area ?>" style="width:180px">
    <button class="btn">Search</button>
    <a class="btn" href="plate_lot_picker.php?plate_id=<?= (int)$plate_id ?>">Reset</a>
    <a class="btn" href="plate_plan_form.php?id=<?= (int)$plan_id ?>">Back to Plan</a>
  </form>

  <?php if ($err): ?><div style="color:#b00;margin:8px 0;"><?= h($err) ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="plate_id" value="<?= (int)$plate_id ?>">
    <table>
      <thead>
        <tr>
          <th>Lot</th>
          <th>Plate / Heat</th>
          <th>Grade</th>
          <th>Thk</th>
          <th>Avail area</th>
          <th>Origin Plan</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h($r['internal_lot_no'] ?: ('LOT-'.$r['id'])) ?></td>
            <td><?= h(($r['plate_no'] ?: '—').' / '.($r['heat_no'] ?: '—')) ?></td>
            <td><?= h($r['item_name'] ?: '') ?></td>
            <td><?= (float)$r['thickness_mm'] ?></td>
            <td><?= number_format((int)$r['available_area_mm2']) ?></td>
            <td><?= h($r['origin_plan_no'] ?: '') ?></td>
            <td><button class="btn" name="lot_id" value="<?= (int)$r['id'] ?>">Use</button></td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr><td colspan="7">No suitable remnants found. Try clearing filters or add lots in Stores/GRN.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </form>
</div>
</body>
</html>