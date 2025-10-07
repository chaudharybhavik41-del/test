<?php
/** PATH: /public_html/purchase/quote_seq.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

/** ---------- INFORMATION_SCHEMA helpers ---------- */
function _table_exists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}
function _table_cols(PDO $pdo, string $table): array {
    $s = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$table]);
    return array_map(fn($r) => $r['COLUMN_NAME'], $s->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Find (or create) a quote sequence table and detect the year column.
 * Returns ['table' => string, 'year_col' => 'y'|'yr'|'year'].
 * If nothing exists, creates `quote_sequences` with (id, yr, seq).
 */
function _find_seq_table(PDO $pdo): array {
    // Preferred names first
    $candidates = ['quote_sequences', 'purchase_quote_sequences', 'quote_sequence', 'quotes_sequences', 'quote_seq'];
    foreach ($candidates as $t) {
        if (_table_exists($pdo, $t)) {
            $cols = _table_cols($pdo, $t);
            foreach (['yr','y','year'] as $yc) {
                if (in_array($yc, $cols, true) && in_array('seq', $cols, true)) {
                    return ['table' => $t, 'year_col' => $yc];
                }
            }
        }
    }
    // Generic search: any table that has 'seq' and one of the year columns
    $s = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'seq'");
    $seqTables = array_map(fn($r) => $r['TABLE_NAME'], $s->fetchAll(PDO::FETCH_ASSOC));
    foreach ($seqTables as $t) {
        $cols = _table_cols($pdo, $t);
        foreach (['yr','y','year'] as $yc) {
            if (in_array($yc, $cols, true)) {
                return ['table' => $t, 'year_col' => $yc];
            }
        }
    }

    // Nothing found: create our own canonical table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS quote_sequences (
            id  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            yr  SMALLINT UNSIGNED NOT NULL,
            seq INT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_yr (yr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    return ['table' => 'quote_sequences', 'year_col' => 'yr'];
}

/** Return ['table' => 'quotes'|'supplier_quotes'|null, 'no_col' => 'quote_no'|null] for collision checks */
function _find_quotes_table(PDO $pdo): array {
    foreach (['quotes','supplier_quotes'] as $t) {
        if (_table_exists($pdo, $t)) {
            $cols = _table_cols($pdo, $t);
            if (in_array('quote_no', $cols, true)) {
                return ['table' => $t, 'no_col' => 'quote_no'];
            }
        }
    }
    return ['table' => null, 'no_col' => null];
}

/**
 * Generates a unique quote number like QUO-2025-0001.
 * - Locks the single row for the current year.
 * - Safe if called inside an existing transaction (no nested BEGIN).
 * - Verifies uniqueness against quotes.quote_no if available.
 */
function next_quote_no(): string {
    $pdo = db();
    $yrInt = (int)date('Y');
    $PREFIX = 'QUO';

    // Detect tables/columns
    $seq = _find_seq_table($pdo);
    $seqTable = $seq['table'];
    $yearCol  = $seq['year_col'];
    $quotesInfo = _find_quotes_table($pdo);
    $quotesTable = $quotesInfo['table'];
    $quoteNoCol  = $quotesInfo['no_col'];

    $weStartedTx = !$pdo->inTransaction();
    if ($weStartedTx) {
        $pdo->beginTransaction();
    }

    try {
        // Lock latest row for this year (or create it)
        $sel = $pdo->prepare("SELECT id, seq FROM {$seqTable} WHERE {$yearCol}=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $sel->execute([$yrInt]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Insert base row for year with seq = 0
            $ins = $pdo->prepare("INSERT INTO {$seqTable} ({$yearCol}, seq) VALUES (?, 0)");
            $ins->execute([$yrInt]);
            $rowId = (int)$pdo->lastInsertId();
            $seqVal = 0;
        } else {
            $rowId = (int)$row['id'];
            $seqVal = (int)$row['seq'];
        }

        // Prepare optional collision check
        $chk = null;
        if ($quotesTable && $quoteNoCol) {
            $chk = $pdo->prepare("SELECT 1 FROM {$quotesTable} WHERE {$quoteNoCol}=? LIMIT 1");
        }

        // Increment until we find a free number
        do {
            $seqVal++;
            $quoteNo = sprintf('%s-%d-%04d', $PREFIX, $yrInt, $seqVal);

            $exists = false;
            if ($chk) {
                $chk->execute([$quoteNo]);
                $exists = (bool)$chk->fetchColumn();
            }
        } while ($exists);

        // Persist the new seq on the locked row
        $upd = $pdo->prepare("UPDATE {$seqTable} SET seq=? WHERE id=?");
        $upd->execute([$seqVal, $rowId]);

        if ($weStartedTx) {
            $pdo->commit();
        }

        return $quoteNo;

    } catch (Throwable $e) {
        if ($weStartedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
