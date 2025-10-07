<?php
declare(strict_types=1);

// Optional: require login
// require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/services/Accounts/PostingService.php';
require_once __DIR__ . '/../includes/services/Accounts/TaxStagingService.php';

use Accounts\PostingService;

header('Content-Type: text/plain');

try {
  $bill = [
    'id'               => 9991,
    'bill_no'          => 'TEST-AP-001',
    'bill_date'        => date('Y-m-d'),
    'vendor_party_id'  => 1,                   // use an existing party id if you have it; else leave null
    'vendor_gstin'     => '24ABCDE1234F1Z5',
    'pos_state'        => '24',
    'project_id'       => 1,
    'posted_by'        => 1
  ];

  $lines = [
    // Material line (intra-state 18% GST)
    [
      'id'             => 1,
      'taxable_value'  => 1000.00,
      'cgst'           => 90.00,
      'sgst'           => 90.00,
      'igst'           => 0.00,
      'gst_rate'       => 18.000,
      'hsn'            => '7208',
      'qty'            => 10.000,
      'uom_id'         => 2,      // adjust if needed
      'is_service'     => 0
    ],
    // Service line (inter-state 18% GST)
    [
      'id'             => 2,
      'taxable_value'  => 500.00,
      'cgst'           => 0.00,
      'sgst'           => 0.00,
      'igst'           => 90.00,
      'gst_rate'       => 18.000,
      'hsn'            => '9987',
      'qty'            => 1.000,
      'uom_id'         => 1,
      'is_service'     => 1
    ],
  ];

  $jid = PostingService::postAPBill($bill, $lines);

  echo "OK ✅ AP Bill posted. Journal ID={$jid}\n";
  echo "- DR 1310 Inventory: 1000.00\n";
  echo "- DR 5010 Expense : 500.00\n";
  echo "- DR 2210 CGST    : 90.00\n";
  echo "- DR 2220 SGST    : 90.00\n";
  echo "- DR 2230 IGST    : 90.00\n";
  echo "- CR 2010 Vendor  : 1770.00\n";
  echo "Also staged 2 lines in tax_transactions for GST.\n";
} catch (Throwable $e) {
  echo "ERR ❌ " . $e->getMessage();
}
