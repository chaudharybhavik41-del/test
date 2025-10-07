<?php
declare(strict_types=1);

if (!function_exists('uom_convert')) {

  /**
   * Convert quantity between UOM codes (e.g., 'MM' -> 'M', 'KG' -> 'TON').
   * Supports multi-hop paths (MM -> M -> KM) by graph search.
   * Returns float value; throws on no path found.
   *
   * NOTE: We keep conversions linear (factor only, offset=0). Offset is ignored here.
   */
  function uom_convert(float $qty, string $fromCode, string $toCode): float {
    if ($fromCode === $toCode) return $qty;

    $pdo = db();

    // Map codes -> ids
    $mapStmt = $pdo->prepare("SELECT id, code FROM uom WHERE code IN (?, ?) AND status='active'");
    $mapStmt->execute([strtoupper($fromCode), strtoupper($toCode)]);
    $ids = [];
    while ($r = $mapStmt->fetch()) { $ids[$r['code']] = (int)$r['id']; }

    if (!isset($ids[strtoupper($fromCode)]) || !isset($ids[strtoupper($toCode)])) {
      throw new RuntimeException("Unknown or inactive UOM code(s): $fromCode or $toCode");
    }

    $fromId = $ids[strtoupper($fromCode)];
    $toId   = $ids[strtoupper($toCode)];

    // Build adjacency (only once per request; you can cache in $_SESSION if needed)
    $edges = [];
    $stmt = $pdo->query("SELECT from_uom_id, to_uom_id, factor FROM uom_conversions");
    while ($c = $stmt->fetch()) {
      $f = (int)$c['from_uom_id']; $t = (int)$c['to_uom_id']; $fac = (float)$c['factor'];
      $edges[$f][] = [$t, $fac];
    }

    // BFS over graph to find a path; track cumulative factor
    $queue = [ [$fromId, 1.0] ];
    $visited = [$fromId => 1.0];
    $parent  = []; // for optional debugging

    while ($queue) {
      [$node, $cumFactor] = array_shift($queue);
      if ($node === $toId) {
        // done
        return $qty * $cumFactor;
      }
      foreach ($edges[$node] ?? [] as [$next, $fac]) {
        $newFac = $cumFactor * $fac;
        if (!isset($visited[$next])) {
          $visited[$next] = $newFac;
          $parent[$next]  = $node;
          $queue[] = [$next, $newFac];
        }
      }
    }

    throw new RuntimeException("No conversion path from $fromCode to $toCode");
  }

  /**
   * Helper: add or replace a conversion; optionally add inverse.
   */
  function uom_add_conversion(string $fromCode, string $toCode, float $factor, bool $makeInverse=true): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $get = $pdo->prepare("SELECT id FROM uom WHERE code=? AND status='active' LIMIT 1");
      $get->execute([strtoupper($fromCode)]); $fromId = (int)($get->fetch()['id'] ?? 0);
      $get->execute([strtoupper($toCode)]);   $toId   = (int)($get->fetch()['id'] ?? 0);
      if (!$fromId || !$toId) throw new RuntimeException("Unknown UOM codes");

      $up = $pdo->prepare("INSERT INTO uom_conversions (from_uom_id,to_uom_id,factor,offset_val)
                           VALUES (?,?,?,0)
                           ON DUPLICATE KEY UPDATE factor=VALUES(factor), offset_val=0");
      $up->execute([$fromId, $toId, $factor]);

      if ($makeInverse && $factor > 0) {
        $up->execute([$toId, $fromId, 1.0/$factor]);
      }
      $pdo->commit();
    } catch (\Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
