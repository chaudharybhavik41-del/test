<?php
declare(strict_types=1);

// Optional: protect with login if your auth is already wired
// require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/services/Accounts/PostingService.php';
use Accounts\PostingService;

header('Content-Type: text/plain');

try {
  $today = date('Y-m-d');

  // Simple JV: DR Refreshments 100, CR Cash 100
  $jid = PostingService::createSimpleJV(
    $today,
    '6010',          // Kitchen & Office Refreshments (DR)
    100.00,
    '1010',          // Cash-in-hand (CR)
    ['narration' => 'DIAG: simple JV test', 'posted_by' => 1]
  );

  echo "OK ✅ Journal created. ID={$jid}\n";
  echo "Check tables: journals + journal_lines.\n";
} catch (Throwable $e) {
  echo "ERR ❌ " . $e->getMessage();
}
