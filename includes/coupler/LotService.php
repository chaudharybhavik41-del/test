<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class LotService {
  public function __construct(private PDO $pdo) {}

  public function createLotAndPieces(int $itemId, int $warehouseId, string $ownerType='company', ?int $ownerId=null, float $qtyBase=0.0, array $meta=[]): array {
    $this->pdo->beginTransaction();
    try {
      $stmt = $this->pdo->prepare("INSERT INTO stock_lots (item_id, warehouse_id, owner_type, owner_id, parent_lot_id, heat_no, plate_no, received_grn_line_id, qty_base, status, meta)
                                   VALUES (:item_id,:wh,:owner_type,:owner_id,NULL,:heat,:plate,:grn,:qty,'free',:meta)");
      $stmt->execute([ ':item_id'=>$itemId, ':wh'=>$warehouseId, ':owner_type'=>$ownerType, ':owner_id'=>$ownerId, ':heat'=>$meta['heat_no']??null, ':plate'=>$meta['plate_no']??null, ':grn'=>$meta['grn_line_id']??null, ':qty'=>$qtyBase, ':meta'=>json_encode($meta, JSON_UNESCAPED_UNICODE) ]);
      $lotId = (int)$this->pdo->lastInsertId();

      $pieceIds=[]; $pcs=max(1,(int)($meta['pcs']??1)); $shape=$meta['shape']??'rect'; $dims=$meta['dims']??[];
      $qtyPerPiece = $pcs>0 ? round($qtyBase/$pcs,6):$qtyBase;
      $ins=$this->pdo->prepare("INSERT INTO lot_pieces (lot_id, piece_code, shape, dims, qty_base, status) VALUES (:lot,:code,:shape,:dims,:qty,'free')");
      for($i=1;$i<=$pcs;$i++){
        $code = ($meta['plate_no'] ?? ('L'.$lotId)).'-'.str_pad((string)$i,2,'0',STR_PAD_LEFT);
        $ins->execute([':lot'=>$lotId, ':code'=>$code, ':shape'=>$shape, ':dims'=>json_encode($dims, JSON_UNESCAPED_UNICODE), ':qty'=>$qtyPerPiece]);
        $pieceIds[]=(int)$this->pdo->lastInsertId();
      }
      $this->pdo->commit();
      return ['lot_id'=>$lotId,'piece_ids'=>$pieceIds];
    } catch(\Throwable $e){ $this->pdo->rollBack(); throw $e; }
  }

  public function splitPiece(int $pieceId, array $remnants, float $consumedKg): array {
    $p=$this->fetchPiece($pieceId); if(!$p) throw new RuntimeException("Piece not found");
    $lotId=(int)$p['lot_id']; $parentQty=(float)$p['qty_base'];
    $totalRemnant=0.0; foreach($remnants as $r){ $totalRemnant += (float)($r['qty_base']??0); }
    $newParent = round($parentQty - $consumedKg - $totalRemnant, 6);
    if($newParent < -0.01) throw new RuntimeException("Split exceeds available qty");
    $this->pdo->beginTransaction();
    try{
      $upd=$this->pdo->prepare("UPDATE lot_pieces SET qty_base=:q, status=CASE WHEN :q<=0 THEN 'consumed' ELSE status END WHERE id=:id");
      $upd->execute([':q'=>$newParent, ':id'=>$pieceId]);
      $childIds=[]; $ins=$this->pdo->prepare("INSERT INTO lot_pieces (lot_id, piece_code, shape, dims, qty_base, status) VALUES (:lot,:code,:shape,:dims,:qty,'free')");
      $idx=1;
      foreach($remnants as $r){
        $ins->execute([':lot'=>$lotId, ':code'=>"R{$pieceId}-".str_pad((string)$idx,2,'0',STR_PAD_LEFT), ':shape'=>$r['shape']??'rect', ':dims'=>json_encode($r['dims']??[], JSON_UNESCAPED_UNICODE), ':qty'=>round((float)($r['qty_base']??0),6)]);
        $childIds[]=(int)$this->pdo->lastInsertId(); $idx++;
      }
      $this->pdo->commit();
      return ['child_piece_ids'=>$childIds,'parent_remaining'=>$newParent];
    } catch(\Throwable $e){ $this->pdo->rollBack(); throw $e; }
  }

  public function reducePieceQty(int $pieceId, float $qtyKg): array {
    $p=$this->fetchPiece($pieceId); if(!$p) throw new RuntimeException("Piece not found");
    $remain = round((float)$p['qty_base'] - $qtyKg, 6);
    if($remain < -0.01) throw new RuntimeException("Issue exceeds piece qty");
    $st=$this->pdo->prepare("UPDATE lot_pieces SET qty_base=:q, status=CASE WHEN :q<=0 THEN 'consumed' ELSE status END WHERE id=:id");
    $st->execute([':q'=>$remain, ':id'=>$pieceId]);
    return ['remaining'=>$remain];
  }

  public function markScrap(int $pieceId, float $qtyKg): array {
    $p=$this->fetchPiece($pieceId); if(!$p) throw new RuntimeException("Piece not found");
    $remain = round((float)$p['qty_base'] - $qtyKg, 6);
    if($remain < -0.01) throw new RuntimeException("Scrap exceeds piece qty");
    $st=$this->pdo->prepare("UPDATE lot_pieces SET qty_base=:q, status=CASE WHEN :q<=0 THEN 'scrap' ELSE status END WHERE id=:id");
    $st->execute([':q'=>$remain, ':id'=>$pieceId]);
    return ['remaining'=>$remain];
  }

  private function fetchPiece(int $id): ?array {
    $st=$this->pdo->prepare("SELECT * FROM lot_pieces WHERE id=:id"); $st->execute([':id'=>$id]);
    $row=$st->fetch(PDO::FETCH_ASSOC); return $row?:null;
  }
}
