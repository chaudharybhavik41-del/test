<?php
declare(strict_types=1);

/**
 * Compute GST split based on org vs site/party state codes.
 * @param string $orgState  e.g. 'GJ' (state code)
 * @param string $txnState  e.g. 'GJ' for intra, other for inter
 * @param float  $taxRate   e.g. 18.0
 * @param float  $taxBase   taxable amount for the line
 * @return array [cgst, sgst, igst]
 */
function gst_split(string $orgState, string $txnState, float $taxRate, float $taxBase): array {
    $rate = max(0.0, $taxRate);
    $base = max(0.0, $taxBase);
    if ($orgState === '' || $txnState === '') return [0.0,0.0,0.0];
    if (strtoupper($orgState) === strtoupper($txnState)) {
        $half = round(($base * $rate / 100) / 2, 2);
        return [$half, $half, 0.0];
    } else {
        $igst = round($base * $rate / 100, 2);
        return [0.0, 0.0, $igst];
    }
}
