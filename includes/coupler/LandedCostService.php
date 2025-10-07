
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class LandedCostService
{
    public function __construct(private PDO $pdo) {}

    public function createHeader(string $method='by_weight', ?int $supplierId=null, string $currency='INR', float $fx=1.0, ?string $notes=null): int {
        $method = in_array($method, ['by_weight','by_value','by_qty','by_volume'], true) ? $method : 'by_weight';
        $st=$this->pdo->prepare("INSERT INTO lc_headers (supplier_id,currency,fx_rate,method,notes) VALUES (?,?,?,?,?)");
        $st->execute([$supplierId, $currency, $fx, $method, $notes]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addGRNLine(int $lcId, array $grnLine): int {
        $hdr = $this->getHeader($lcId);
        if ($hdr['status']!=='draft') throw new RuntimeException("Only draft LC can be edited");
        $qty = (float)($grnLine['qty_base'] ?? 0);
        $val = (float)($grnLine['value_base'] ?? 0);
        $itemId = (int)($grnLine['item_id'] ?? 0);
        $wh = (int)($grnLine['warehouse_id'] ?? 0);
        if ($qty<=0 || $itemId<=0 || $wh<=0) throw new RuntimeException("item_id, warehouse_id, qty_base required");
        $wkg = isset($grnLine['weight_kg']) ? (float)$grnLine['weight_kg'] : $qty;
        $cbm = isset($grnLine['volume_cbm']) ? (float)$grnLine['volume_cbm'] : null;

        $st=$this->pdo->prepare("INSERT INTO lc_grn_lines (header_id, grn_id, grn_line_id, item_id, warehouse_id, qty_base, value_base, weight_kg, volume_cbm, meta)
                                 VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$lcId, $grnLine['grn_id'] ?? null, $grnLine['grn_line_id'] ?? null, $itemId, $wh, $qty, $val, $wkg, $cbm, json_encode($grnLine, JSON_UNESCAPED_UNICODE)]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addCharge(int $lcId, string $code, float $amount, ?int $vendorId=null, string $currency='INR', float $fx=1.0, ?string $desc=null): int {
        $hdr = $this->getHeader($lcId);
        if ($hdr['status']!=='draft') throw new RuntimeException("Only draft LC can be edited");
        $code = $code ?: 'other';
        $st=$this->pdo->prepare("INSERT INTO lc_charges (header_id,charge_code,amount,currency,fx_rate,vendor_id,description) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$lcId,$code,$amount,$currency,$fx,$vendorId,$desc]);
        $this->pdo->prepare("UPDATE lc_headers SET total_charges=COALESCE(total_charges,0)+? WHERE id=?")->execute([$amount*$fx,$lcId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function allocate(int $lcId): array {
        $hdr = $this->getHeader($lcId, true);
        $charges = (float)$hdr['total_charges'];
        if ($charges<=0) throw new RuntimeException("No charges to allocate");
        $lines = $this->fetchLines($lcId);
        if (!$lines) throw new RuntimeException("No GRN lines linked");

        // Compute basis sum
        $basisSum = 0.0;
        foreach ($lines as $ln) {
            $basisSum += $this->basisValue($hdr['method'], $ln);
        }
        if ($basisSum <= 0) throw new RuntimeException("Allocation basis is zero");

        $allocated = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($lines as $ln) {
                $b = $this->basisValue($hdr['method'], $ln);
                $share = $charges * ($b / $basisSum);
                $perKg = $ln['qty_base'] > 0 ? $share / (float)$ln['qty_base'] : 0.0;
                $upd = $this->pdo->prepare("UPDATE lc_grn_lines SET allocated_cost=?, allocation_per_kg=? WHERE id=?");
                $upd->execute([round($share,2), round($perKg,6), (int)$ln['id']]);
                $allocated[] = ['lc_grn_line_id'=>(int)$ln['id'], 'allocated'=>round($share,2), 'per_kg'=>round($perKg,6)];
            }
            $this->pdo->prepare("UPDATE lc_headers SET status='allocated' WHERE id=?")->execute([$lcId]);
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }

        return ['allocated'=>$allocated,'total'=>round($charges,2)];
    }

    public function post(int $lcId): array {
        $hdr = $this->getHeader($lcId, true);
        if ($hdr['status']!=='allocated') throw new RuntimeException("Allocate before posting");
        $lines = $this->fetchLines($lcId);
        if (!$lines) throw new RuntimeException("No GRN lines linked");

        // Option A: write cost_adjustments per item/warehouse; optionally per-lot if meta has lot_id
        $total = 0.0;
        $this->pdo->beginTransaction();
        try {
            foreach ($lines as $ln) {
                $perKg = (float)$ln['allocation_per_kg'];
                $qty   = (float)$ln['qty_base'];
                $adj   = round($perKg * $qty, 2);
                $total += $adj;
                $lotId = null;
                $meta = json_decode((string)$ln['meta'], true);
                if (isset($meta['lot_id'])) $lotId = (int)$meta['lot_id'];

                $ins = $this->pdo->prepare("INSERT INTO cost_adjustments (lot_id,item_id,warehouse_id,basis,qty_base,adj_per_kg,total_adjustment,ref_table,ref_id)
                                            VALUES (?,?,?,?,?,?,?,?,?)");
                $ins->execute([$lotId,(int)$ln['item_id'],(int)$ln['warehouse_id'],'LC',$qty,$perKg,$adj,'lc_headers',$lcId]);
            }
            // Queue GL: Dr Inventory, Cr Accrued LC (or Freight Payable)
            $this->enqueueGL('LC_POSTED', [
                'dr'=>'140100-RM Inventory',
                'cr'=>'221000-Landed Cost Clearing',
                'amount'=>round($total,2),
                'refs'=>['lc_id'=>$lcId]
            ]);
            $this->pdo->prepare("UPDATE lc_headers SET status='posted', posted_at=NOW() WHERE id=?")->execute([$lcId]);
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        return ['posted'=>true,'amount'=>round($total,2)];
    }

    private function basisValue(string $method, array $ln): float {
        return match($method){
            'by_value'  => (float)$ln['value_base'],
            'by_qty'    => (float)$ln['qty_base'],
            'by_volume' => (float)($ln['volume_cbm'] ?? 0),
            default     => (float)($ln['weight_kg'] ?? $ln['qty_base'])
        };
    }

    private function getHeader(int $id, bool $lock=false): array {
        $sql="SELECT * FROM lc_headers WHERE id=?"; if($lock) $sql.=" FOR UPDATE";
        $st=$this->pdo->prepare($sql); $st->execute([$id]);
        $row=$st->fetch(PDO::FETCH_ASSOC); if(!$row) throw new RuntimeException("LC not found");
        return $row;
    }

    private function fetchLines(int $lcId): array {
        $st=$this->pdo->prepare("SELECT * FROM lc_grn_lines WHERE header_id=?");
        $st->execute([$lcId]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function enqueueGL(string $event, array $payload): void {
        $st=$this->pdo->prepare("INSERT INTO gl_interface_outbox (event_type,payload_json) VALUES (?,?)");
        $st->execute([$event, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}
