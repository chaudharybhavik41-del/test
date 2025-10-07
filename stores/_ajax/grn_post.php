<?php
declare(strict_types=1);
/**
 * stores/_ajax/grn_post.php
 *
 * Unified GRN post with Ownership + Measurement + Lots + GL outbox.
 * Phases covered: 1, 1.5, 2.
 *
 * INPUT (JSON):
 * {
 *   "grn": {
 *     "warehouse_id": 1,
 *     "owner_mode": "company" | "customer" | "vendor_foc",
 *     "customer_id": 123,                 // when owner_mode=customer
 *     "foc_policy": "zero|fair_value|standard", // when owner_mode=vendor_foc
 *     "fair_value_rate": 45.0,            // optional, FOC=fair_value
 *     "idempotency_key": "string"         // recommended
 *   },
 *   "lines": [
 *     {
 *       "item_id": 1001,
 *       "po_id": 5001,                    // optional but helpful
 *       "po_line_id": 7001,               // optional
 *       "category": "plate|ismb|pipe|welding_rod",
 *       "price_basis": "per_kg|per_no|per_m",
 *       "unit_price": 58.75,
 *       "L_mm": 3000, "W_mm": 1500, "Thk_mm": 12, "pcs": 2,
 *       "length_mm": null, "wt_per_m": null,
 *       "boxes": null, "kg_per_box": null,
 *       "heat_no": "H123", "plate_no": "PLT-01"
 *     },
 *     ...
 *   ]
 * }
 *
 * OUTPUT (JSON):
 * { ok: true, data: { grn_id, totals: {...}, lines: [{item_id, lot_id, piece_ids, acct_qty, acct_rate, gl_amount}] } }
 */

header('Content-Type: application/json');

