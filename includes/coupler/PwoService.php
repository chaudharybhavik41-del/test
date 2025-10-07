
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

require_once __DIR__.'/BomService.php';
require_once __DIR__.'/RoutingService.php';

final class PwoService {
  public function __construct(private PDO $pdo){}
  private function nextNo(): string {
    $n = (int)$this->pdo->query("SELECT COALESCE(MAX(id),0)+1 AS n FROM pwo_headers")->fetch(PDO::FETCH_ASSOC)['n'];
    return 'PWO'.str_pad((string)$n, 6, '0', STR_PAD_LEFT);
  }
  public function create(int $itemId, float $qty, ?int $bomVersionId=null, ?int $routingId=null, ?string $dueDate=null): int {
    if($qty<=0) throw new RuntimeException("qty>0 required");
    $pwoNo=$this->nextNo();
    $st=$this->pdo->prepare("INSERT INTO pwo_headers (pwo_no,item_id,qty_ordered,bom_version_id,routing_id,due_date) VALUES (?,?,?,?,?,?)");
    $st->execute([$pwoNo,$itemId,$qty,$bomVersionId,$routingId,$dueDate]);
    return (int)$this->pdo->lastInsertId();
  }
  public function buildFromBom(int $pwoId): array {
    $hdr=$this->get($pwoId,true);
    $qty=(float)$hdr['qty_ordered'];
    $verId = $hdr['bom_version_id'] ? (int)$hdr['bom_version_id'] : $this->activeVersionId((int)$hdr['item_id']);
    if(!$verId) throw new RuntimeException("No active BOM version");
    $bom=new \Coupler\BomService($this->pdo);
    $flat=$bom->explode((int)$hdr['item_id'],$qty,null);
    $this->pdo->prepare("DELETE FROM pwo_materials WHERE pwo_id=?")->execute([$pwoId]);
    $ins=$this->pdo->prepare("INSERT INTO pwo_materials (pwo_id,component_item_id,req_qty) VALUES (?,?,?)");
    foreach($flat as $r){ $ins->execute([$pwoId,(int)$r['item_id'],(float)$r['qty']]); }
    if($hdr['routing_id']){
      $ops=$this->pdo->prepare("SELECT * FROM routing_operations WHERE routing_id=? ORDER BY op_seq"); $ops->execute([(int)$hdr['routing_id']]);
      $this->pdo->prepare("DELETE FROM pwo_operations WHERE pwo_id=?")->execute([$pwoId]);
      $ins2=$this->pdo->prepare("INSERT INTO pwo_operations (pwo_id,op_seq,wc_id) VALUES (?,?,?)");
      foreach($ops->fetchAll(PDO::FETCH_ASSOC) as $o){ $ins2->execute([$pwoId,(int)$o['op_seq'],(int)$o['wc_id']]); }
    }
    return ['materials'=>count($flat),'operations'=> $hdr['routing_id']? 'from routing':'none'];
  }
  public function release(int $pwoId): bool {
    $this->pdo->prepare("UPDATE pwo_headers SET status='released' WHERE id=?")->execute([$pwoId]);
    return true;
  }
  public function get(int $pwoId, bool $lock=false): array {
    $sql="SELECT * FROM pwo_headers WHERE id=?"; if($lock)$sql.=" FOR UPDATE";
    $st=$this->pdo->prepare($sql); $st->execute([$pwoId]);
    $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException("not found");
    return $r;
  }
  private function activeVersionId(int $parent): ?int {
    $st=$this->pdo->prepare("SELECT id FROM bom_versions WHERE parent_item_id=? AND is_active=1 AND (effective_from IS NULL OR effective_from<=CURDATE()) AND (effective_to IS NULL OR effective_to>=CURDATE()) ORDER BY id DESC LIMIT 1");
    $st->execute([$parent]); $r=$st->fetch(PDO::FETCH_ASSOC);
    return $r?(int)$r['id']:null;
  }
}
