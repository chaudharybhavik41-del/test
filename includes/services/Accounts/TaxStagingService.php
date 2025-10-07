<?php
/** PATH: /public_html/includes/services/Accounts/TaxStagingService.php
 * GST/TDS staging helper used by PostingService::postAPBill() and anywhere else.
 */
declare(strict_types=1);

namespace Accounts;

use PDO;

require_once __DIR__ . '/../../db.php';

final class TaxStagingService
{
    /**
     * Upsert a tax transaction row for GST/TDS reporting.
     * Required: doc_type (AP_BILL|AR_INV|AP_CDN|AR_CDN|CASH_EXP), doc_id, doc_date
     * Optional: line_id, party_id, gstin, hsn, pos_state, supply_type (intra|inter),
     *           taxable_value, cgst, sgst, igst, gst_rate, quantity, uom_id,
     *           is_itc_eligible (1|0), itc_bucket ('Available'|'Ineligible'|'Deferred'), section_tds
     */
    public static function stage(array $data): void
    {
        $pdo = \db();
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

        foreach (['doc_type','doc_id','doc_date'] as $k) {
            if (!isset($data[$k])) throw new \InvalidArgumentException("Missing $k");
        }

        // If exists => update; else insert
        $sel = $pdo->prepare("SELECT id FROM tax_transactions WHERE doc_type=? AND doc_id=? AND (line_id <=> ?)");
        $sel->execute([$data['doc_type'], (int)$data['doc_id'], $data['line_id'] ?? null]);
        $id = $sel->fetchColumn();

        $fields = [
            'party_id','gstin','hsn','pos_state','supply_type','taxable_value','cgst','sgst','igst',
            'gst_rate','quantity','uom_id','doc_date','is_itc_eligible','itc_bucket','section_tds'
        ];

        if ($id) {
            $sets = implode(',', array_map(fn($f)=>"$f=:$f", $fields));
            $sql  = "UPDATE tax_transactions SET $sets WHERE id=:id";
            $st   = $pdo->prepare($sql);
            foreach ($fields as $f) $st->bindValue(":$f", $data[$f] ?? null);
            $st->bindValue(':id', (int)$id, PDO::PARAM_INT);
            $st->execute();
        } else {
            $cols = 'doc_type,doc_id,line_id,' . implode(',', $fields);
            $vals = ':doc_type,:doc_id,:line_id,' . implode(',', array_map(fn($f)=>":$f", $fields));
            $sql  = "INSERT INTO tax_transactions ($cols) VALUES ($vals)";
            $st   = $pdo->prepare($sql);
            $st->bindValue(':doc_type', $data['doc_type']);
            $st->bindValue(':doc_id',   (int)$data['doc_id'], PDO::PARAM_INT);
            $st->bindValue(':line_id',  $data['line_id'] ?? null);
            foreach ($fields as $f) $st->bindValue(":$f", $data[$f] ?? null);
            $st->execute();
        }
    }
}
