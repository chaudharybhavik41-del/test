EMS Infra ERP — Store Module Patch
Generated: 2025-10-01 17:23:55

Contents
--------
- req_post_issue.php            (full replacement)
- stock_adjust_post.php         (full replacement)
- gp_create_post.php            (full replacement)
- gp_return_post.php            (full replacement)
- includes/StockLedgerAdapter.php (new include)
- migrations/001_add_stock_ledger.sql (new table)

Instructions
------------
1) Run the migration:
   - Execute migrations/001_add_stock_ledger.sql on your MySQL DB.

2) Copy the new include:
   - Place includes/StockLedgerAdapter.php into your project's includes/ folder.

3) Replace endpoints:
   - Backup your existing files.
   - Replace the following with the provided versions:
       req_post_issue.php
       stock_adjust_post.php
       gp_create_post.php
       gp_return_post.php

4) Clear opcode cache if enabled (php-fpm/apcu/opcache).

5) Test:
   - Post a small Issue (OUT): verify stock_ledger receives rows and stock_avg basis reduces.
   - Create a non-returnable GP: verify OUT mirror in stock_ledger.
   - Return on a returnable GP: verify IN mirror and valuation on receipt.
   - Adjustment IN/OUT: verify both valuation and ledger entries.

Notes
-----
- No UI changes. Payload shapes are preserved.
- Rates are pre-tax (basic). Taxes remain in AP.
- The adapter reads current WA for OUT from stock_avg; ensure your ValuationService keeps stock_avg updated.