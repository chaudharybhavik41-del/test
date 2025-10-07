<?php
declare(strict_types=1);

namespace Coupler;
use PDO;
use RuntimeException;

final class SettlementService
{
    public function __construct(private PDO $pdo) {}

    public function createHeader(int $customerId, string $mode, string $kind, string $bucket, ?string $notes): int
    {
        if ($customerId <= 0) throw new RuntimeException("customer_id required");
        $mode   = in_array($mode, ['credit_note','purchase_ap','foc'], true) ? $mode : 'credit_note';
        $kind   = in_array($kind, ['remnant','scrap','mixed'], true) ? $kind : 'remnant';
        $bucket = in_array($bucket, ['RM','SCRAP'], true) ? $bucket : 'RM';

        $st = $this->pdo->prepare(
            "INSERT INTO settlement_headers (customer_id, mode, kind, bucket, notes) VALUES (?,?,?,?,?)"
        );
        $st->execute([$customerId, $mode, $kind, $bucket, $notes]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Add a FULL piece. For partial qty, split the piece first (Phase 1.5). */
    public function addPiece(int $headerId, int $pieceId, float $rate): array
    {
        if ($headerId<=0 || $pieceId<=0 || $rate<=0) throw new RuntimeException("settlement_id, piece_id, rate required");

        $hdr = $this->getHeader($headerId, true);
        if ($hdr['status'] !== 'draft') throw new RuntimeException("Only draft settlements can be edited");

        $p   = $this->fetchPiece($pieceId);      if (!$p)  throw new RuntimeException("Piece not found");
        $lot = $this->fetchLot((int)$p['lot_id']); if (!$lot) throw new RuntimeException("Lot not found");

        if (($lot['owner_type'] ?? 'company') !== 'customer')                      throw new RuntimeException("Piece is not party-owned");
        if ((int)$lot['owner_id'] !== (int)$hdr['customer_id'])                    throw new RuntimeException("Piece belongs to a different customer");

        $qty = (float)$p['qty_base'];
        if ($qty <= 0) throw new RuntimeException("Piece has zero qty");

        $amount = round($qty * $rate, 2);

        $st = $this->pdo->prepare(
            "INSERT INTO settlement_lines (header_id, item_id, warehouse_id, lot_id, piece_id, qty_base, rate, amount, heat_no, plate_no)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            $headerId, (int)$lot['item_id'], (int)$lot['warehouse_id'], (int)$lot['id'], $pieceId,
            $qty, $rate, $amount, $lot['heat_no'] ?? null, $lot['plate_no'] ?? null
        ]);

        $st = $this->pdo->prepare("UPDATE settlement_headers SET total_qty_base=total_qty_base+?, total_amount=total_amount+? WHERE id=?");
        $st->execute([$qty, $amount, $headerId]);

        return ['qty_base'=>$qty, 'amount'=>$amount];
    }

    public function post(int $headerId): array
    {
        $hdr = $this->getHeader($headerId, true);
        if ($hdr['status'] !== 'draft') throw new RuntimeException("Settlement already posted/void");

        $lines = $this->getLines($headerId);
        if (!$lines) throw new RuntimeException("No lines to post");

        $this->pdo->beginTransaction();
        try {
            foreach ($lines as $ln) {
                $pieceId = (int)$ln['piece_id'];
                $qty     = (float)$ln['qty_base'];

                // Re-validate current ownership
                $p   = $this->fetchPiece($pieceId);
                $lot = $this->fetchLot((int)$p['lot_id']);
                if (($lot['owner_type'] ?? 'company') !== 'customer' || (int)$lot['owner_id'] !== (int)$hdr['customer_id']) {
                    throw new RuntimeException("Ownership changed for piece {$pieceId}. Refresh and retry.");
                }

                // Create new company-owned lot and move the piece there (preserve heat/plate/meta)
                $insLot = $this->pdo->prepare(
                    "INSERT INTO stock_lots (item_id, warehouse_id, owner_type, owner_id, parent_lot_id, heat_no, plate_no, received_grn_line_id, qty_base, status, meta)
                     VALUES (?,?,?,?,?,?,?,?,?,'free', (SELECT meta FROM stock_lots WHERE id=?))"
                );
                $insLot->execute([
                    (int)$lot['item_id'], (int)$lot['warehouse_id'], 'company', null, (int)$lot['id'],
                    $lot['heat_no'] ?? null, $lot['plate_no'] ?? null, $lot['received_grn_line_id'] ?? null, $qty, (int)$lot['id']
                ]);
                $newLotId = (int)$this->pdo->lastInsertId();

                // Reassign piece to new, company-owned lot
                $updPiece = $this->pdo->prepare("UPDATE lot_pieces SET lot_id=? WHERE id=?");
                $updPiece->execute([$newLotId, $pieceId]);

                // Adjust on-hand quantities: customer -> company
                // Reduce customer balance safely
                $sel = $this->pdo->prepare("SELECT qty FROM stock_onhand WHERE item_id=? AND warehouse_id=? AND owner_type='customer' AND owner_id=?");
                $sel->execute([(int)$lot['item_id'], (int)$lot['warehouse_id'], (int)$hdr['customer_id']]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $upd = $this->pdo->prepare(
                        "UPDATE stock_onhand SET qty = GREATEST(qty - ?, 0) WHERE item_id=? AND warehouse_id=? AND owner_type='customer' AND owner_id=?"
                    );
                    $upd->execute([$qty, (int)$lot['item_id'], (int)$lot['warehouse_id'], (int)$hdr['customer_id']]);
                }

                // Increase company balance
                $sel2 = $this->pdo->prepare("SELECT qty FROM stock_onhand WHERE item_id=? AND warehouse_id=? AND owner_type='company' AND owner_id IS NULL");
                $sel2->execute([(int)$lot['item_id'], (int)$lot['warehouse_id']]);
                if ($sel2->fetch(PDO::FETCH_ASSOC)) {
                    $upd2 = $this->pdo->prepare("UPDATE stock_onhand SET qty = qty + ? WHERE item_id=? AND warehouse_id=? AND owner_type='company' AND owner_id IS NULL");
                    $upd2->execute([$qty, (int)$lot['item_id'], (int)$lot['warehouse_id']]);
                } else {
                    $ins = $this->pdo->prepare("INSERT INTO stock_onhand (item_id, warehouse_id, qty, owner_type, owner_id) VALUES (?,?,?,'company',NULL)");
                    $ins->execute([(int)$lot['item_id'], (int)$lot['warehouse_id'], $qty]);
                }

                // Ownership history
                $insH = $this->pdo->prepare(
                    "INSERT INTO ownership_history (piece_id, from_owner_type, from_owner_id, to_owner_type, to_owner_id, reason, ref_table, ref_id)
                     VALUES (?,?,?,?,?,'settlement','settlement_headers',?)"
                );
                $insH->execute([$pieceId, 'customer', (int)$hdr['customer_id'], 'company', null, $headerId]);
            }

            // One aggregated GL entry per settlement
            $dr = ($hdr['bucket'] === 'SCRAP') ? '140500-Scrap Inventory' : '140100-RM Inventory';
            $amount = round((float)$hdr['total_amount'], 2);

            if ($hdr['mode'] === 'credit_note') {
                $cr = '110000-Accounts Receivable';
                $this->enqueueGL('SETTLEMENT_POSTED', [
                    'dr'=>$dr, 'cr'=>$cr, 'amount'=>$amount,
                    'refs'=>['settlement_id'=>$headerId, 'customer_id'=>(int)$hdr['customer_id']]
                ]);
                $this->enqueueAR((int)$hdr['customer_id'], 'CREDIT_NOTE', $amount, ['settlement_id'=>$headerId]);

            } elseif ($hdr['mode'] === 'purchase_ap') {
                $cr = '210000-Trade Payables';
                $this->enqueueGL('SETTLEMENT_POSTED', [
                    'dr'=>$dr, 'cr'=>$cr, 'amount'=>$amount,
                    'refs'=>['settlement_id'=>$headerId, 'customer_id'=>(int)$hdr['customer_id']]
                ]);
                // Bridge can map customer→vendor if needed
                $this->enqueueAP((int)$hdr['customer_id'], 'PURCHASE_INVOICE', $amount, ['settlement_id'=>$headerId]);

            } else { // foc
                $cr = '710900-Other Income (In-kind)';
                $this->enqueueGL('SETTLEMENT_POSTED', [
                    'dr'=>$dr, 'cr'=>$cr, 'amount'=>$amount,
                    'refs'=>['settlement_id'=>$headerId, 'customer_id'=>(int)$hdr['customer_id']]
                ]);
            }

            $st = $this->pdo->prepare("UPDATE settlement_headers SET status='posted', posted_at=NOW() WHERE id=?");
            $st->execute([$headerId]);

            $this->pdo->commit();
            return ['posted'=>true, 'amount'=>$amount];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ----------------- helpers -----------------
    private function getHeader(int $id, bool $lock=false): array
    {
        $sql = "SELECT * FROM settlement_headers WHERE id=?";
        if ($lock) $sql .= " FOR UPDATE";
        $st = $this->pdo->prepare($sql);
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Settlement not found");
        return $row;
    }

    private function getLines(int $hdrId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM settlement_lines WHERE header_id=?");
        $st->execute([$hdrId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchPiece(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM lot_pieces WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function fetchLot(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM stock_lots WHERE id=?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function enqueueGL(string $event, array $payload): void
    {
        $st = $this->pdo->prepare("INSERT INTO gl_interface_outbox (event_type, payload_json) VALUES (?,?)");
        $st->execute([$event, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    private function enqueueAR(int $customerId, string $docType, float $amount, array $payload): void
    {
        $st = $this->pdo->prepare("INSERT INTO ar_interface_outbox (customer_id, doc_type, amount, payload_json) VALUES (?,?,?,?)");
        $st->execute([$customerId, $docType, $amount, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    private function enqueueAP(int $vendorId, string $docType, float $amount, array $payload): void
    {
        $st = $this->pdo->prepare("INSERT INTO ap_interface_outbox (vendor_id, doc_type, amount, payload_json) VALUES (?,?,?,?)");
        $st->execute([$vendorId, $docType, $amount, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }
}