<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_login(); require_permission('purchase.lc.view');
?><!doctype html><html><head><meta charset="utf-8"><title>Landed Cost Console</title>
<link rel="stylesheet" href="../assets/ems_phase04_ui.css"></head><body>
<h1>Landed Cost Console</h1>
<section><h2>Create LC Header</h2>
<div class="row"><div><label>Method</label><select id="lc_method"><option>by_weight</option><option>by_value</option><option>by_qty</option><option>by_volume</option></select></div>
<div><label>Supplier ID</label><input id="lc_supplier" type="number"></div>
<div><label>Currency</label><input id="lc_curr" value="INR"></div>
<div><label>FX Rate</label><input id="lc_fx" type="number" step="0.000001" value="1.0"></div></div>
<div><label>Notes</label><input id="lc_notes"></div>
<button id="btnCreate">Create LC</button> <b id="lcId"></b></section>
<section><h2>Add GRN Line</h2>
<div class="row"><div><label>LC ID</label><input id="grn_lc_id" type="number"></div><div><label>Item ID</label><input id="grn_item_id" type="number"></div>
<div><label>Warehouse ID</label><input id="grn_wh_id" type="number"></div><div><label>Qty (base)</label><input id="grn_qty" type="number" step="0.000001"></div></div>
<div class="row"><div><label>Value (qty*rate)</label><input id="grn_val" type="number" step="0.000001"></div><div><label>Weight (kg)</label><input id="grn_wkg" type="number" step="0.000001"></div>
<div><label>GRN ID</label><input id="grn_id" type="number"></div><div><label>GRN Line ID</label><input id="grn_line_id" type="number"></div></div>
<button id="btnAddGRN">Add GRN Line</button> <span id="grnMsg"></span></section>
<section><h2>Add Charge</h2>
<div class="row"><div><label>LC ID</label><input id="ch_lc_id" type="number"></div><div><label>Charge Code</label><input id="ch_code" value="freight"></div>
<div><label>Amount</label><input id="ch_amt" type="number" step="0.01"></div><div><label>Vendor ID</label><input id="ch_vendor" type="number"></div></div>
<div class="row"><div><label>Currency</label><input id="ch_curr" value="INR"></div><div><label>FX</label><input id="ch_fx" type="number" step="0.000001" value="1.0"></div><div><label>Description</label><input id="ch_desc"></div></div>
<button id="btnAddCh">Add Charge</button> <span id="chMsg"></span></section>
<section><h2>Allocate / Post</h2>
<div class="row-3"><div><label>LC ID</label><input id="act_lc_id" type="number"></div><div><button id="btnAlloc">Allocate</button></div><div><button id="btnPost">Post</button></div></div>
<div id="allocMsg"></div></section>
<script>
async function api(u,d){const r=await fetch(u,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const j=await r.json();if(!j.ok) throw new Error(j.error||'API');return j.data;}
document.getElementById('btnCreate').onclick=async()=>{try{const d=await api('../purchase/_ajax/lc_create.php',{method:document.getElementById('lc_method').value,supplier_id:parseInt(document.getElementById('lc_supplier').value||0)||null,currency:document.getElementById('lc_curr').value,fx_rate:parseFloat(document.getElementById('lc_fx').value||'1'),notes:document.getElementById('lc_notes').value});document.getElementById('lcId').textContent='LC #'+d.lc_id;['grn_lc_id','ch_lc_id','act_lc_id'].forEach(id=>document.getElementById(id).value=d.lc_id);}catch(e){alert(e.message);}};
document.getElementById('btnAddGRN').onclick=async()=>{try{const d=await api('../purchase/_ajax/lc_add_grn_line.php',{lc_id:parseInt(document.getElementById('grn_lc_id').value||0),grn_line:{item_id:parseInt(document.getElementById('grn_item_id').value||0),warehouse_id:parseInt(document.getElementById('grn_wh_id').value||0),qty_base:parseFloat(document.getElementById('grn_qty').value||'0'),value_base:parseFloat(document.getElementById('grn_val').value||'0'),weight_kg:document.getElementById('grn_wkg').value?parseFloat(document.getElementById('grn_wkg').value):null,grn_id:document.getElementById('grn_id').value||null,grn_line_id:document.getElementById('grn_line_id').value||null}});document.getElementById('grnMsg').textContent='Added line #'+d.lc_grn_line_id;}catch(e){alert(e.message);}};
document.getElementById('btnAddCh').onclick=async()=>{try{const d=await api('../purchase/_ajax/lc_add_charge.php',{lc_id:parseInt(document.getElementById('ch_lc_id').value||0),charge_code:document.getElementById('ch_code').value,amount:parseFloat(document.getElementById('ch_amt').value||'0'),currency:document.getElementById('ch_curr').value,fx_rate:parseFloat(document.getElementById('ch_fx').value||'1'),vendor_id:document.getElementById('ch_vendor').value?parseInt(document.getElementById('ch_vendor').value):null,description:document.getElementById('ch_desc').value||null});document.getElementById('chMsg').textContent='Charge #'+d.lc_charge_id+' added';}catch(e){alert(e.message);}};
document.getElementById('btnAlloc').onclick=async()=>{try{const d=await api('../purchase/_ajax/lc_allocate_post.php',{lc_id:parseInt(document.getElementById('act_lc_id').value||0),action:'allocate'});document.getElementById('allocMsg').textContent='Allocated total '+d.total+' over '+d.allocated.length+' line(s)';}catch(e){alert(e.message);}};
document.getElementById('btnPost').onclick=async()=>{try{const d=await api('../purchase/_ajax/lc_allocate_post.php',{lc_id:parseInt(document.getElementById('act_lc_id').value||0),action:'post'});document.getElementById('allocMsg').textContent='Posted LC amount '+d.amount;}catch(e){alert(e.message);}};
</script></body></html>
