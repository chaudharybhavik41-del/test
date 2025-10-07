<?php
/** PATH: /public_html/includes/numbering.php */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function next_no(string $series): string {
    $series = strtoupper(trim($series));
    $pdo = db();
    $year = (int)date('Y');

    // 1) Preferred: numbering_series(series, last_no, year)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS numbering_series (
          series VARCHAR(32) PRIMARY KEY,
          last_no INT UNSIGNED NOT NULL DEFAULT 0,
          year INT NOT NULL DEFAULT (YEAR(CURDATE())),
          updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->beginTransaction();
        $sel = $pdo->prepare("SELECT last_no, year FROM numbering_series WHERE series=:s FOR UPDATE");
        $sel->execute([':s'=>$series]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $ins = $pdo->prepare("INSERT INTO numbering_series(series,last_no,year) VALUES(:s,0,:y)");
            $ins->execute([':s'=>$series, ':y'=>$year]);
            $last = 0; $rowYear = $year;
        } else {
            $last = (int)$row['last_no']; $rowYear = (int)$row['year'];
        }

        if ($rowYear !== $year) { $last = 0; }
        $next = $last + 1;

        $upd = $pdo->prepare("UPDATE numbering_series SET last_no=:n, year=:y WHERE series=:s");
        $upd->execute([':n'=>$next, ':y'=>$year, ':s'=>$series]);
        $pdo->commit();

        $prefix = $series . '-' . $year . '-';
        return sprintf('%s%04d', $prefix, $next);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // fallthrough to legacy
    }

    // 2) Legacy fallback: quote_sequences(y, seq) → used only for QO
    if ($series === 'QO') {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS quote_sequences (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              y INT NOT NULL,
              seq INT NOT NULL,
              UNIQUE KEY uq_y (y)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->beginTransaction();
            $s = $pdo->prepare("SELECT seq FROM quote_sequences WHERE y=:y FOR UPDATE");
            $s->execute([':y'=>$year]);
            $seq = $s->fetchColumn();
            if ($seq === false) {
                $pdo->prepare("INSERT INTO quote_sequences(y, seq) VALUES(:y, 1)")->execute([':y'=>$year]);
                $seq = 1;
            } else {
                $seq = (int)$seq + 1;
                $pdo->prepare("UPDATE quote_sequences SET seq=:s WHERE y=:y")->execute([':s'=>$seq, ':y'=>$year]);
            }
            $pdo->commit();
            return sprintf('QO-%d-%04d', $year, $seq);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    // 3) Absolute fallback
    return sprintf('%s-%d-%04d', $series, $year, random_int(1, 9999));
}