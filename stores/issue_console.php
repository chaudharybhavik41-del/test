<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('stores.issue.view');
?><!doctype html><html><head><meta charset="utf-8"><title>Stores Issue</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}label{display:block;margin:6px 0}</style></head><body>
<h1>Stores Issue</h1>
<h3>Create Header</h3>
<label>Date <input id="d" type="date"></label>
<label>Cost Center <input id="cc"></label>
<label>Job <input id="job"></label>
<button id="c">Create</button> <b id="hid"></b>
<h3>Add Line</h3>
<label>Item <input id="item" type="number"></label>
<label>Warehouse <input id="wh" type="number"></label>
<label>Qty <input id="qty" type="number" step="0.000001"></label>
<label>Lot (opt) <input id="lot" type="number"></label>
<label>Piece (opt) <input id="piece" type="number"></label>
<button id="add">Add</button> <span id="msg"></span>
<h3>Post</h3>
<button id="post">Post Issue</button> <span id="pmsg"></span>
<script>
async function api(u,d){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const j=await r.json();if(!j.ok)throw new Error(j.error);return j.data;}
document.getElementById('c').onclick=async()=>{try{const d=await api('stores/_ajax/issue_create.php',{issue_date:document.getElementById('d').value,cost_center_id:document.getElementById('cc').value||null,job_id:document.getElementById('job').value||null});document.getElementById('hid').textContent='Issue #'+d.issue_id;window.issue_id=d.issue_id;}catch(e){alert(e.message);}}
document.getElementById('add').onclick=async()=>{try{const d=await api('stores/_ajax/issue_add_line.php',{issue_id:window.issue_id,item_id:parseInt(document.getElementById('item').value||0),warehouse_id:parseInt(document.getElementById('wh').value||0),qty_base:parseFloat(document.getElementById('qty').value||'0'),lot_id:document.getElementById('lot').value||null,piece_id:document.getElementById('piece').value||null});document.getElementById('msg').textContent='Line #'+d.issue_line_id+' added';}catch(e){alert(e.message);}}
document.getElementById('post').onclick=async()=>{try{const d=await api('stores/_ajax/issue_post.php',{issue_id:window.issue_id});document.getElementById('pmsg').textContent='Posted lines='+d.lines+' qty_total='+d.qty_total;}catch(e){alert(e.message);}}
</script></body></html>
