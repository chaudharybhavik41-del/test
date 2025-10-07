<?php
final class GstLookupService {
    public function __construct(private PDO $db) {}

    public function validateGstin(string $gstin): bool {
        $gstin = strtoupper(trim($gstin));
        if (strlen($gstin) !== 15) return false;
        if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin)) return false;
        return true; // TODO: checksum if needed
    }

    /**
     * Future: call official API/provider; for now just return a dummy map.
     */
    public function fetchAndEnrich(string $gstin): array {
        if (!$this->validateGstin($gstin)) {
            throw new InvalidArgumentException('Invalid GSTIN format');
        }
        // TODO: integrate real API and map fields
        return [
            'legal_name' => null,
            'trade_name' => null,
            'gst_state_code' => substr($gstin, 0, 2),
            'gst_registration_type' => null,
            'gst_status' => null,
            'gst_last_verified_at' => date('Y-m-d H:i:s'),
            'gst_raw_json' => null,
        ];
    }
}
?>