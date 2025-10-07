
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class GrirCloser
{
    public function __construct(private PDO $pdo) {}

    public function suggest(string $olderThanDate): array {
        // Use view v_grir_aging (Phase 5). Pick rows with open_value != 0 and grn_date <= olderThanDate.
        $st=$this->pdo->prepare("SELECT * FROM v_grir_aging WHERE open_value <> 0 AND grn_date <= ? ORDER BY age_days DESC LIMIT 1000");
        $st->execute([$olderThanDate]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createHeader(?string $notes=null): int {
        $st=$this->pdo->prepare("INSERT INTO grir_closure_hdr (close_date,notes) VALUES (?,?)");
        $st->execute([date('Y-m-d'), $notes]); return (int)$this->pdo->lastInsertId();
    }

    public function addLine(int $hdrId, int $grnLineId, float $openValue, float $closeValue, string $reason='writeoff', ?string $notes=null): int {
        $hdr=$this->hdr($hdrId,true); if($hdr['status']!=='draft') throw new RuntimeException("Only draft closure editable");
        $st=$this->pdo->prepare("INSERT INTO grir_closure_lines (header_id,grn_line_id,open_value,close_value,reason,notes) VALUES (?,?,?,?,?,?)");
        $st->execute([$hdrId,$grnLineId,$openValue,$closeValue,$reason,$notes]);
        return (int)$this->pdo->lastInsertId();
    }

    public function post(int $hdrId): array {
        $hdr=$this->hdr($hdrId,true); if($hdr['status']!=='draft') throw new RuntimeException("Already posted/void");
        $lines=$this->lines($hdrId); if(!$lines) throw new RuntimeException("No lines");
        $this->pdo->beginTransaction();
        try{
            $sum=0.0;
            foreach($lines as $ln){
                $diff = (float)$ln['close_value'];
                $sum += $diff;
                // GL: clear GR/IR with write-off to variance or expense
                $this->enqueueGL('GRIR_CLOSED',[
                    'dr' => $diff>0 ? '220500-GRN-Clearing' : '710930-GRIR Write-off',
                    'cr' => $diff>0 ? '710930-GRIR Write-off' : '220500-GRN-Clearing',
                    'amount' => round(abs($diff),2),
                    'refs' => ['closure_id'=>$hdrId,'grn_line_id'=>(int)$ln['grn_line_id']]
                ]);
            }
            $this->pdo->prepare("UPDATE grir_closure_hdr SET status='posted' WHERE id=?")->execute([$hdrId]);
            $this->pdo->commit();
            return ['posted'=>true,'amount'=>round($sum,2),'lines'=>count($lines)];
        } catch(\Throwable $e){ $this->pdo->rollBack(); throw $e; }
    }

    private function hdr(int $id,bool $lock=false): array{ $sql="SELECT * FROM grir_closure_hdr WHERE id=?"; if($lock)$sql.=" FOR UPDATE"; $st=$this->pdo->prepare($sql); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException("Closure not found"); return $r; }
    private function lines(int $id): array{ $st=$this->pdo->prepare("SELECT * FROM grir_closure_lines WHERE header_id=?"); $st->execute([$id]); return $st->fetchAll(PDO::FETCH_ASSOC)?:[]; }
    private function enqueueGL(string $event, array $payload): void{ $st=$this->pdo->prepare("INSERT INTO gl_interface_outbox (event_type,payload_json) VALUES (?,?)"); $st->execute([$event,json_encode($payload,JSON_UNESCAPED_UNICODE)]); }
}
