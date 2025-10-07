
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class IssuePwoBridge {
  public function __construct(private PDO $pdo){}

  /** Validate picks: checks lot exists, item_id match, owner rules, and onhand >= sum */
  public function validate(int $pwoId, array $lines, bool $allowClientOwner=false): array {
    if ($pwoId<=0) throw new RuntimeException("pwoId required");
    if (!is_array($lines) || !$lines) throw new RuntimeException("lines required");
    // Load onhand by item for quick check
    $ohStmt = $this->pdo->query("SELECT item_id, SUM(qty) AS onhand FROM stock_onhand GROUP BY item_id");
    $onhand = [];
    foreach($ohStmt->fetchAll(PDO::FETCH_ASSOC) as $r){ $onhand[(int)$r['item_id']] = (float)$r['onhand']; }

    $sumByItem = [];
    $out = [];
    foreach($lines as $i=>$ln){
      $itemId = (int)($ln['item_id'] ?? 0);
      $lotId  = isset($ln['lot_id']) ? (int)$ln['lot_id'] : null;
      $qty    = (float)($ln['qty'] ?? 0);
      if($itemId<=0 || $qty<=0) { $out[]=['ok'=>false,'reason'=>'bad_item_or_qty','i'=>$i]; continue; }

      $owner = 'company'; $lotItem = $itemId; $lotQty = null;
      if($lotId){
        $st = $this->pdo->prepare("SELECT item_id, owner FROM stock_lots WHERE id=?");
        $st->execute([$lotId]);
        $lot = $st->fetch(PDO::FETCH_ASSOC);
        if(!$lot){ $out[]=['ok'=>false,'reason'=>'lot_not_found','i'=>$i,'lot_id'=>$lotId]; continue; }
        $lotItem = (int)$lot['item_id'];
        $owner   = (string)$lot['owner'];
        if($lotItem !== $itemId){ $out[]=['ok'=>false,'reason'=>'lot_item_mismatch','i'=>$i,'lot_item'=>$lotItem,'item_id'=>$itemId]; continue; }
        if($owner === 'client' && !$allowClientOwner){ $out[]=['ok'=>false,'reason'=>'client_owner_blocked','i'=>$i]; continue; }
      }

      $sumByItem[$itemId] = ($sumByItem[$itemId] ?? 0) + $qty;
      $out[]=['ok'=>true,'i'=>$i,'item_id'=>$itemId,'lot_id'=>$lotId,'qty'=>$qty,'owner'=>$owner];
    }

    // Onhand check by item (aggregate of all lines for that item)
    foreach($sumByItem as $item=>$req){
      $avail = $onhand[$item] ?? 0.0;
      if($req > $avail){
        // mark related lines as shortage
        foreach($out as &$row){
          if(($row['ok']??False) && ($row['item_id']??None) === $item){
            $row['ok'] = False; $row['reason']='insufficient_onhand'; $row['available']=$avail;
          }
        }
      }
    }

    return $out;
  }

  /** Apply: logs and updates pwo_materials.issued_qty. Does not move stock. */
  public function apply(int $pwoId, array $lines, int $userId=null, string $note=null): array {
    $val = $this->validate($pwoId, $lines, false);
    foreach($val as $r){ if(!($r['ok']??false)) throw new RuntimeException("validation_failed"); }

    // Sum issued per item
    $sum = [];
    foreach($lines as $ln){ $sum[(int)$ln['item_id']] = ($sum[(int)$ln['item_id']] ?? 0) + (float)$ln['qty']; }

    // Update pwo_materials issued_qty (bounded to req_qty)
    foreach($sum as $item=>$q){
      $st=$this->pdo->prepare("UPDATE pwo_materials SET issued_qty = LEAST(req_qty, issued_qty + ?) WHERE pwo_id=? AND component_item_id=?");
      $st->execute([(float)$q, $pwoId, $item]);
    }

    // Log
    $payload = json_encode(['pwo_id'=>$pwoId,'lines'=>$lines], JSON_UNESCAPED_SLASHES);
    $st=$this->pdo->prepare("INSERT INTO pwo_issue_logs (pwo_id, payload_json, applied, note, created_by) VALUES (?, ?, 1, ?, ?)");
    $st->execute([$pwoId, $payload, $note, $userId]);
    return ['ok'=>true,'log_id'=>(int)$this->pdo->lastInsertId()];
  }
}
