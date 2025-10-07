<?php
/** PATH: /public_html/parties/party_gst_lookup.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
try {
  require_login();

  $gstin = strtoupper(trim((string)($_GET['gstin'] ?? '')));
  if ($gstin === '') {
    echo json_encode(['ok'=>false,'message'=>'GSTIN is required']); exit;
  }

  // Format: 2 digit state + 10 PAN-like + 1 entity + 'Z' + 1 checksum
  $isValid = (bool)preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin);
  $pan = $isValid ? substr($gstin, 2, 10) : null;
  $stateCode = $isValid ? substr($gstin, 0, 2) : null;

  // NOTE: We do not call the NIC/GSTN API here. legal_name is unknown without an external API.
  echo json_encode([
    'ok'         => $isValid,
    'message'    => $isValid ? 'GSTIN format looks valid' : 'Invalid GSTIN format',
    'gstin'      => $gstin,
    'pan'        => $pan,
    'state_code' => $stateCode,
    'legal_name' => null
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}