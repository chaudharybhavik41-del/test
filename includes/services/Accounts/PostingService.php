<?php
/** PATH: /public_html/includes/services/Accounts/PostingService.php
 * PHP 8.4 | Bootstrap 5 UI elsewhere | Kernel untouched
 * ------------------------------------------------------
 * What this provides:
 * - createVoucher($hdr, $lines): Generic balanced journal posting to GL.
 * - postAPBill($bill, $lines): Convenience posting for AP Bills (DR Inventory/Expense + DR Input GST + CR Vendor) + GST staging.
 * - postStoreIssue($issue, $issueLines): Convenience posting for Store Issues (DR WIP/Project + CR Inventory).
 *
 * COA codes used (seeded in SQL you ran):
 *   1010 Cash-in-hand
 *   1310 Inventory - Raw Material
 *   1410 WIP - Projects
 *   2010 Accounts Payable - Trade
 *   2210 GST Input CGST
 *   2220 GST Input SGST
 *   2230 GST Input IGST
 *   5010 COGS / Material Consumption
 */

declare(strict_types=1);

namespace Accounts;

use PDO;

require_once __DIR__ . '/../../db.php';              // uses global db(): PDO
// audit_log is optional; only called if function exists.
if (is_file(__DIR__ . '/../../audit.php')) { require_once __DIR__ . '/../../audit.php'; }

// If GST staging helper is present, we'll use it inside postAPBill()
$__taxSvcPath = __DIR__ . '/TaxStagingService.php';
if (is_file($__taxSvcPath)) require_once $__taxSvcPath;

class PostingException extends \RuntimeException {}

