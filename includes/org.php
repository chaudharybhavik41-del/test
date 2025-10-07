<?php
/** PATH: /public_html/includes/org.php */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function org_profile(): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  $pdo = db();
  $row = $pdo->query("SELECT * FROM org_profile WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
  // sane defaults if row missing
  $row += [
    'legal_name' => 'Your Company Pvt Ltd',
    'address_line1' => '', 'address_line2' => '', 'city' => '',
    'state' => '', 'state_code' => '', 'pincode' => '',
    'gstin' => '', 'phone' => '', 'email' => '',
  ];
  return $cache = $row;
}

/**
 * Decide GST split based on place-of-supply vs org state.
 * @return array{mode:string, cgst:float, sgst:float, igst:float}
 */
function gst_split(float $totalTaxAmount, string $placeState): array {
  $org = org_profile();
  $isIntra = (strcasecmp(trim($placeState), trim((string)$org['state'])) === 0);
  if ($isIntra) {
    $half = round($totalTaxAmount / 2, 2);
    return ['mode' => 'intra', 'cgst' => $half, 'sgst' => $half, 'igst' => 0.00];
    // If you need per-line exactness, compute at line level instead.
  } else {
    return ['mode' => 'inter', 'cgst' => 0.00, 'sgst' => 0.00, 'igst' => round($totalTaxAmount, 2)];
  }
}
