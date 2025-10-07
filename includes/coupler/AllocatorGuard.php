
<?php
declare(strict_types=1);
namespace Coupler;

final class AllocatorGuard
{
    /** Ensure lot owner matches the intended consumer (job/customer) policy. */
    public static function canIssue(array $lotRow, ?int $jobCustomerId, string $policy='strict'): bool
    {
        $ownerType = $lotRow['owner_type'] ?? 'company';
        $ownerId   = isset($lotRow['owner_id']) ? (int)$lotRow['owner_id'] : null;

        if ($ownerType === 'company') {
            return true; // company material can go to any job
        }
        if ($ownerType === 'customer') {
            if ($policy === 'strict') {
                return $jobCustomerId !== null && $ownerId === (int)$jobCustomerId;
            }
            // 'warn' policy: allow but flag
            return true;
        }
        if ($ownerType === 'vendor_foc') {
            // treat as company once received
            return true;
        }
        return false;
    }
}
