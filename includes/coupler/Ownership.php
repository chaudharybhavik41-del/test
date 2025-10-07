
<?php
declare(strict_types=1);
namespace Coupler;

final class Ownership
{
    public const COMPANY   = 'company';
    public const CUSTOMER  = 'customer';
    public const VENDOR_FOC= 'vendor_foc';

    /** Validate GRN owner selection and map to doc_type. */
    public static function mapGrnOwner(array $payload): array
    {
        $mode = $payload['owner_mode'] ?? 'company'; // ui: company|customer|vendor_foc
        $out = [
            'owner_type' => $mode,
            'owner_id'   => null,
            'doc_type'   => 'PO-GRN',
            'foc_policy' => null,
        ];

        if ($mode === self::COMPANY) {
            $out['doc_type'] = 'PO-GRN';
        } elseif ($mode === self::CUSTOMER) {
            $out['doc_type'] = 'PARTY-IN';
            $out['owner_id'] = isset($payload['customer_id']) ? (int)$payload['customer_id'] : null;
            if (empty($out['owner_id'])) throw new \RuntimeException("Customer is required for PARTY-IN");
        } elseif ($mode === self::VENDOR_FOC) {
            $out['doc_type'] = 'FOC-IN';
            $pol = $payload['foc_policy'] ?? 'zero'; // zero|fair_value|standard
            if (!in_array($pol, ['zero','fair_value','standard'], true)) $pol = 'zero';
            $out['foc_policy'] = $pol;
        } else {
            throw new \RuntimeException("Invalid owner mode");
        }
        return $out;
    }
}
