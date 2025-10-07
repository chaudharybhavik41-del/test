<?php
/** PATH: /public_html/sales_quotes/convert_to_order.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_permission('sales.order.create');
csrf_require_token();

$quoteId    = (int)($_POST['quote_id'] ?? $_GET['quote_id'] ?? 0);
$setLeadWon = (int)($_POST['set_lead_won'] ?? $_GET['set_lead_won'] ?? 0);

if ($quoteId <= 0) {
    flash('Invalid quote id', 'danger');
    redirect('/sales_quotes/sales_quotes_list.php');
}

$pdo = db();
$pdo->beginTransaction();

try {
    // Lock quote
    $q = $pdo->prepare("
        SELECT q.*, p.name AS party_name
          FROM sales_quotes q
          LEFT JOIN parties p ON p.id = q.party_id
         WHERE q.id = :id AND q.deleted_at IS NULL
         FOR UPDATE
    ");
    $q->execute([':id' => $quoteId]);
    $quote = $q->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        throw new RuntimeException('Quote not found or deleted.');
    }

    // Already converted?
    $chk = $pdo->prepare("SELECT id FROM sales_orders WHERE quote_id = :qid LIMIT 1");
    $chk->execute([':qid' => $quoteId]);
    if ($chk->fetchColumn()) {
        throw new RuntimeException('This quote is already converted to a Sales Order.');
    }

    // Fetch quote items (MAPPED TO YOUR SCHEMA)
    $qi = $pdo->prepare("
        SELECT sl_no, item_code, item_name, qty, uom, rate, discount_pct, tax_pct, line_total
          FROM sales_quote_items
         WHERE quote_id = :qid
         ORDER BY sl_no
    ");
    $qi->execute([':qid' => $quoteId]);
    $items = $qi->fetchAll(PDO::FETCH_ASSOC);

    // Create SO header (adjust fields as per your sales_orders table)
    $soNo = next_no('SO');
    $insSO = $pdo->prepare("
        INSERT INTO sales_orders
            (code, quote_id, party_id, contact_id, site_id, title, order_date, currency, fx_rate,
             subtotal, discount_total, tax_total, grand_total, status, created_by, created_at)
        VALUES
            (:code, :quote_id, :party_id, :contact_id, :site_id, :title, CURRENT_DATE, :currency, :fx_rate,
             :subtotal, :discount_total, :tax_total, :grand_total, 'Draft', :uid, NOW())
    ");
    $insSO->execute([
        ':code'           => $soNo,
        ':quote_id'       => $quote['id'],
        ':party_id'       => $quote['party_id'],
        ':contact_id'     => $quote['contact_id'],
        ':site_id'        => $quote['site_id'],
        ':title'          => $quote['title'] ?: ('Order for ' . ($quote['party_name'] ?? '')),
        ':currency'       => $quote['currency'] ?? 'INR',
        ':fx_rate'        => $quote['fx_rate'] ?? 1.0,
        ':subtotal'       => $quote['subtotal'] ?? 0,
        ':discount_total' => $quote['discount_total'] ?? 0,
        ':tax_total'      => $quote['tax_total'] ?? 0,
        ':grand_total'    => $quote['grand_total'] ?? 0,
        ':uid'            => (int)current_user_id(),
    ]);
    $soId = (int)$pdo->lastInsertId();

    // Copy items into sales_order_items (assumes same-named columns exist there)
    if ($items) {
        $insItem = $pdo->prepare("
            INSERT INTO sales_order_items
                (order_id, sl_no, item_code, item_name, qty, uom, rate, discount_pct, tax_pct, line_total)
            VALUES
                (:order_id, :sl_no, :item_code, :item_name, :qty, :uom, :rate, :discount_pct, :tax_pct, :line_total)
        ");
        foreach ($items as $it) {
            $insItem->execute([
                ':order_id'     => $soId,
                ':sl_no'        => (int)$it['sl_no'],
                ':item_code'    => (string)($it['item_code'] ?? ''),
                ':item_name'    => (string)$it['item_name'],
                ':qty'          => (float)$it['qty'],
                ':uom'          => (string)$it['uom'],
                ':rate'         => (float)$it['rate'],
                ':discount_pct' => (float)($it['discount_pct'] ?? 0),
                ':tax_pct'      => (float)($it['tax_pct'] ?? 0),
                ':line_total'   => (float)$it['line_total'],
            ]);
        }
    }

    // Optional: mark related lead Won
    if ($setLeadWon && (int)$quote['lead_id'] > 0) {
        $pdo->prepare("UPDATE crm_leads SET stage='Won', updated_at=NOW() WHERE id=:lid")
            ->execute([':lid' => (int)$quote['lead_id']]);
    }

    // Mark quote as converted
    $pdo->prepare("UPDATE sales_quotes SET status='Converted', updated_at=NOW() WHERE id=:qid")
        ->execute([':qid' => $quoteId]);

    $pdo->commit();

    flash("Sales Order #{$soNo} created from Quote #{$quote['code']}.", 'success');
    redirect('/sales_orders/sales_order_view.php?id=' . $soId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Helpful guidance if sales_order_items schema differs
    $msg = $e->getMessage();
    if (stripos($msg, 'Unknown column') !== false) {
        $msg .= ' — Adjust the INSERT column list in sales_order_items to your schema.';
    }
    flash('Conversion failed: ' . h($msg), 'danger');
    redirect('/sales_quotes/sales_quotes_view.php?id=' . $quoteId);
}
