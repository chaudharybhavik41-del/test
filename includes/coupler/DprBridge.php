
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class DprBridge
{
    public function __construct(private PDO $pdo) {}

    /** Create DPR entries from a GRN line (call during GRN post for certain categories) */
    public function fromGrn(array $grnLine, string $itemCategory, ?int $jobId=null): ?int {
        $map = $this->getMap($itemCategory);
        if(!$map) return null; // no mapping -> skip
        $qty = (float)($grnLine['qty_base'] ?? 0);
        $unit = $map['unit'];
        $act  = $map['activity'];
        $st=$this->pdo->prepare("INSERT INTO dpr_entries (dpr_date,job_id,activity,item_id,qty,unit,source_table,source_id,notes) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([date('Y-m-d'), $jobId, $act, (int)$grnLine['item_id'], $qty, $unit, 'grn_lines', (int)$grnLine['id'], 'Auto from GRN']);
        return (int)$this->pdo->lastInsertId();
    }

    public function getMap(string $cat): ?array {
        $st=$this->pdo->prepare("SELECT * FROM dpr_activity_map WHERE item_category=?");
        $st->execute([$cat]); $r=$st->fetch(PDO::FETCH_ASSOC);
        return $r?:null;
    }
}
