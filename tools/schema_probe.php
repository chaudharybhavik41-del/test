<?php
// Quick schema probe: prints JSON of columns for the tables we need
$tables = [
  'crm_leads','crm_activities','parties','contacts','users',
  'sales_quotes','sales_quote_items','sales_orders','sales_order_items',
  'sales_quote_revisions','sales_quote_sents','quote_items',
  'numbering_series','quote_sequences'
];
$pdo = new PDO('mysql:host=localhost;dbname=u989675055_emsinfracoin;charset=utf8mb4','u989675055_emsinfrain','Emsinfra@9898',[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$out=[];
foreach ($tables as $t) {
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll();
    $out[$t] = array_map(fn($c)=>$c['Field'].' '.$c['Type'], $cols);
  } catch (Throwable $e) {
    $out[$t] = 'NOT FOUND';
  }
}
header('Content-Type: application/json'); echo json_encode($out, JSON_PRETTY_PRINT);