
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

final class ThreeWayMatch
{
    public function __construct(private PDO $pdo) {}

    /** Match AP invoice lines to PO/GRN with tolerances; computes PPV/QTV and marks status. */
    public function matchInvoice(int $invoiceId): array
    {
        $inv = $this->getInv($invoiceId, true);
        if ($inv['status']!=='draft' && $inv['status']!=='exception') throw new RuntimeException("Only draft/exception invoices can be matched");

        $tol = $this->getTolerance();
        $lines = $this->getLines($invoiceId);
        if (!$lines) throw new RuntimeException("No invoice lines");

        $exceptions = 0; $ok = 0; $tolCount = 0;
        foreach ($lines as $ln) {
            // Pull PO/GRN references
            $poPrice = $this->poPrice((int)$ln['po_line_id']);
            $grnQty  = $this->grnQty((int)$ln['grn_line_id']);
            $invQty  = (float)$ln['qty'];
            $invRate = (float)$ln['unit_price'];

            // Compute variances
            $ppv = $poPrice !== null ? round(($invRate - $poPrice) * $invQty, 2) : 0.0;
            $qtv = $grnQty !== null ? round(($invQty - $grnQty) * ($poPrice ?? $invRate), 2) : 0.0;

            // Decide status
            $priceVarPct = ($poPrice && $poPrice!=0) ? abs(($invRate - $poPrice)/$poPrice)*100 : 0;
            $qtyVarPct   = ($grnQty && $grnQty!=0) ? abs(($invQty - $grnQty)/$grnQty)*100 : 0;

            $status = 'ok';
            if ($priceVarPct > (float)$tol['price_pct'] || $qtyVarPct > (float)$tol['qty_pct'] || abs($ppv)+abs($qtv) > (float)$tol['amount_abs']) {
                $status = 'exception';
                $exceptions++;
            } elseif ($priceVarPct>0 || $qtyVarPct>0 || abs($ppv)+abs($qtv)>0) {
                $status = 'tolerance';
                $tolCount++;
            } else {
                $ok++;
            }

            // Update line
            $up=$this->pdo->prepare("UPDATE ap_invoice_lines SET matched_status=?, ppv=?, qtv=? WHERE id=?");
            $up->execute([$status, $ppv, $qtv, (int)$ln['id']]);
        }

        $newStatus = $exceptions>0 ? 'exception' : ($tolCount>0 ? 'matched' : 'matched');
        $this->pdo->prepare("UPDATE ap_invoices SET status=? WHERE id=?")->execute([$newStatus, $invoiceId]);
        return ['ok'=>$ok,'tolerance'=>$tolCount,'exception'=>$exceptions,'status'=>$newStatus];
    }

    /** Post AP invoice to GL: Dr GR/IR (clearing) up to GRN, PPV to variance, balance to AP. */
    public function postInvoice(int $invoiceId): array
    {
        $inv = $this->getInv($invoiceId, true);
        if ($inv['status']!=='matched') throw new RuntimeException("Invoice must be matched before posting");

        $lines = $this->getLines($invoiceId);
        $sum = 0.0; $ppv = 0.0; $qtv = 0.0;
        foreach ($lines as $ln) {
            $sum += (float)$ln['amount'];
            $ppv += (float)$ln['ppv'];
            $qtv += (float)$ln['qtv'];
        }
        // GL enqueue
        $this->enqueueGL('AP_INVOICE_POSTED', [
            'dr' => '220500-GRN-Clearing',   // GR/IR
            'cr' => '210000-Trade Payables',
            'amount' => round($sum,2),
            'refs' => ['invoice_id'=>$invoiceId]
        ]);
        if (abs($ppv) > 0.005) {
            $this->enqueueGL('PPV_POSTED', [
                'dr' => $ppv>0 ? '140100-RM Inventory' : '720500-Purchase Price Variance',
                'cr' => $ppv>0 ? '720500-Purchase Price Variance' : '140100-RM Inventory',
                'amount' => round(abs($ppv),2),
                'refs' => ['invoice_id'=>$invoiceId]
            ]);
        }
        if (abs($qtv) > 0.005) {
            $this->enqueueGL('QTV_POSTED', [
                'dr' => $qtv>0 ? '220500-GRN-Clearing' : '710920-GRN Quantity Variance',
                'cr' => $qtv>0 ? '710920-GRN Quantity Variance' : '220500-GRN-Clearing',
                'amount' => round(abs($qtv),2),
                'refs' => ['invoice_id'=>$invoiceId]
            ]);
        }
        $this->pdo->prepare("UPDATE ap_invoices SET status='posted', posted_at=NOW() WHERE id=?")->execute([$invoiceId]);
        return ['posted'=>true,'amount'=>round($sum,2),'ppv'=>round($ppv,2),'qtv'=>round($qtv,2)];
    }

    // ---------------- helpers ----------------
    private function getInv(int $id, bool $lock=false): array {
        $sql="SELECT * FROM ap_invoices WHERE id=?"; if($lock) $sql.=" FOR UPDATE";
        $st=$this->pdo->prepare($sql); $st->execute([$id]);
        $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException("Invoice not found"); return $r;
    }
    private function getLines(int $invId): array {
        $st=$this->pdo->prepare("SELECT * FROM ap_invoice_lines WHERE invoice_id=?");
        $st->execute([$invId]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    private function getTolerance(): array {
        $st=$this->pdo->query("SELECT * FROM threeway_tolerances ORDER BY id DESC LIMIT 1");
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['price_pct'=>1.0,'qty_pct'=>1.0,'amount_abs'=>0.0];
    }
    private function poPrice(?int $poLineId): ?float {
        if(!$poLineId) return null;
        $st=$this->pdo->prepare("SELECT unit_price FROM po_lines WHERE id=?"); // adapt to your schema
        if(!$st->execute([$poLineId])) return null;
        $r=$st->fetch(PDO::FETCH_ASSOC);
        return $r ? (float)$r['unit_price'] : null;
    }
    private function grnQty(?int $grnLineId): ?float {
        if(!$grnLineId) return null;
        $st=$this->pdo->prepare("SELECT qty_base FROM grn_lines WHERE id=?"); // adapt if different
        if(!$st->execute([$grnLineId])) return null;
        $r=$st->fetch(PDO::FETCH_ASSOC);
        return $r ? (float)$r['qty_base'] : null;
    }
    private function enqueueGL(string $event, array $payload): void {
        $st=$this->pdo->prepare("INSERT INTO gl_interface_outbox (event_type,payload_json) VALUES (?,?)");
        $st->execute([$event, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}
