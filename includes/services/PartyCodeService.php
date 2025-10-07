<?php
declare(strict_types=1);

class PartyCodeService
{
    /** @var PDO */
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function prefixFor(string $type): string {
        try {
            $stmt = $this->db->prepare("SELECT code_prefix FROM party_type_meta WHERE type=?");
            $stmt->execute([$type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['code_prefix'])) return $row['code_prefix'];
        } catch (\Throwable $e) { /* fallback below */ }

        switch ($type) {
            case 'client': return 'CL';
            case 'supplier': return 'SP';
            case 'contractor': return 'CT';
            default: return 'OT';
        }
    }

    public function nextCode(string $type): string {
        $scope = "party:$type";
        $this->db->beginTransaction();
        try {
            // Ensure row exists for this scope
            $this->db->prepare(
                "INSERT INTO party_sequences (scope, current_value) VALUES (?, 0)
                 ON DUPLICATE KEY UPDATE current_value = current_value"
            )->execute([$scope]);

            // Atomically increment and fetch new value
            $this->db->prepare(
                "UPDATE party_sequences
                   SET current_value = LAST_INSERT_ID(current_value + 1)
                 WHERE scope = ?"
            )->execute([$scope]);

            $n = (int)$this->db->lastInsertId();
            $prefix = $this->prefixFor($type);
            $code = sprintf('%s-%04d', $prefix, $n);

            $this->db->commit();
            return $code;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}