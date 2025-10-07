
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('stores.issue.view');
$pdo=db();
$owner = $_GET['owner'] ?? 'company'; // 'company' or 'client'
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
$q = "SELECT id AS lot_id, item_id, plate_no, heat_no, owner, status, received_at FROM stock_lots WHERE owner=?";
$p = [$owner];
if($owner==='client' && $client_id){ $q .= " AND client_id=?"; $p[]=$client_id; } // column optional; ignored if not present
$q .= " ORDER BY received_at DESC LIMIT 500";
$st=$pdo->prepare($q); $st->execute($p); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><title>Owner-Aware Issue Helper</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}input[type=checkbox]{transform:scale(1.2)}</style></head><body>
<h1>Owner-Aware Issue Helper</h1>
<form id="f">
<label>Owner
  <select name="owner"><option value="company" <?= $owner==='company'?'selected':'' ?>>Company</option><option value="client" <?= $owner==='client'?'selected':'' ?>>Client</option></select>
</label>
<label>Client ID (opt) <input name="client_id" value="<?= htmlspecialchars((string)($client_id??'')) ?>"></label>
<button>Load</button>
</form>
<form id="pick">
<table><thead><tr><th></th><th>Lot</th><th>Item</th><th>Heat</th><th>Plate</th><th>Owner</th><th>Status</th><th>Received</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr>
  <td><input type="checkbox" name="sel" value="<?= $r['lot_id'] ?>"></td>
  <td><?= $r['lot_id'] ?></td><td><?= $r['item_id'] ?></td><td><?= htmlspecialchars((string)$r['heat_no']) ?></td><td><?= htmlspecialchars((string)$r['plate_no']) ?></td><td><?= htmlspecialchars((string)$r['owner']) ?></td><td><?= htmlspecialchars((string)$r['status']) ?></td><td><?= htmlspecialchars((string)$r['received_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<label>Job/WO ID <input id="job"></label>
<label>Qty (JSON per lot) <input id="qty" placeholder='{"123": 2.5, "124": 1.0}'></label>
<button id="export">Export JSON</button>
</form>
<pre id="out"></pre>
<script>
document.getElementById('f').onsubmit=(e)=>{e.preventDefault(); const fd=new FormData(e.target); const qs=new URLSearchParams(fd).toString(); location.href='?'+qs;};
document.getElementById('export').onclick=(e)=>{
  e.preventDefault();
  const sels=[...document.querySelectorAll('input[name=sel]:checked')].map(i=>i.value);
  const qtyRaw=document.getElementById('qty').value||'{}'; let qty={}; try{ qty=JSON.parse(qtyRaw);}catch(_){ alert('Qty JSON invalid'); return; }
  const job=document.getElementById('job').value||null;
  const payload={ job_id: job?parseInt(job):null, picks: sels.map(lot=>({ lot_id: parseInt(lot), qty: qty[lot]||null })) };
  document.getElementById('out').textContent=JSON.stringify(payload,null,2);
};
</script>
</body></html>
