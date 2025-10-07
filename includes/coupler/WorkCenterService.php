
<?php
declare(strict_types=1);
namespace Coupler;
use PDO;
final class WorkCenterService {
  public function __construct(private PDO $pdo){}
  public function upsert(?int $id, string $code, string $name, ?float $rate=null, ?string $calendar=null, bool $active=true): int {
    if($id){
      $st=$this->pdo->prepare("UPDATE work_centers SET wc_code=?, wc_name=?, cost_rate_per_hour=?, calendar_json=?, is_active=? WHERE id=?");
      $st->execute([$code,$name,$rate,$calendar,$active?1:0,$id]); return $id;
    } else {
      $st=$this->pdo->prepare("INSERT INTO work_centers (wc_code,wc_name,cost_rate_per_hour,calendar_json,is_active) VALUES (?,?,?,?,?)");
      $st->execute([$code,$name,$rate,$calendar,$active?1:0]); return (int)$this->pdo->lastInsertId();
    }
  }
  public function list(): array {
    return $this->pdo->query("SELECT * FROM work_centers ORDER BY wc_code")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