final class PostingService
{
    /** Allocate next voucher no as TYPE-YYYY-SEQ4 */
    private static function nextVoucherNo(PDO $pdo, string $voucherType, \DateTimeInterface $date): string
    {
        $yr = (int)$date->format('Y');
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT seq FROM account_sequences WHERE seq_year=? AND voucher_type=? FOR UPDATE");
            $sel->execute([$yr, $voucherType]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $seq = (int)$row['seq'] + 1;
                $upd = $pdo->prepare("UPDATE account_sequences SET seq=? WHERE seq_year=? AND voucher_type=?");
                $upd->execute([$seq, $yr, $voucherType]);
            } else {
                $seq = 1;
                $ins = $pdo->prepare("INSERT INTO account_sequences (seq_year, voucher_type, seq) VALUES (?,?,?)");
                $ins->execute([$yr, $voucherType, $seq]);
            }
            $pdo->commit();
            return sprintf('%s-%04d-%04d', $voucherType, $yr, $seq);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new PostingException('Sequence allocation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Find account id by id or code; throws if not found */
    private static function resolveAccountId(PDO $pdo, array $line): int
    {
        if (!empty($line['account_id'])) {
            return (int)$line['account_id'];
        }
        if (!empty($line['account_code'])) {
            $q = $pdo->prepare("SELECT id FROM accounts_chart WHERE code=? AND active=1");
            $q->execute([$line['account_code']]);
            $id = $q->fetchColumn();
            if ($id) return (int)$id;
            throw new PostingException('Unknown account_code: ' . $line['account_code']);
        }
        throw new PostingException('Each line must include account_id or account_code');
    }

    /** Ensure DR=CR and both > 0 */
    private static function validateBalanced(array $lines): array
    {
        $dr = 0.0; $cr = 0.0;
        foreach ($lines as $ln) {
            $dr += (float)($ln['debit'] ?? 0);
            $cr += (float)($ln['credit'] ?? 0);
        }
        $dr = round($dr, 2); $cr = round($cr, 2);
        if ($dr <= 0 || $cr <= 0 || abs($dr - $cr) > 0.009) {
            throw new PostingException("Unbalanced entry. DR={$dr} CR={$cr}");
        }
        return [$dr, $cr];
    }

    /**
     * Public API: Generic voucher post
     * $hdr keys: voucher_type (JV|CPV|CRV|CNV|APB|ARV), voucher_date (Y-m-d), ref_doc_type, ref_doc_id, narration, posted_by
     * $lines[]:  account_code|account_id, debit, credit, cost_center_id?, project_id?, party_id?, line_memo?
     * Returns journal id (int)
     */
    public static function createVoucher(array $hdr, array $lines): int
    {
        $pdo = \db();
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

        $vt = strtoupper(trim((string)($hdr['voucher_type'] ?? '')));
        if (!in_array($vt, ['JV','CPV','CRV','CNV','APB','ARV'], true)) {
            throw new PostingException('Invalid voucher_type');
        }
        $vdStr = (string)($hdr['voucher_date'] ?? date('Y-m-d'));
        $vd = new \DateTimeImmutable($vdStr);

        if (empty($lines)) throw new PostingException('At least one line is required');

        self::validateBalanced($lines);
        $voucherNo = self::nextVoucherNo($pdo, $vt, $vd);

        $pdo->beginTransaction();
        try {
            $insH = $pdo->prepare(
                "INSERT INTO journals (voucher_no,voucher_type,voucher_date,ref_doc_type,ref_doc_id,narration,posted_by,status)
                 VALUES (?,?,?,?,?,?,?, 'posted')"
            );
            $insH->execute([
                $voucherNo,
                $vt,
                $vd->format('Y-m-d'),
                $hdr['ref_doc_type'] ?? null,
                $hdr['ref_doc_id']   ?? null,
                $hdr['narration']    ?? null,
                $hdr['posted_by']    ?? null
            ]);
            $jid = (int)$pdo->lastInsertId();

            $insL = $pdo->prepare(
                "INSERT INTO journal_lines (journal_id,line_no,account_id,debit,credit,cost_center_id,project_id,party_id,line_memo)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );

            $lnNo = 1;
            foreach ($lines as $ln) {
                $accId = self::resolveAccountId($pdo, $ln);
                $insL->execute([
                    $jid,
                    $lnNo++,
                    $accId,
                    round((float)($ln['debit'] ?? 0), 2),
                    round((float)($ln['credit'] ?? 0), 2),
                    $ln['cost_center_id'] ?? null,
                    $ln['project_id']     ?? null,
                    $ln['party_id']       ?? null,
                    $ln['line_memo']      ?? null
                ]);
            }

            if (function_exists('audit_log')) {
                @audit_log((int)($hdr['posted_by'] ?? 0), 'journals', $jid, 'created', null, json_encode(['voucher_no' => $voucherNo], JSON_UNESCAPED_SLASHES));
            }

            $pdo->commit();
            return $jid;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new PostingException('Voucher post failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Helper: simple two-line JV */
    public static function createSimpleJV(string $dateYmd, string $drAccountCode, float $amount, string $crAccountCode, array $meta = []): int
    {
        $hdr = [
            'voucher_type' => 'JV',
            'voucher_date' => $dateYmd,
            'ref_doc_type' => $meta['ref_doc_type'] ?? null,
            'ref_doc_id'   => $meta['ref_doc_id']   ?? null,
            'narration'    => $meta['narration']    ?? null,
            'posted_by'    => (int)($meta['posted_by'] ?? 0),
        ];
        $lines = [
            ['account_code' => $drAccountCode, 'debit' => round($amount,2), 'credit' => 0.00, 'project_id' => $meta['project_id'] ?? null, 'party_id' => $meta['party_id'] ?? null],
            ['account_code' => $crAccountCode, 'debit' => 0.00, 'credit' => round($amount,2), 'project_id' => $meta['project_id'] ?? null, 'party_id' => $meta['party_id'] ?? null],
        ];
        return self::createVoucher($hdr, $lines);
    }

    /**
     * Convenience: Post an AP Bill
     * $bill: ['id','bill_no','bill_date','vendor_party_id','vendor_gstin?','pos_state?','project_id?','posted_by?']
     * $lines[]: ['id','taxable_value','cgst','sgst','igst','gst_rate','hsn?','qty?','uom_id?','is_service?']
     * Chooses 1310 (Inventory) for material; 5010 (COGS/Expense) if is_service flag is true.
     * Returns journal id.
     */
    public static function postAPBill(array $bill, array $lines): int
    {
        $hdr = [
            'voucher_type' => 'APB',
            'voucher_date' => (string)($bill['bill_date'] ?? date('Y-m-d')),
            'ref_doc_type' => 'AP_BILL',
            'ref_doc_id'   => (int)$bill['id'],
            'narration'    => 'AP Bill #' . ($bill['bill_no'] ?? (string)$bill['id']),
            'posted_by'    => (int)($bill['posted_by'] ?? 0),
        ];

        $sumTaxableGoods = 0.0;
        $sumTaxableSvc   = 0.0;
        $sumCGST = 0.0; $sumSGST = 0.0; $sumIGST = 0.0;

        foreach ($lines as $ln) {
            $taxable = (float)($ln['taxable_value'] ?? 0);
            $sumCGST += (float)($ln['cgst'] ?? 0);
            $sumSGST += (float)($ln['sgst'] ?? 0);
            $sumIGST += (float)($ln['igst'] ?? 0);

            if (!empty($ln['is_service'])) $sumTaxableSvc   += $taxable;
            else                            $sumTaxableGoods += $taxable;

            // Stage GST if TaxStagingService exists
            if (class_exists(__NAMESPACE__ . '\\TaxStagingService')) {
                TaxStagingService::stage([
                    'doc_type'      => 'AP_BILL',
                    'doc_id'        => (int)$bill['id'],
                    'line_id'       => $ln['id'] ?? null,
                    'party_id'      => $bill['vendor_party_id'] ?? null,
                    'gstin'         => $bill['vendor_gstin']   ?? null,
                    'hsn'           => $ln['hsn'] ?? null,
                    'pos_state'     => $bill['pos_state'] ?? null,
                    'supply_type'   => ((float)($ln['igst'] ?? 0) > 0) ? 'inter' : 'intra',
                    'taxable_value' => $taxable,
                    'cgst'          => (float)($ln['cgst'] ?? 0),
                    'sgst'          => (float)($ln['sgst'] ?? 0),
                    'igst'          => (float)($ln['igst'] ?? 0),
                    'gst_rate'      => (float)($ln['gst_rate'] ?? 0),
                    'quantity'      => $ln['qty'] ?? null,
                    'uom_id'        => $ln['uom_id'] ?? null,
                    'doc_date'      => (string)($bill['bill_date'] ?? date('Y-m-d')),
                    'is_itc_eligible'=> 1,
                    'itc_bucket'    => 'Available'
                ]);
            }
        }

        $jl = [];
        if ($sumTaxableGoods > 0) {
            $jl[] = ['account_code'=>'1310','debit'=>round($sumTaxableGoods,2),'credit'=>0.00,'project_id'=>$bill['project_id'] ?? null,'party_id'=>$bill['vendor_party_id'] ?? null,'line_memo'=>'AP Bill taxable (materials)'];
        }
        if ($sumTaxableSvc > 0) {
            $jl[] = ['account_code'=>'5010','debit'=>round($sumTaxableSvc,2),'credit'=>0.00,'project_id'=>$bill['project_id'] ?? null,'party_id'=>$bill['vendor_party_id'] ?? null,'line_memo'=>'AP Bill taxable (services)'];
        }
        if ($sumCGST > 0) $jl[] = ['account_code'=>'2210','debit'=>round($sumCGST,2),'credit'=>0.00,'party_id'=>$bill['vendor_party_id'] ?? null,'line_memo'=>'Input CGST'];
        if ($sumSGST > 0) $jl[] = ['account_code'=>'2220','debit'=>round($sumSGST,2),'credit'=>0.00,'party_id'=>$bill['vendor_party_id'] ?? null,'line_memo'=>'Input SGST'];
        if ($sumIGST > 0) $jl[] = ['account_code'=>'2230','debit'=>round($sumIGST,2),'credit'=>0.00,'party_id'=>$bill['vendor_party_id'] ?? null,'line_memo'=>'Input IGST'];

        $total = 0.0;
        foreach ($jl as $l) $total += (float)$l['debit'];
        $jl[] = ['account_code'=>'2010','debit'=>0.00,'credit'=>round($total,2),'party_id'=>$bill['vendor_party_id'] ?? null,'line_memo'=>'AP Bill total payable'];

        return self::createVoucher($hdr, $jl);
    }

    /**
     * Convenience: Post Store Issue
     * $issue: ['id','issue_no','issue_date','project_id?','posted_by?']
     * $issueLines[]: ['value_amount' => numeric] (already valued by Stores using your valuation method)
     * Uses: DR 1410 WIP - Projects, CR 1310 Inventory - Raw Material
     */
    public static function postStoreIssue(array $issue, array $issueLines): int
    {
        $sum = 0.0;
        foreach ($issueLines as $ln) {
            $sum += (float)($ln['value_amount'] ?? 0);
        }
        $sum = round($sum, 2);
        if ($sum <= 0) throw new PostingException('Store Issue value is zero; nothing to post');

        $hdr = [
            'voucher_type' => 'JV',
            'voucher_date' => (string)($issue['issue_date'] ?? date('Y-m-d')),
            'ref_doc_type' => 'STORE_ISSUE',
            'ref_doc_id'   => (int)$issue['id'],
            'narration'    => 'Material Issue #' . ($issue['issue_no'] ?? (string)$issue['id']),
            'posted_by'    => (int)($issue['posted_by'] ?? 0),
        ];

        $lines = [
            ['account_code'=>'1410','debit'=>$sum,'credit'=>0.00,'project_id'=>$issue['project_id'] ?? null,'line_memo'=>'Issue to project'],
            ['account_code'=>'1310','debit'=>0.00,'credit'=>$sum,'project_id'=>$issue['project_id'] ?? null,'line_memo'=>'Inventory out'],
        ];

        return self::createVoucher($hdr, $lines);
    }
}
