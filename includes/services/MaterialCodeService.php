<?php
declare(strict_types=1);

final class MaterialCodeService
{
    public function __construct(private PDO $pdo) {}

    public function generate(
        int $categoryId,
        ?int $subcategoryId,
        ?float $T = null,
        ?float $W = null,
        ?float $L = null
    ): string {
        // 1) Load prefixes
        $cat = $this->fetchOne("SELECT id, prefix FROM material_categories WHERE id=? AND status='active'", [$categoryId]);
        if (!$cat) { throw new RuntimeException("Invalid category"); }
        $catPrefix = (string)$cat['prefix'];

        $subPrefix = '';
        if ($subcategoryId) {
            $sub = $this->fetchOne("SELECT id, prefix FROM material_subcategories WHERE id=? AND category_id=?", [$subcategoryId, $categoryId]);
            if (!$sub) { throw new RuntimeException("Invalid subcategory"); }
            $subPrefix = (string)$sub['prefix'];
        }

        // 2) Resolve pattern: SUBCAT:{id} > CAT:{id} > DEFAULT
        $pattern = $this->fetchPattern($subcategoryId, $categoryId) ?? '{CAT}-{SUB}-{YYYY}-{SEQ4}';

        // 3) Next sequence (yearly, scoped to CAT-SUB-YYYY)
        $year = (int)date('Y');
        $seqKey = trim($catPrefix . '-' . $subPrefix, '-'); // e.g., PL-FLG or PL
        if ($seqKey === '') { $seqKey = $catPrefix ?: 'GEN'; }
        $nextSeq = $this->nextSeq($seqKey, $year); // increments in DB
        // Normalize dimensions (strip trailing .00 etc.)
        $fmt = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');

        // 4) Render tokens
        $rep = [
            '{CAT}'  => $catPrefix,
            '{SUB}'  => $subPrefix,
            '{YYYY}' => (string)$year,
            '{YY}'   => substr((string)$year, -2),
            '{T}'    => $fmt($T),
            '{W}'    => $fmt($W),
            '{L}'    => $fmt($L),
        ];
        // Support {SEQ2}..{SEQ6}
        for ($n = 2; $n <= 6; $n++) {
            $rep['{SEQ' . $n . '}'] = str_pad((string)$nextSeq, $n, '0', STR_PAD_LEFT);
        }

        $code = strtr($pattern, $rep);
        // collapse duplicate dashes if SUB/D dims empty; trim
        $code = preg_replace('/-{2,}/', '-', $code);
        return trim($code, '-');
    }

    private function fetchPattern(?int $subcategoryId, int $categoryId): ?string {
        // Prefer pattern_key = "SUBCAT:{id}"
        if ($subcategoryId) {
            $p = $this->fetchOne("SELECT pattern FROM code_patterns WHERE active=1 AND pattern_key=?", ['SUBCAT:' . (int)$subcategoryId]);
            if ($p) return (string)$p['pattern'];
        }
        // Then "CAT:{id}"
        $p = $this->fetchOne("SELECT pattern FROM code_patterns WHERE active=1 AND pattern_key=?", ['CAT:' . (int)$categoryId]);
        if ($p) return (string)$p['pattern'];
        // Then DEFAULT
        $p = $this->fetchOne("SELECT pattern FROM code_patterns WHERE active=1 AND pattern_key='DEFAULT'");
        return $p ? (string)$p['pattern'] : null;
    }

    private function nextSeq(string $seqKey, int $year): int {
        $this->pdo->beginTransaction();
        try {
            // Try select-for-update the existing row
            $sel = $this->pdo->prepare("SELECT id, next_value FROM sequences WHERE seq_key=? AND year=? FOR UPDATE");
            $sel->execute([$seqKey, $year]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $next = (int)$row['next_value'];
                $upd = $this->pdo->prepare("UPDATE sequences SET next_value = next_value + 1 WHERE id=?");
                $upd->execute([(int)$row['id']]);
            } else {
                // Insert new sequence row starting at 2, return 1
                $ins = $this->pdo->prepare("INSERT INTO sequences (seq_key, year, next_value) VALUES (?,?,2)");
                $ins->execute([$seqKey, $year]);
                $next = 1;
            }
            $this->pdo->commit();
            return $next;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function fetchOne(string $sql, array $params = []): ?array {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}
