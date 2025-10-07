<?php
/** PATH: /public_html/includes/services/NumberingService.php */
declare(strict_types=1);

if (!function_exists('require_base')) {
  function require_base(string $rel): void {
    require_once __DIR__ . '/../' . ltrim($rel, '/');
  }
}
require_base('db.php');

/**
 * Backward-compatible numbering service:
 * 1) Known codes in MAP -> per-document tables (current behavior)
 * 2) Otherwise -> generic table number_sequences(series, year, seq)
 */
final class NumberingService
{
    private const MAP = [
        'REQ'   => ['table' => 'req_sequences',       'prefix' => 'MR'],
        'ISSUE' => ['table' => 'issue_sequences',     'prefix' => 'MI'],
        'GRN'   => ['table' => 'grn_sequences',       'prefix' => 'GRN'],
        'RTV'   => ['table' => 'rtv_sequences',       'prefix' => 'RTV'],
        'TRF'   => ['table' => 'transfer_sequences',  'prefix' => 'TRF'],
        'ADJ'   => ['table' => 'adjust_sequences',    'prefix' => 'ADJ'],
        'PR'    => ['table' => 'pr_sequences',        'prefix' => 'PR'],
        'IND'   => ['table' => 'indent_sequences',    'prefix' => 'IND'],
        'PA'    => ['table' => 'pa_sequences',        'prefix' => 'PA'],
        'GP'    => ['table' => 'gp_sequences',        'prefix' => 'GP'],
        'GPR'   => ['table' => 'gpr_sequences',       'prefix' => 'GPR'],
    ];

    public static function next(PDO $pdo, string $code): string
    {
        $code = strtoupper(trim($code));
        $year = (int)date('Y');

        if (isset(self::MAP[$code])) {
            $meta   = self::MAP[$code];
            return self::nextFromPerDocTable($pdo, $meta['table'], $meta['prefix'], $year);
        }
        return self::nextFromGenericTable($pdo, $code, $year);
    }

    private static function nextFromPerDocTable(PDO $pdo, string $table, string $prefix, int $year): string
    {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) { $pdo->beginTransaction(); }

        try {
            $stmt = $pdo->prepare("SELECT seq FROM {$table} WHERE year = ? FOR UPDATE");
            $stmt->execute([$year]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $seq = (int)$row['seq'] + 1;
                $pdo->prepare("UPDATE {$table} SET seq=? WHERE year=?")->execute([$seq, $year]);
            } else {
                $seq = 1;
                $pdo->prepare("INSERT INTO {$table}(year, seq) VALUES(?, ?)")->execute([$year, $seq]);
            }
            if ($ownTx) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        return sprintf('%s-%04d-%04d', $prefix, $year, $seq);
    }

    private static function nextFromGenericTable(PDO $pdo, string $series, int $year): string
    {
        self::ensureGenericTable($pdo);

        $ownTx = !$pdo->inTransaction();
        if ($ownTx) { $pdo->beginTransaction(); }

        try {
            $sel = $pdo->prepare("SELECT seq FROM number_sequences WHERE series = ? AND year = ? FOR UPDATE");
            $sel->execute([$series, $year]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $seq = (int)$row['seq'] + 1;
                $pdo->prepare("UPDATE number_sequences SET seq=? WHERE series=? AND year=?")
                    ->execute([$seq, $series, $year]);
            } else {
                $seq = 1;
                $pdo->prepare("INSERT INTO number_sequences(series, year, seq) VALUES(?, ?, ?)")
                    ->execute([$series, $year, $seq]);
            }
            if ($ownTx) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        return sprintf('%s-%04d-%04d', $series, $year, $seq);
    }

    private static function ensureGenericTable(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS number_sequences (
              series VARCHAR(16) NOT NULL,
              year   INT NOT NULL,
              seq    INT NOT NULL,
              PRIMARY KEY (series, year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
}
