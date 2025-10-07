
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class ScrapRemnantService
{
    public function __construct(private PDO $pdo) {}

    public function markRemnant(int $pieceId, float $qty, string $reason): int {
        $p=$this->piece($pieceId); if(!$p) throw new RuntimeException("piece not found");
        $st=$this->pdo->prepare("INSERT INTO remnant_actions (piece_id,action,qty_base,reason) VALUES (?,?,?,?)");
        $st->execute([$pieceId,'mark_remnant',$qty,$reason]);
        // optional: update piece meta/status
        return (int)$this->pdo->lastInsertId();
    }

    public function convertToScrap(int $pieceId, float $qty, int $scrapItemId, int $warehouseId, string $reason): array {
        $p=$this->piece($pieceId); if(!$p) throw new RuntimeException("piece not found");
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare("INSERT INTO remnant_actions (piece_id,action,qty_base,reason) VALUES (?,?,?,?)")->execute([$pieceId,'convert_to_scrap',$qty,$reason]);
            // Increase scrap onhand (company)
            $sel=$this->pdo->prepare("SELECT qty FROM stock_onhand WHERE item_id=? AND warehouse_id=? AND owner_type='company' AND owner_id IS NULL");
            $sel->execute([$scrapItemId,$warehouseId]);
            if($sel->fetch()) $this->pdo->prepare("UPDATE stock_onhand SET qty=qty+? WHERE item_id=? AND warehouse_id=? AND owner_type='company' AND owner_id IS NULL")->execute([$qty,$scrapItemId,$warehouseId]);
            else $this->pdo->prepare("INSERT INTO stock_onhand (item_id,warehouse_id,qty,owner_type,owner_id) VALUES (?,?,?,'company',NULL)")->execute([$scrapItemId,$warehouseId,$qty]);
            $this->pdo->prepare("INSERT INTO scrap_receipts (item_id,warehouse_id,qty_base,source,source_id,notes) VALUES (?,?,?,?,?,?)")->execute([$scrapItemId,$warehouseId,$qty,'remnant',$pieceId,$reason]);
            // GL
            $this->enqueueGL('SCRAP_RECEIPT',['dr'=>'140500-Scrap Inventory','cr'=>'510100-Consumption / WIP','qty'=>$qty,'refs'=>['piece_id'=>$pieceId]]);
            $this->pdo->commit();
            return ['ok'=>true,'qty'=>$qty];
        } catch(\Throwable $e){ $this->pdo->rollBack(); throw $e; }
    }

    private function piece(int $id): ?array { $st=$this->pdo->prepare("SELECT * FROM lot_pieces WHERE id=?"); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?:null; }
    private function enqueueGL(string $event, array $payload): void { $st=$this->pdo->prepare("INSERT INTO gl_interface_outbox (event_type,payload_json) VALUES (?,?)"); $st->execute([$event,json_encode($payload,JSON_UNESCAPED_UNICODE)]); }
}
