<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('bom.view');
?><!doctype html><html><head><meta charset="utf-8"><title>BOM Tree (Multi-level)</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}pre{background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px}</style></head><body>
<h1>BOM Tree (Multi-level)</h1>
<label>Parent Item <input id="pid" type="number"></label>
<label>Qty <input id="qty" type="number" step="0.000001" value="1"></label>
<label>As Of <input id="asof" type="date"></label>
<button id="go">Explode</button>
<h3>Flat Requirements</h3><pre id="flat"></pre>
<h3>Tree</h3><pre id="tree"></pre>
<script>
async function call(data){const r=await fetch('bom/_ajax/explode.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});const j=await r.json();if(!j.ok)throw new Error(j.error);return j.data;}
go.onclick=async()=>{try{const d=await call({parent_item_id:parseInt(pid.value||0),qty:parseFloat(qty.value||'1'),as_of:asof.value||null});flat.textContent=JSON.stringify(d.flat,null,2);tree.textContent=JSON.stringify(d.tree,null,2);}catch(e){alert(e.message);}}
</script></body></html>
