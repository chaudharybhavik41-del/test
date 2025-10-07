<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_permission('stores.cycle.manage');
$pdo = db();
$id = (int)($_GET['id'] ?? 0); if ($id <= 0) die('Invalid id');
$hdr = $pdo->prepare("SELECT * FROM cycle_counts WHERE id=:id"); $hdr->execute([':id'=>$id]); $hdr = $hdr->fetch(PDO::FETCH_ASSOC); if (!$hdr) die('Not found');
$lines = $pdo->prepare("SELECT cci.*, i.code AS item_code, i.name AS item_name, u.code AS uom_code
                        FROM cycle_count_items cci JOIN items i ON i.id = cci.item_id LEFT JOIN uoms u ON u.id = cci.uom_id
                        WHERE cci.cycle_id = :id ORDER BY line_no");
$lines->execute([':id'=>$id]); $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
$csrf = csrf_token();
?><!doctype html><html><head><meta charset="utf-8"><title>Cycle Count — Enter (<?=$hdr['cc_no']?>)</title><meta name="viewport" content="width=device-width, initial-scale=1">
<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px}.card{border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px}table{width:100%;border-collapse:collapse;font-size:14px}th,td{border-bottom:1px solid #eee;padding:8px;text-align:left}th{background:#fafafa}.right{text-align:right}.btn{display:inline-block;padding:8px 12px;border:1px solid #ccc;border-radius:6px;background:#fff;cursor:pointer;text-decoration:none;color:#111}.btn.primary{border-color:#2573ef;background:#2f7df4;color:#fff}input[type=number]{width:140px;padding:6px}input[type=text]{padding:6px;width:100%}</style></head><body>
<div class="card"><h2>Cycle Count — <?=$hdr['cc_no']?></h2><p>Warehouse ID: <?=$hdr['warehouse_id']?> | Bin: <?=htmlspecialchars((string)$hdr['bin_id'])?></p>
<form method="post" action="cycle_count_post.php"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="cycle_id" value="<?=$hdr['id']?>">
<table><thead><tr><th>#</th><th>Item</th><th class="right">Expected</th><th class="right">Counted</th><th class="right">Variance</th><th>Remarks</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?=$r['line_no']?></td><td><?=htmlspecialchars($r['item_code'].' — '.$r['item_name'])?></td><td class="right"><?=number_format((float)$r['expected_qty'],3)?></td>
<td class="right"><input type="number" name="counted_qty[<?=$r['id']?>]" step="0.001" value="<?=number_format((float)$r['counted_qty'],3,'.','')?>"></td>
<td class="right"><?=number_format((float)($r['counted_qty'] - $r['expected_qty']),3)?></td>
<td><input type="text" name="remarks[<?=$r['id']?>]" value="<?=htmlspecialchars($r['remarks'] ?? '')?>"></td></tr><?php endforeach; if (!count($rows)): ?>
<tr><td colspan="6">No lines.</td></tr><?php endif; ?></tbody></table>
<div style="margin-top:12px;"><button class="btn primary" type="submit">Post Variance</button> <a class="btn" href="cycle_count_generate.php">Back</a></div></form></div>
</body></html>
