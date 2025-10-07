<?php
declare(strict_types=1);

final class ItemCodeGenerator
{
    public function __construct(private PDO $pdo) {}

    public function generate(int $categoryId, ?int $subcategoryId): string
    {
        // Pull codes + pattern overrides
        $cat = $this->fetchOne("SELECT code, code_pattern FROM material_categories WHERE id = ?", [$categoryId]);
        if (!$cat) throw new RuntimeException("Invalid category.");

        $subCode = '';
        $pattern = $cat['code_pattern'] ?: null;

        if ($subcategoryId) {
            $sub = $this->fetchOne("SELECT code, code_pattern FROM material_subcategories WHERE id = ? AND category_id = ?", [$subcategoryId, $categoryId]);
            if (!$sub) throw new RuntimeException("Invalid subcategory for category.");
            $subCode = $sub['code'];
            // precedence: sub > cat > default
            $pattern = $sub['code_pattern'] ?: $pattern;
        }

        $pattern ??= '{CAT}-{SUB}-{YYYY}-{SEQ4}';

        // Compute sequence scope
        $year = (int)date('Y');
        [$scope, $scopeId] = $subcategoryId
            ? ['subcategory', $subcategoryId]
            : ['category', $categoryId];

        // If SUB is empty (no subcategory), still use category scope; if you want global, switch to 'default'
        if (empty($subCode)) { $scope = 'category'; $scopeId = $categoryId; }

        // Next sequence atomically
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, last_seq FROM item_code_sequences
                WHERE scope = ? AND ".($scopeId === null ? "scope_id IS NULL" : "scope_id = ?")." AND year = ?
                FOR UPDATE
            ");
            $params = [$scope];
            if ($scopeId === null) $params[] = $year; else $params[] = $scopeId;
            $params[] = $year;
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $next = (int)$row['last_seq'] + 1;
                $upd = $this->pdo->prepare("UPDATE item_code_sequences SET last_seq = ? WHERE id = ?");
                $upd->execute([$next, (int)$row['id']]);
            } else {
                $next = 1;
                $ins = $this->pdo->prepare("INSERT INTO item_code_sequences (scope, scope_id, year, last_seq) VALUES (?,?,?,?)");
                $ins->execute([$scope, $scopeId, $year, $next]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $seq4 = str_pad((string)$next, 4, '0', STR_PAD_LEFT);

        // Tokens
        $replacements = [
            '{CAT}'  => (string)$cat['code'],
            '{SUB}'  => $subCode,
            '{YYYY}' => (string)$year,
            '{YY}'   => substr((string)$year, -2),
            '{SEQ4}' => $seq4,
            '{SEQ3}' => substr($seq4, -3),
        ];

        $code = strtr($pattern, $replacements);
        // tidy: collapse double dashes if SUB blank, then trim
        $code = preg_replace('/-{2,}/', '-', $code);
        $code = trim($code, '-');

        return $code;
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
