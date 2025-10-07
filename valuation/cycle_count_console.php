<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('stores.cc.view');
?><!doctype html><html><head><meta charset="utf-8"><title>Cycle Count</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}label{display:block;margin:6px 0}</style></head><body>
<h1>Cycle Count</h1>
<label>Date <input id="d" type="date"></label>
<label>Warehouse <input id="w"></label>
<button id="c">Create</button> <b id="id"></b>
<label>Item <input id="i" type="number"></label>
<label>Counted Qty <input id="q" type="number" step="0.000001"></label>
<button id="a">Add</button>
<button id="p">Post</button> <span id="m"></span>
<script>
async function api(u,d){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const j=await r.json();if(!j.ok)throw new Error(j.error);return j.data;}
c.onclick=async()=>{try{const d=await api('valuation/_ajax/cc_create.php',{cc_date:document.getElementById('d').value,warehouse_id:parseInt(document.getElementById('w').value||0)});id.textContent=d.cc_id;window.cc=d.cc_id;}catch(e){alert(e.message);}}
a.onclick=async()=>{try{const d=await api('valuation/_ajax/cc_add_line.php',{cc_id:window.cc,item_id:parseInt(document.getElementById('i').value||0),counted_qty:parseFloat(document.getElementById('q').value||'0')});m.textContent='Line '+d.line_id;}catch(e){alert(e.message);}}
p.onclick=async()=>{try{const d=await api('valuation/_ajax/cc_post.php',{cc_id:window.cc});m.textContent='Posted variance total '+d.variance_total;}catch(e){alert(e.message);}}
</script></body></html>
