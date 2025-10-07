
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class ValuationService
{
    public function __construct(private PDO $pdo) {}

    public function rebuildInputs(string $fromDate, string $toDate): int {
        $this->pdo->prepare("DELETE FROM inv_cost_inputs WHERE event_date BETWEEN ? AND ?")->execute([$fromDate,$toDate]);
        $n=0;
        $st=$this->pdo->prepare("
            SELECT gi.id AS grn_item_id, gi.item_id, gi.warehouse_id,
                   gi.qty_accepted AS qty, gi.unit_price AS rate, g.grn_date
            FROM grn_items gi
            JOIN grn g ON g.id=gi.grn_id
            WHERE g.status='posted' AND g.grn_date BETWEEN ? AND ?
        ");
        $st->execute([$fromDate,$toDate]);
        while($r=$st->fetch(PDO::FETCH_ASSOC)){
            $qty=(float)$r['qty']; $rate=(float)$r['rate']; $amt=round($qty*$rate,2);
            $ins=$this->pdo->prepare("INSERT INTO inv_cost_inputs (event_date,item_id,warehouse_id,qty,rate,amount,source,source_id) VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$r['grn_date'],(int)$r['item_id'],(int)$r['warehouse_id'],$qty,$rate,$amt,'GRN',(int)$r['grn_item_id']]);
            $n++;
        }
        return $n;
    }

    public function snapshot(string $snapDate): int {
        $this->pdo->beginTransaction();
        try{
            $st=$this->pdo->prepare("
                SELECT item_id, warehouse_id,
                       SUM(amount) / NULLIF(SUM(qty),0) AS wavg_rate
                FROM inv_cost_inputs
                WHERE event_date <= ?
                GROUP BY item_id, warehouse_id
            ");
            $st->execute([$snapDate]);
            $ins = $this->pdo->prepare("REPLACE INTO inv_valuation_snapshots (snap_date,item_id,warehouse_id,qty_onhand,wavg_rate,amount) VALUES (?,?,?,?,?,?)");
            $count=0;
            while($r=$st->fetch(PDO::FETCH_ASSOC)){
                $qty_onhand = $this->onhand((int)$r['item_id'], (int)$r['warehouse_id']);
                $rate = (float)$r['wavg_rate']; $amt = round($qty_onhand * $rate, 2);
                $ins->execute([$snapDate,(int)$r['item_id'],(int)$r['warehouse_id'],$qty_onhand,$rate,$amt]);
                $count++;
            }
            $this->pdo->commit();
            return $count;
        } catch(\Throwable $e){ $this->pdo->rollBack(); throw $e; }
    }

    private function onhand(int $item, int $wh): float {
        $st=$this->pdo->prepare("SELECT qty FROM stock_onhand WHERE item_id=? AND warehouse_id=?");
        $st->execute([$item,$wh]); $r=$st->fetch(PDO::FETCH_ASSOC);
        return $r ? (float)$r['qty'] : 0.0;
    }
}
