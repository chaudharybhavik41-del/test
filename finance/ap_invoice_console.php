<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login(); require_permission('finance.ap.view');
?><!doctype html><html><head><meta charset="utf-8"><title>AP Invoice Console (3-Way Match)</title>
<link rel="stylesheet" href="../assets/ems_phase04_ui.css"></head><body>
<h1>AP Invoice Console (3-Way Match)</h1>
<section><h2>Create Invoice</h2>
<div class="row"><div><label>Vendor ID</label><input id="v_id" type="number"></div><div><label>Invoice No</label><input id="inv_no"></div>
<div><label>Date</label><input id="inv_date" type="date"></div><div><label>Category</label><select id="inv_cat"><option>goods</option><option>service</option><option>landed_cost</option></select></div></div>
<div class="row"><div><label>Currency</label><input id="inv_curr" value="INR"></div><div><label>FX Rate</label><input id="inv_fx" type="number" step="0.000001" value="1.0"></div><div><button id="btnCreateInv">Create</button></div></div>
<b id="invoiceId"></b></section>
<section><h2>Add Invoice Line</h2>
<div class="row"><div><label>Invoice ID</label><input id="il_inv_id" type="number"></div><div><label>PO Line ID</label><input id="il_po_line" type="number"></div>
<div><label>GRN Line ID</label><input id="il_grn_line" type="number"></div><div><label>Item ID</label><input id="il_item" type="number"></div></div>
<div class="row"><div><label>Qty</label><input id="il_qty" type="number" step="0.000001"></div><div><label>Unit Price</label><input id="il_rate" type="number" step="0.000001"></div><div><button id="btnAddLine">Add Line</button></div></div>
<div id="ilMsg"></div></section>
<section><h2>Match & Post</h2>
<div class="row-3"><div><label>Invoice ID</label><input id="act_inv_id" type="number"></div><div><button id="btnMatch">Run 3-Way Match</button></div><div><button id="btnPost">Post</button></div></div>
<div id="matchMsg"></div></section>
<script>
async function api(u,d){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const j=await r.json();if(!j.ok) throw new Error(j.error||'API');return j.data;}
document.getElementById('btnCreateInv').onclick=async()=>{try{const d=await api('../finance/_ajax/ap_invoice_create.php',{vendor_id:parseInt(document.getElementById('v_id').value||0),invoice_no:document.getElementById('inv_no').value,invoice_date:document.getElementById('inv_date').value,category:document.getElementById('inv_cat').value,currency:document.getElementById('inv_curr').value,fx_rate:parseFloat(document.getElementById('inv_fx').value||'1')});document.getElementById('invoiceId').textContent='Invoice #'+d.invoice_id;document.getElementById('il_inv_id').value=d.invoice_id;document.getElementById('act_inv_id').value=d.invoice_id;}catch(e){alert(e.message);}};
document.getElementById('btnAddLine').onclick=async()=>{try{const d=await api('../finance/_ajax/ap_invoice_add_line.php',{invoice_id:parseInt(document.getElementById('il_inv_id').value||0),po_line_id:document.getElementById('il_po_line').value?parseInt(document.getElementById('il_po_line').value):null,grn_line_id:document.getElementById('il_grn_line').value?parseInt(document.getElementById('il_grn_line').value):null,item_id:document.getElementById('il_item').value?parseInt(document.getElementById('il_item').value):null,qty:parseFloat(document.getElementById('il_qty').value||'0'),unit_price:parseFloat(document.getElementById('il_rate').value||'0')});document.getElementById('ilMsg').textContent='Line #'+d.invoice_line_id+' amount='+d.amount;}catch(e){alert(e.message);}};
document.getElementById('btnMatch').onclick=async()=>{try{const d=await api('../finance/_ajax/ap_invoice_match.php',{invoice_id:parseInt(document.getElementById('act_inv_id').value||0)});document.getElementById('matchMsg').innerHTML='OK: '+d.ok+' Tol: '+d.tolerance+' Exc: '+d.exception+' Status: <span class="badge '+(d.exception>0?'err':(d.tolerance>0?'warn':'ok'))+'">'+d.status+'</span>'; }catch(e){alert(e.message);}};
document.getElementById('btnPost').onclick=async()=>{try{const d=await api('../finance/_ajax/ap_invoice_post.php',{invoice_id:parseInt(document.getElementById('act_inv_id').value||0)});document.getElementById('matchMsg').textContent='Posted amount='+d.amount+' PPV='+d.ppv+' QTV='+d.qtv;}catch(e){alert(e.message);}};
</script></body></html>
