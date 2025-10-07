
<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('grir.close.view');
?><!doctype html><html><head><meta charset="utf-8"><title>GR/IR Reconciliation</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}label{display:block;margin:6px 0}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}</style></head><body>
<h1>GR/IR Reconciliation & Close</h1>
<label>Older than (GRN date) <input id="od" type="date"></label>
<button id="sug">Suggest</button>
<table id="t"><thead></thead><tbody></tbody></table>
<hr>
<h3>Create & Post</h3>
<button id="create">Create Close</button> <b id="cid"></b>
<label>GRN Line <input id="gl"></label>
<label>Open Value <input id="ov" type="number" step="0.01"></label>
<label>Close Value (+/-) <input id="cv" type="number" step="0.01"></label>
<label>Reason <select id="rs"><option>writeoff</option><option>price_adjust</option><option>qty_adjust</option><option>other</option></select></label>
<button id="add">Add</button>
<button id="post">Post</button> <span id="msg"></span>
<script>
async function load(url, data){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});const j=await r.json();if(!j.ok)throw new Error(j.error);return j.data;}
sug.onclick=async()=>{try{const d=await load('grir/_ajax/suggest.php',{older_than:od.value});const tbody=document.querySelector('#t tbody');const thead=document.querySelector('#t thead');tbody.innerHTML='';thead.innerHTML='';if(!d.length){tbody.innerHTML='<tr><td>No suggestions</td></tr>';return;}const cols=Object.keys(d[0]);thead.innerHTML='<tr>'+cols.map(c=>'<th>'+c+'</th>').join('')+'</tr>';tbody.innerHTML=d.map(r=>'<tr>'+cols.map(c=>'<td>'+r[c]+'</td>').join('')+'</tr>').join('');}catch(e){alert(e.message);}}
create.onclick=async()=>{try{const d=await load('grir/_ajax/create.php',{});cid.textContent=d.closure_id;}catch(e){alert(e.message);}}
add.onclick=async()=>{try{const d=await load('grir/_ajax/add_line.php',{closure_id:parseInt(cid.textContent||0),grn_line_id:parseInt(gl.value||0),open_value:parseFloat(ov.value||'0'),close_value:parseFloat(cv.value||'0'),reason:rs.value});msg.textContent='Added line '+d.closure_line_id;}catch(e){alert(e.message);}}
post.onclick=async()=>{try{const d=await load('grir/_ajax/post.php',{closure_id:parseInt(cid.textContent||0)});msg.textContent='Posted lines='+d.lines+' amount='+d.amount;}catch(e){alert(e.message);}}
</script></body></html>
