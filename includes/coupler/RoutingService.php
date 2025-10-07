
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;
final class RoutingService {
  public function __construct(private PDO $pdo){}
  public function create(int $itemId, string $code, ?int $bomVersionId=null, bool $primary=true, ?string $from=null, ?string $to=null, ?string $notes=null): int {
    $st=$this->pdo->prepare("INSERT INTO item_routings (parent_item_id,routing_code,bom_version_id,is_primary,effective_from,effective_to,notes) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$itemId,$code,$bomVersionId,$primary?1:0,$from,$to,$notes]);
    return (int)$this->pdo->lastInsertId();
  }
  public function addOp(int $routingId, int $seq, string $opCode, int $wcId, float $setupMin=0, float $runMinPerUnit=0, float $overlapPct=0, ?string $notes=null): int {
    $st=$this->pdo->prepare("INSERT INTO routing_operations (routing_id,op_seq,op_code,wc_id,std_setup_min,std_run_min_per_unit,overlap_pct,notes) VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([$routingId,$seq,$opCode,$wcId,$setupMin,$runMinPerUnit,$overlapPct,$notes]);
    return (int)$this->pdo->lastInsertId();
  }
  public function getFull(int $routingId): array {
    $r=$this->pdo->prepare("SELECT * FROM item_routings WHERE id=?"); $r->execute([$routingId]); $hdr=$r->fetch(PDO::FETCH_ASSOC);
    if(!$hdr) throw new RuntimeException("routing not found");
    $ops=$this->pdo->prepare("SELECT * FROM routing_operations WHERE routing_id=? ORDER BY op_seq"); $ops->execute([$routingId]); $ops=$ops->fetchAll(PDO::FETCH_ASSOC)?:[];
    return ['routing'=>$hdr,'operations'=>$ops];
  }
}
