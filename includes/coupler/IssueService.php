
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class IssueService
{
    public function __construct(private PDO $pdo) {}

    public function create(string $date, ?int $cc=null, ?int $job=null, ?string $notes=null): int {
        $st=$this->pdo->prepare("INSERT INTO issue_headers (issue_date,cost_center_id,job_id,notes) VALUES (?,?,?,?)");
        $st->execute([$date,$cc,$job,$notes]);
        return (int)$this->pdo->lastInsertId();
    }
    public function addLine(int $hdrId, array $in): int {
        $hdr = $this->getHdr($hdrId,true); if($hdr['status']!=='draft') throw new RuntimeException("Only draft issues editable");
        $item=(int)($in['item_id']??0); $wh=(int)($in['warehouse_id']??0); $qty=(float)($in['qty_base']??0);
        if($item<=0||$wh<=0||$qty<=0) throw new RuntimeException("item_id, warehouse_id, qty_base required");
        $lot = $in['lot_id'] ?? null; $piece=$in['piece_id'] ?? null;
        $heat=$in['heat_no'] ?? null; $plate=$in['plate_no'] ?? null;
        $st=$this->pdo->prepare("INSERT INTO issue_lines (header_id,item_id,warehouse_id,lot_id,piece_id,qty_base,uom,heat_no,plate_no) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([$hdrId,$item,$wh,$lot,$piece,$qty,$in['uom']??null,$heat,$plate]);
        return (int)$this->pdo->lastInsertId();
    }
    public function post(int $hdrId): array {
        $hdr=$this->getHdr($hdrId,true); if($hdr['status']!=='draft') throw new RuntimeException("Already posted/void");
        $lines=$this->getLines($hdrId); if(!$lines) throw new RuntimeException("No lines");
        $this->pdo->beginTransaction();
        try{
            $total=0.0;
            foreach($lines as $ln){
                $qty=(float)$ln['qty_base']; $item=(int)$ln['item_id']; $wh=(int)$ln['warehouse_id'];
                // reduce company onhand
                $upd=$this->pdo->prepare("UPDATE stock_onhand SET qty=GREATEST(qty-?,0) WHERE item_id=? AND warehouse_id=? AND owner_type='company' AND owner_id IS NULL");
                $upd->execute([$qty,$item,$wh]);
                $total += $qty;
                // (Optional) mark piece/lot status if fully consumed
            }
            // GL: Dr WIP/Expense, Cr Inventory
            $this->enqueueGL('ISSUE_POSTED',[
                'dr'=>'510100-Consumption / WIP',
                'cr'=>'140100-RM Inventory',
                'qty_total'=>round($total,6),
                'refs'=>['issue_id'=>$hdrId,'job_id'=>$hdr['job_id'],'cost_center_id'=>$hdr['cost_center_id']]
            ]);
            $this->pdo->prepare("UPDATE issue_headers SET status='posted' WHERE id=?")->execute([$hdrId]);
            $this->pdo->commit();
            return ['posted'=>true,'lines'=>count($lines),'qty_total'=>round($total,6)];
        } catch(\Throwable $e){ $this->pdo->rollBack(); throw $e; }
    }
    private function getHdr(int $id,bool $lock=false): array { $sql="SELECT * FROM issue_headers WHERE id=?"; if($lock) $sql.=" FOR UPDATE"; $st=$this->pdo->prepare($sql); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException("Issue not found"); return $r; }
    private function getLines(int $id): array { $st=$this->pdo->prepare("SELECT * FROM issue_lines WHERE header_id=?"); $st->execute([$id]); return $st->fetchAll(PDO::FETCH_ASSOC)?:[]; }
    private function enqueueGL(string $event, array $payload): void { $st=$this->pdo->prepare("INSERT INTO gl_interface_outbox (event_type,payload_json) VALUES (?,?)"); $st->execute([$event,json_encode($payload,JSON_UNESCAPED_UNICODE)]); }
}
