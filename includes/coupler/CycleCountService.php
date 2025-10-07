
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class CycleCountService
{
    public function __construct(private PDO $pdo) {}

    public function create(string $date, int $warehouseId, ?string $notes=null): int {
        $st=$this->pdo->prepare("INSERT INTO cc_headers (cc_date,warehouse_id,notes) VALUES (?,?,?)");
        $st->execute([$date,$warehouseId,$notes]); return (int)$this->pdo->lastInsertId();
    }
    public function addLine(int $hdrId, int $itemId, float $counted): int {
        $hdr=$this->hdr($hdrId,true); if($hdr['status']!=='draft') throw new RuntimeException("Only draft");
        $sys = $this->onhand($itemId, (int)$hdr['warehouse_id']);
        $var = round($counted - $sys, 6);
        $st=$this->pdo->prepare("INSERT INTO cc_lines (header_id,item_id,system_qty,counted_qty,variance) VALUES (?,?,?,?,?)");
        $st->execute([$hdrId,$itemId,$sys,$counted,$var]); return (int)$this->pdo->lastInsertId();
    }
    public function post(int $hdrId): array {
        $hdr=$this->hdr($hdrId,true); if($hdr['status']!=='draft') throw new RuntimeException("Already posted/void");
        $lines=$this->lines($hdrId); if(!$lines) throw new RuntimeException("No lines");
        $this->pdo->beginTransaction();
        try{
            $sum=0.0;
            foreach($lines as $ln){
                $delta=(float)$ln['variance']; if(abs($delta)<1e-9) continue;
                $this->applyOnhand((int)$ln['item_id'], (int)$hdr['warehouse_id'], $delta);
                $sum += $delta;
            }
            $this->enqueueGL('CYCLECOUNT_POSTED',[
                'dr'=>$sum>0?'140100-RM Inventory':'710910-Inventory Shrinkage',
                'cr'=>$sum>0?'710910-Inventory Shrinkage':'140100-RM Inventory',
                'qty_total'=>round(abs($sum),6),
                'refs'=>['cc_id'=>$hdrId,'warehouse_id'=>(int)$hdr['warehouse_id']]
            ]);
            $this->pdo->prepare("UPDATE cc_headers SET status='posted' WHERE id=?")->execute([$hdrId]);
            $this->pdo->commit();
            return ['posted'=>True,'lines'=>count($lines),'variance_total'=>round($sum,6)];
        } catch(\Throwable $e){ $this->pdo->rollBack(); throw $e; }
    }

    private function hdr(int $id,bool $lock=false): array{ $sql="SELECT * FROM cc_headers WHERE id=?"; if($lock)$sql.=" FOR UPDATE"; $st=$this->pdo->prepare($sql);$st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException("not found"); return $r; }
    private function lines(int $id): array{ $st=$this->pdo->prepare("SELECT * FROM cc_lines WHERE header_id=?"); $st->execute([$id]); return $st->fetchAll(PDO::FETCH_ASSOC)?:[]; }
    private function onhand(int $item,int $wh): float{ $st=$this->pdo->prepare("SELECT qty FROM stock_onhand WHERE item_id=? AND warehouse_id=? AND owner_type='company' AND (owner_id IS NULL OR owner_id=0)"); $st->execute([$item,$wh]); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?(float)$r['qty']:0.0; }
    private function applyOnhand(int $item,int $wh,float $delta): void{
        $sel=$this->pdo->prepare("SELECT qty FROM stock_onhand WHERE item_id=? AND warehouse_id=? AND owner_type='company' AND owner_id IS NULL"); $sel->execute([$item,$wh]);
        if($sel->fetch()) $this->pdo->prepare("UPDATE stock_onhand SET qty=qty+? WHERE item_id=? AND warehouse_id=? AND owner_type='company' AND owner_id IS NULL")->execute([$delta,$item,$wh]);
        else $this->pdo->prepare("INSERT INTO stock_onhand (item_id,warehouse_id,qty,owner_type,owner_id) VALUES (?,?,?,'company',NULL)")->execute([$item,$wh,$delta]);
    }
    private function enqueueGL(string $event, array $payload): void{ $st=$this->pdo->prepare("INSERT INTO gl_interface_outbox (event_type,payload_json) VALUES (?,?)"); $st->execute([$event,json_encode($payload,JSON_UNESCAPED_UNICODE)]); }
}
