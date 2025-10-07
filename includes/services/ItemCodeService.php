<?php
declare(strict_types=1);

/**
 * Material code generator with tokenized patterns.
 * Priority of pattern:
 *  - subcategory: "SUB_{id}" if exists
 *  - category:    "CAT_{id}" if exists
 *  - 'DEFAULT' pattern
 *
 * Tokens supported:
 * {CAT} {SUB} {YYYY} {YY} {T} {W} {L} {SEQ2..SEQ6}
 */
final class ItemCodeService {

  public static function generate(array $input): string {
    // Expected in $input:
    // category_id (int), subcategory_id (int|null), uom_id (int),
    // thickness_mm (float|null), width_mm (float|null), length_mm (float|null)
    $pdo = db();

    // Fetch category/subcategory prefixes
    $cat = self::fetchOne($pdo, "SELECT id, prefix FROM material_categories WHERE id=? AND status='active' LIMIT 1", [$input['category_id']]);
    if (!$cat) throw new RuntimeException("Invalid category");

    $sub = null;
    if (!empty($input['subcategory_id'])) {
      $sub = self::fetchOne($pdo, "SELECT id, prefix FROM material_subcategories WHERE id=? AND status='active' LIMIT 1", [$input['subcategory_id']]);
    }

    $catPrefix = $cat['prefix'];
    $subPrefix = $sub['prefix'] ?? 'GEN';

    // Choose pattern (SUB > CAT > DEFAULT)
    $patternRow = self::fetchOne($pdo, "SELECT pattern FROM code_patterns WHERE pattern_key=? AND active=1 LIMIT 1", ["SUB_" . ($sub['id'] ?? 0)]);
    if (!$patternRow) {
      $patternRow = self::fetchOne($pdo, "SELECT pattern FROM code_patterns WHERE pattern_key=? AND active=1 LIMIT 1", ["CAT_" . $cat['id']]);
    }
    if (!$patternRow) {
      $patternRow = self::fetchOne($pdo, "SELECT pattern FROM code_patterns WHERE pattern_key='DEFAULT' AND active=1 LIMIT 1");
    }
    $pattern = $patternRow['pattern'] ?? '{CAT}-{SUB}-{YYYY}-{SEQ4}';

    // Year parts
    $yyyy = (int)date('Y');
    $yy   = (int)date('y');

    // Dimension tokens (strip trailing .00)
    $fmt = function($n) {
      if ($n === null) return '';
      $s = rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
      return $s === '' ? '0' : $s;
    };
    $T = $fmt($input['thickness_mm'] ?? null);
    $W = $fmt($input['width_mm'] ?? null);
    $L = $fmt($input['length_mm'] ?? null);

    // First, replace non-seq tokens
    $code = strtr($pattern, [
      '{CAT}'  => $catPrefix,
      '{SUB}'  => $subPrefix,
      '{YYYY}' => (string)$yyyy,
      '{YY}'   => str_pad((string)$yy, 2, '0', STR_PAD_LEFT),
      '{T}'    => $T,
      '{W}'    => $W,
      '{L}'    => $L,
    ]);

    // Sequence: detect {SEQn}
    if (!preg_match('/\{SEQ([2-6])\}/', $code, $m)) {
      // fallback to 4-digit
      $code .= '-{SEQ4}';
      $m = [0, '4'];
    }
    $seqDigits = (int)$m[1];

    // Build seq key – keeps sequences per CAT-SUB-YYYY by default
    // Feel free to change to $code without {SEQ*} for more granularity.
    $seqKey = "{$catPrefix}-{$subPrefix}-{$yyyy}";
    $next = self::nextSeq($pdo, $seqKey, $yyyy);

    $seqStr = str_pad((string)$next, $seqDigits, '0', STR_PAD_LEFT);
    $code = preg_replace('/\{SEQ[2-6]\}/', $seqStr, $code);

    // Ensure uniqueness; retry bump if collision (rare)
    $tries = 0;
    while ($tries < 3) {
      $exists = self::fetchOne($pdo, "SELECT id FROM items WHERE material_code=? LIMIT 1", [$code]);
      if (!$exists) break;
      $next    = self::nextSeq($pdo, $seqKey, $yyyy); // reserve next
      $seqStr  = str_pad((string)$next, $seqDigits, '0', STR_PAD_LEFT);
      $code    = preg_replace('/\d{'. $seqDigits .'}/', $seqStr, $code, 1);
      $tries++;
    }

    return $code;
  }

  private static function fetchOne(PDO $pdo, string $sql, array $bind = []): ?array {
    $st = $pdo->prepare($sql); $st->execute($bind);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }

  private static function nextSeq(PDO $pdo, string $seqKey, ?int $year): int {
    $pdo->beginTransaction();
    try {
      $sel = $pdo->prepare("SELECT id, next_value FROM sequences WHERE seq_key=? AND (year<=>?) LIMIT 1");
      $sel->execute([$seqKey, $year]);
      $row = $sel->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        $next = (int)$row['next_value'];
        $upd = $pdo->prepare("UPDATE sequences SET next_value = next_value + 1 WHERE id=?");
        $upd->execute([$row['id']]);
      } else {
        $next = 1;
        $ins = $pdo->prepare("INSERT INTO sequences (seq_key, year, next_value) VALUES (?,?,2)");
        $ins->execute([$seqKey, $year]);
      }

      $pdo->commit();
      return $next;
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
