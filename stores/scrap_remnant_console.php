
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('stores.remnant.view');
?><!doctype html><html><head><meta charset="utf-8"><title>Scrap & Remnant Desk</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}label{display:block;margin:6px 0}</style></head><body>
<h1>Scrap & Remnant Desk</h1>
<h3>Mark Remnant</h3>
<label>Piece ID <input id="p"></label>
<label>Qty <input id="q" type="number" step="0.000001"></label>
<label>Reason <input id="r"></label>
<button id="mr">Mark</button> <span id="m"></span>
<h3>Convert to Scrap</h3>
<label>Piece ID <input id="p2"></label>
<label>Qty <input id="q2" type="number" step="0.000001"></label>
<label>Scrap Item <input id="si"></label>
<label>Warehouse <input id="wh"></label>
<label>Reason <input id="r2"></label>
<button id="cs">Convert</button> <span id="s"></span>
<script>
async function api(u,d){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const j=await r.json();if(!j.ok)throw new Error(j.error);return j.data;}
mr.onclick=async()=>{try{const d=await api('stores/_ajax/remnant_mark.php',{piece_id:parseInt(p.value||0),qty_base:parseFloat(q.value||'0'),reason:r.value});m.textContent='Action '+d.remnant_action_id;}catch(e){alert(e.message);}}
cs.onclick=async()=>{try{const d=await api('stores/_ajax/scrap_convert.php',{piece_id:parseInt(p2.value||0),qty_base:parseFloat(q2.value||'0'),scrap_item_id:parseInt(si.value||0),warehouse_id:parseInt(wh.value||0),reason:r2.value});s.textContent='Scrap qty '+d.qty;}catch(e){alert(e.message);}}
</script></body></html>