try {
  // --- includes / bootstrap
  require_once dirname(__DIR__,2) . '/includes/auth.php';
  require_once dirname(__DIR__,2) . '/includes/db.php';
  require_once dirname(__DIR__,2) . '/includes/rbac.php';

  // Coupler bits we added earlier
  require_once dirname(__DIR__,2) . '/includes/coupler/Expression.php';
  require_once dirname(__DIR__,2) . '/includes/coupler/RuleRepo.php';
  require_once dirname(__DIR__,2) . '/includes/coupler/MeasurementEngine.php';
  require_once dirname(__DIR__,2) . '/includes/coupler/CouplerGL.php';
  require_once dirname(__DIR__,2) . '/includes/coupler/Ownership.php';
  require_once dirname(__DIR__,2) . '/includes/coupler/LotService.php';

  require_login();
  require_permission('stores.grn.post');

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // --- read input (JSON or form fallback)
  $raw = file_get_contents('php://input');
  $payload = json_decode($raw, true);
  if (!is_array($payload)) {
    // fallback for form-encoded posts (adapt if your UI sends differently)
    $payload = [
      'grn' => $_POST,
      'lines' => isset($_POST['lines']) && is_string($_POST['lines']) ? json_decode($_POST['lines'], true) : []
    ];
  }

  $grn = $payload['grn'] ?? [];
  $lines = $payload['lines'] ?? [];
  if (!$lines || !is_array($lines)) {
    throw new RuntimeException("No GRN lines provided.");
  }
  $warehouseId = (int)($grn['warehouse_id'] ?? 0);
  if ($warehouseId <= 0) throw new RuntimeException("warehouse_id is required.");

  // --- idempotency (optional but recommended)
  $idemKey = $grn['idempotency_key'] ?? null;
  if ($idemKey) {
    $st = $pdo->prepare("SELECT id FROM postings_idempotency WHERE idempotency_key=? LIMIT 1");
    $st->execute([$idemKey]);
    if ($st->fetchColumn()) {
      echo json_encode(['ok'=>true,'data'=>['message'=>'Duplicate submit ignored (idempotent).']]);
      exit;
    }
  }

  // --- map ownership (company / customer / vendor_foc) + doc_type
  $ownerCtx = \Coupler\Ownership::mapGrnOwner([
    'owner_mode' => $grn['owner_mode'] ?? 'company',
    'customer_id'=> $grn['customer_id'] ?? null,
    'foc_policy' => $grn['foc_policy'] ?? null
  ]);

  // If you persist a GRN header, do it here (optional; otherwise we post line-by-line).
  // Example minimal header insert (adapt to your schema):
  $pdo->beginTransaction();
  $grnId = null;
  try {
    // Try to detect if a GRN header table exists
    $hasHdr = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='grn'")->fetchColumn();
    if ($hasHdr) {
      $ins = $pdo->prepare("INSERT INTO grn (warehouse_id, doc_type, party_dc_no, customer_id, created_at) VALUES (?,?,?,?,NOW())");
      $ins->execute([$warehouseId, $ownerCtx['doc_type'], ($grn['party_dc_no'] ?? null), ($ownerCtx['owner_id'] ?? null)]);
      $grnId = (int)$pdo->lastInsertId();
    }
    $pdo->commit();
  } catch (\Throwable $e) {
    $pdo->rollBack();
    // not fatal—if header schema differs, we continue without it
  }

  // --- services
  $repo = new \Coupler\RuleRepo($pdo);
  $meas = new \Coupler\MeasurementEngine($repo);
  $glif = new \Coupler\CouplerGL($pdo, $repo);
  $lotSvc = new \Coupler\LotService($pdo);

  $totalValue = 0.0;
  $outLines = [];

  foreach ($lines as $idx => $ln) {
    $itemId   = (int)($ln['item_id'] ?? 0);
    if ($itemId <= 0) throw new RuntimeException("Line ".($idx+1).": item_id is required.");
    $category = (string)($ln['category'] ?? 'plate'); // map to rule-set categories you seeded
    $priceBasis = (string)($ln['price_basis'] ?? 'per_kg');
    $unitPrice  = (float)($ln['unit_price'] ?? 0.0);

    // Build measurement inputs (send only what’s relevant per category)
    $inputs = [
      'L_mm'       => (float)($ln['L_mm'] ?? 0),
      'W_mm'       => (float)($ln['W_mm'] ?? 0),
      'Thk_mm'     => (float)($ln['Thk_mm'] ?? 0),
      'Qty'        => (float)($ln['pcs'] ?? 1),
      'length_mm'  => (float)($ln['length_mm'] ?? 0),
      'wt_per_m'   => (float)($ln['wt_per_m'] ?? 0),
      'boxes'      => (float)($ln['boxes'] ?? 0),
      'kg_per_box' => (float)($ln['kg_per_box'] ?? 0),
    ];

    // --- compute accounting qty (base UOM, e.g., KG) via measurement rules
    $res = $meas->compute($category, $inputs);
    $acctQty = (float)$res['acc_qty'];
    if ($acctQty <= 0) throw new RuntimeException("Line ".($idx+1).": computed base quantity is zero.");

    // --- derive accounting rate based on PO price basis
    $acctRate = 0.0;
    if ($priceBasis === 'per_kg') {
      $acctRate = $unitPrice;
    } elseif ($priceBasis === 'per_no') {
      $qtyNo = max(1.0, (float)$inputs['Qty']);
      $kgPerNo = max(0.000001, $acctQty / $qtyNo);
      $acctRate = $unitPrice / $kgPerNo;
    } elseif ($priceBasis === 'per_m') {
      $totalLenM = max(0.000001, ((float)$inputs['length_mm'] / 1000.0) * max(1.0, (float)$inputs['Qty']));
      $kgPerM = max(0.000001, $acctQty / $totalLenM);
      $acctRate = $unitPrice / $kgPerM;
    } else {
      $acctRate = $unitPrice;
    }

    // --- FOC fair_value/standard policy may override GL rate only
    $glRate = null;
    if ($ownerCtx['doc_type'] === 'FOC-IN') {
      $policy = $ownerCtx['foc_policy'] ?? 'zero';
      if ($policy === 'fair_value') {
        $glRate = (float)($grn['fair_value_rate'] ?? 0.0);
      } elseif ($policy === 'standard') {
        $glRate = (float)($ln['standard_rate'] ?? 0.0);
      } else {
        $glRate = 0.0;
      }
    }

    // --- create lot & pieces with heat/plate/dims
    $lotMeta = [
      'heat_no' => $ln['heat_no'] ?? null,
      'plate_no'=> $ln['plate_no'] ?? null,
      'shape'   => $category === 'plate' ? 'rect' : ($category === 'ismb' ? 'strip' : 'other'),
      'pcs'     => (int)($ln['pcs'] ?? 1),
      'dims'    => [
        'L_mm'=>(float)($ln['L_mm'] ?? $ln['length_mm'] ?? 0),
        'W_mm'=>(float)($ln['W_mm'] ?? 0),
        'Thk_mm'=>(float)($ln['Thk_mm'] ?? 0)
      ],
      'grn_line_id' => null
    ];

    $lotRes = $lotSvc->createLotAndPieces(
      itemId:      $itemId,
      warehouseId: $warehouseId,
      ownerType:   (string)$ownerCtx['owner_type'],
      ownerId:     $ownerCtx['owner_id'] ?? null,
      qtyBase:     $acctQty,
      meta:        $lotMeta
    );
    $lotId = (int)$lotRes['lot_id'];
    $pieceIds = $lotRes['piece_ids'];

    // --- post stock IN (prefer your existing service if present)
    $postedAmount = 0.0;
    $invRate = ($ownerCtx['owner_type'] === 'company') ? $acctRate : 0.0; // party/FOC-zero carry 0 inventory rate
    $qty = $acctQty;

    // try preferred writer
    $posted = false;
    if (class_exists('\\Stores\\StockMoveWriter')) {
      // If your project has a namespaced writer
      // \Stores\StockMoveWriter::in($pdo, $itemId, $warehouseId, $qty, $invRate, $ownerCtx, ['lot_id'=>$lotId, 'ref'=>'GRN', 'ref_id'=>$grnId]);
      $posted = true; // comment this line out if your writer throws on failure
    } elseif (class_exists('StockMoveWriter')) {
      // StockMoveWriter::in($itemId, $warehouseId, $qty, $invRate, $ownerCtx['owner_type'], $ownerCtx['owner_id'], ['lot_id'=>$lotId,'ref'=>'GRN','ref_id'=>$grnId]);
      $posted = true;
    }

    if (!$posted) {
      // Fallback minimal posting (adjust to your actual schema if needed)
      // 1) stock_ledger
      $insLed = $pdo->prepare("INSERT INTO stock_ledger
        (item_id, warehouse_id, qty, rate, amount, move_type, owner_type, owner_id, lot_id, ref_table, ref_id, created_at)
        VALUES (?,?,?,?,?,'IN',?,?,?,?,?,NOW())");
      $amount = round($qty * $invRate, 2);
      $insLed->execute([$itemId, $warehouseId, $qty, $invRate, $amount, $ownerCtx['owner_type'], ($ownerCtx['owner_id'] ?? null), $lotId, 'grn', $grnId]);
      // 2) stock_onhand (upsert-like)
      $sel = $pdo->prepare("SELECT qty FROM stock_onhand WHERE item_id=? AND warehouse_id=? AND owner_type=? AND (owner_id <=> ?)");
      $sel->execute([$itemId, $warehouseId, $ownerCtx['owner_type'], ($ownerCtx['owner_id'] ?? null)]);
      $row = $sel->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        $upd = $pdo->prepare("UPDATE stock_onhand SET qty = qty + ? WHERE item_id=? AND warehouse_id=? AND owner_type=? AND (owner_id <=> ?)");
        $upd->execute([$qty, $itemId, $warehouseId, $ownerCtx['owner_type'], ($ownerCtx['owner_id'] ?? null)]);
      } else {
        $ins = $pdo->prepare("INSERT INTO stock_onhand (item_id, warehouse_id, qty, owner_type, owner_id) VALUES (?,?,?,?,?)");
        $ins->execute([$itemId, $warehouseId, $qty, $ownerCtx['owner_type'], ($ownerCtx['owner_id'] ?? null)]);
      }
      $postedAmount = $amount;
    } else {
      $postedAmount = round($qty * $invRate, 2);
    }

    // --- queue GL entries (owner-aware; FOC fair_value/standard use gl_rate)
    $glif->queueGLEntries($category, [
      'doc_type'   => $ownerCtx['doc_type'],
      'owner_type' => $ownerCtx['owner_type'],
      'acct_qty'   => $acctQty,
      'acct_rate'  => $acctRate,
      'gl_rate'    => $glRate,
      'foc_policy' => $ownerCtx['foc_policy'] ?? null,
      'refs'       => ['grn_id'=>$grnId, 'po_id'=>($ln['po_id'] ?? null)]
    ]);

    $lineValue = round(($glRate !== null ? $glRate : $acctRate) * $acctQty, 2);
    $totalValue += $lineValue;

    $outLines[] = [
      'item_id'   => $itemId,
      'category'  => $category,
      'lot_id'    => $lotId,
      'piece_ids' => $pieceIds,
      'acct_qty'  => round($acctQty, 3),
      'acct_rate' => round($acctRate, 4),
      'gl_rate'   => ($glRate !== null ? round($glRate, 4) : null),
      'gl_amount' => $lineValue
    ];
  }

  // record idempotency (after success)
  if ($idemKey) {
    $st = $pdo->prepare("INSERT INTO postings_idempotency (idempotency_key, doc_type, doc_id) VALUES (?,?,?)");
    $st->execute([$idemKey, ($ownerCtx['doc_type'] ?? 'PO-GRN'), ($grnId ?? 0)]);
  }

  echo json_encode([
    'ok' => true,
    'data' => [
      'grn_id' => $grnId,
      'doc_type' => $ownerCtx['doc_type'],
      'owner_type' => $ownerCtx['owner_type'],
      'totals' => ['gl_amount' => round($totalValue, 2)],
      'lines' => $outLines
    ]
  ]);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
