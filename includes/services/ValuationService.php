<?php
/** PATH: /public_html/includes/services/ValuationService.php */
declare(strict_types=1);

if (!function_exists('require_base')) {
  function require_base(string $rel): void { require_once __DIR__ . '/../' . ltrim($rel, '/'); }
}
require_base('db.php');

final class ValuationService
{
    public static function getAvg(PDO $pdo, int $item_id, int $warehouse_id): array
    {
        $stmt = $pdo->prepare("SELECT avg_cost, qty_basis FROM stock_avg WHERE item_id=? AND warehouse_id=?");
        $stmt->execute([$item_id, $warehouse_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return ['avg_cost' => 0.0, 'qty_basis' => 0.0];
        return ['avg_cost' => (float)$row['avg_cost'], 'qty_basis' => (float)$row['qty_basis']];
    }

    /** Update moving average when positive stock comes in */
    public static function onPositiveReceipt(PDO $pdo, int $item_id, int $warehouse_id, float $qty_in, float $unit_cost): void
    {
        if ($qty_in <= 0) return;

        // current basis
        $cur = self::getAvg($pdo, $item_id, $warehouse_id);
        $prev_qty = $cur['qty_basis'];
        $prev_avg = $cur['avg_cost'];

        // new average
        $new_qty = $prev_qty + $qty_in;
        $new_avg = ($prev_qty * $prev_avg + $qty_in * $unit_cost) / max(0.000001, $new_qty);

        // upsert
        $pdo->prepare("
          INSERT INTO stock_avg (item_id, warehouse_id, avg_cost, qty_basis)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE avg_cost=VALUES(avg_cost), qty_basis=VALUES(qty_basis)
        ")->execute([$item_id, $warehouse_id, $new_avg, $new_qty]);
    }

    /** Decrease basis on issue (does not change avg cost; avg stays until next receipt) */
    public static function onIssue(PDO $pdo, int $item_id, int $warehouse_id, float $qty_out): void
    {
        if ($qty_out <= 0) return;
        $cur = self::getAvg($pdo, $item_id, $warehouse_id);
        $new_qty = max(0.0, $cur['qty_basis'] - $qty_out);
        $pdo->prepare("
          INSERT INTO stock_avg (item_id, warehouse_id, avg_cost, qty_basis)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE qty_basis=VALUES(qty_basis)
        ")->execute([$item_id, $warehouse_id, $cur['avg_cost'], $new_qty]);
    }
}
