
<?php
// Hook example to be called inside GRN post flow BEFORE accounting rate/qty are finalized.
// Usage in grn_post.php:
//   require_once __DIR__ . '/../../includes/coupler/UomRuleEngine.php';
//   $engine = new \Coupler\UomRuleEngine($pdo);
//   $calc = $engine->compute($itemCategory, [
//       'rate'=>$rate, 'pcs'=>$pcs, 'weight_kg'=>$weight_kg,
//       'length_m'=>$length, 'width_m'=>$width, 'thickness_m'=>$thickness,
//       'area_m2'=>$area, 'volume_m3'=>$volume
//   ]);
//   $acct_qty = $calc['qty']; $acct_rate = $calc['rate'];
