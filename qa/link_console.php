<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/rbac.php';
require_login(); require_permission('qa.view');
?><!doctype html><html><head><meta charset="utf-8"><title>QA Document Links (No Upload)</title>
<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;margin:20px}label{display:block;margin:6px 0}table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e7eb;padding:6px}</style></head><body>
<h1>QA Document Links</h1>
<h3>Create Link</h3>
<label>Attachment ID <input id="att" type="number"></label>
<label>Doc Type <select id="dt"><option>heat_cert</option><option>inspection_report</option><option>other</option></select></label>
<label>Lot ID (opt) <input id="lot" type="number"></label>
<label>GRN Line ID (opt) <input id="grn" type="number"></label>
<label>Item ID (opt) <input id="item" type="number"></label>
<label>Notes <input id="notes"></label>
<button id="link">Link</button> <span id="msg"></span>
<hr>
<h3>List Links</h3>
<label>Filter: Attachment ID <input id="f_att" type="number"></label>
<label>Filter: Lot ID <input id="f_lot" type="number"></label>
<label>Filter: GRN Line ID <input id="f_grn" type="number"></label>
<button id="load">Load</button>
<table id="t"><thead></thead><tbody></tbody></table>
<script>
async function api(u,method,body){const opt={method}; if(body){opt.headers={'Content-Type':'application/json'}; opt.body=JSON.stringify(body);} const r=await fetch(u,opt); const j=await r.json(); if(!j.ok) throw new Error(j.error); return j.data;}
link.onclick=async()=>{try{const d=await api('qa/_ajax/link.php','POST',{attachment_id:parseInt(att.value||0),doc_type:dt.value,lot_id:lot.value?parseInt(lot.value):null,grn_line_id:grn.value?parseInt(grn.value):null,item_id:item.value?parseInt(item.value):null,notes:notes.value||null}); msg.textContent='Linked #'+d.qa_link_id;}catch(e){alert(e.message);}}
load.onclick=async()=>{try{const url = 'qa/_ajax/list.php?attachment_id='+(f_att.value||'')+'&lot_id='+(f_lot.value||'')+'&grn_line_id='+(f_grn.value||''); const d=await api(url,'GET'); const tbody=document.querySelector('#t tbody'); const thead=document.querySelector('#t thead'); thead.innerHTML=''; tbody.innerHTML=''; if(!d.length){tbody.innerHTML='<tr><td>No data</td></tr>'; return;} const cols=Object.keys(d[0]); thead.innerHTML='<tr>'+cols.map(c=>'<th>'+c+'</th>').join('')+'</tr>'; tbody.innerHTML=d.map(r=>'<tr>'+cols.map(c=>'<td>'+ (r[c]===null?'':r[c]) +'</td>').join('')+'</tr>').join('');}catch(e){alert(e.message);}}
</script></body></html>
