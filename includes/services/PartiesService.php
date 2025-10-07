<?php
final class PartiesService {
    public function __construct(private PDO $db, private PartyCodeService $codeSvc) {}

    public function fetchAll(string $q = null, ?string $type = null, ?int $status = null): array {
        $sql = "SELECT * FROM parties WHERE 1=1";
        $args = [];
        if ($q) { $sql .= " AND (name LIKE ? OR legal_name LIKE ? OR gst_number LIKE ? OR pan_number LIKE ?)"; $args = array_merge($args, ["%$q%","%$q%","%$q%","%$q%"]); }
        if ($type) { $sql .= " AND type=?"; $args[] = $type; }
        if ($status !== null) { $sql .= " AND status=?"; $args[] = $status; }
        $sql .= " ORDER BY name";
        $stmt = $this->db->prepare($sql); $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchOne(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM parties WHERE id=?");
        $stmt->execute([$id]);
        $party = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($party) {
            $party['contacts'] = $this->getContacts($id);
            $party['banks'] = $this->getBanks($id);
            $party['commercials'] = $this->getCommercials($id);
        }
        return $party;
    }

    public function save(array $data): int {
        // Normalizations
        $data['gst_number'] = isset($data['gst_number']) ? strtoupper(trim($data['gst_number'])) : null;
        $data['pan_number'] = isset($data['pan_number']) ? strtoupper(trim($data['pan_number'])) : null;
        $data['status'] = isset($data['status']) ? (int)$data['status'] : 1;

        if (!empty($data['id'])) {
            $sql = "UPDATE parties SET code=?, name=?, legal_name=?, type=?, contact_name=?, email=?, phone=?, gst_number=?, gst_state_code=?, gst_registration_type=?, gst_status=?, gst_last_verified_at=?, gst_raw_json=?, pan_number=?, cin_number=?, msme_udyam=?, address_line1=?, address_line2=?, city=?, state=?, country=?, pincode=?, status=? WHERE id=?";
            $this->db->prepare($sql)->execute([
                $data['code'], $data['name'], $data['legal_name'], $data['type'], $data['contact_name'],
                $data['email'], $data['phone'], $data['gst_number'], $data['gst_state_code'], $data['gst_registration_type'],
                $data['gst_status'], $data['gst_last_verified_at'], $data['gst_raw_json'] ?? null,
                $data['pan_number'], $data['cin_number'], $data['msme_udyam'],
                $data['address_line1'], $data['address_line2'], $data['city'], $data['state'], $data['country'], $data['pincode'],
                $data['status'], $data['id']
            ]);
            return (int)$data['id'];
        } else {
            if (empty($data['code'])) {
                $data['code'] = $this->codeSvc->nextCode($data['type']);
            }
            $sql = "INSERT INTO parties (code, name, legal_name, type, contact_name, email, phone, gst_number, gst_state_code, gst_registration_type, gst_status, gst_last_verified_at, gst_raw_json, pan_number, cin_number, msme_udyam, address_line1, address_line2, city, state, country, pincode, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $this->db->prepare($sql)->execute([
                $data['code'], $data['name'], $data['legal_name'], $data['type'], $data['contact_name'],
                $data['email'], $data['phone'], $data['gst_number'], $data['gst_state_code'], $data['gst_registration_type'],
                $data['gst_status'], $data['gst_last_verified_at'], $data['gst_raw_json'] ?? null,
                $data['pan_number'], $data['cin_number'], $data['msme_udyam'],
                $data['address_line1'], $data['address_line2'], $data['city'], $data['state'], $data['country'], $data['pincode'],
                $data['status']
            ]);
            return (int)$this->db->lastInsertId();
        }
    }

    # ---- Contacts ----
    public function getContacts(int $partyId): array {
        $stmt = $this->db->prepare("SELECT * FROM party_contacts WHERE party_id=? ORDER BY is_primary DESC, id ASC");
        $stmt->execute([$partyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function upsertContact(int $partyId, array $c): int {
        if (!empty($c['id'])) {
            $sql = "UPDATE party_contacts SET name=?, email=?, phone=?, role_title=?, is_primary=? WHERE id=? AND party_id=?";
            $this->db->prepare($sql)->execute([$c['name'],$c['email'],$c['phone'],$c['role_title'],(int)$c['is_primary'],$c['id'],$partyId]);
            return (int)$c['id'];
        }
        $sql = "INSERT INTO party_contacts (party_id,name,email,phone,role_title,is_primary) VALUES (?,?,?,?,?,?)";
        $this->db->prepare($sql)->execute([$partyId,$c['name'],$c['email'],$c['phone'],$c['role_title'],(int)$c['is_primary']]);
        return (int)$this->db->lastInsertId();
    }
    public function deleteContact(int $partyId, int $id): void {
        $this->db->prepare("DELETE FROM party_contacts WHERE id=? AND party_id=?")->execute([$id,$partyId]);
    }

    # ---- Banks ----
    public function getBanks(int $partyId): array {
        $stmt = $this->db->prepare("SELECT * FROM party_bank_accounts WHERE party_id=? ORDER BY is_primary DESC, id ASC");
        $stmt->execute([$partyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function upsertBank(int $partyId, array $b): int {
        if (!empty($b['is_primary'])) {
            $this->db->prepare("UPDATE party_bank_accounts SET is_primary=0 WHERE party_id=?")->execute([$partyId]);
        }
        if (!empty($b['id'])) {
            $sql = "UPDATE party_bank_accounts SET beneficiary_name=?, bank_name=?, branch=?, account_number=?, ifsc=?, account_type=?, is_primary=? WHERE id=? AND party_id=?";
            $this->db->prepare($sql)->execute([$b['beneficiary_name'],$b['bank_name'],$b['branch'],$b['account_number'],$b['ifsc'],$b['account_type'],(int)$b['is_primary'],$b['id'],$partyId]);
            return (int)$b['id'];
        }
        $sql = "INSERT INTO party_bank_accounts (party_id,beneficiary_name,bank_name,branch,account_number,ifsc,account_type,is_primary) VALUES (?,?,?,?,?,?,?,?)";
        $this->db->prepare($sql)->execute([$partyId,$b['beneficiary_name'],$b['bank_name'],$b['branch'],$b['account_number'],$b['ifsc'],$b['account_type'],(int)$b['is_primary']]);
        return (int)$this->db->lastInsertId();
    }
    public function deleteBank(int $partyId, int $id): void {
        $this->db->prepare("DELETE FROM party_bank_accounts WHERE id=? AND party_id=?")->execute([$id,$partyId]);
    }

    # ---- Commercials ----
    public function getCommercials(int $partyId): array {
        $stmt = $this->db->prepare("SELECT * FROM party_commercials WHERE party_id=?");
        $stmt->execute([$partyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'party_id' => $partyId,
            'payment_terms_days' => 30,
            'credit_limit' => 0,
            'tds_section' => null,
            'tds_rate' => null,
            'tcs_applicable' => 0,
            'e_invoice_required' => 0,
            'reverse_charge_applicable' => 0,
            'default_place_of_supply' => null,
        ];
    }
    public function upsertCommercials(int $partyId, array $c): void {
        $sql = "INSERT INTO party_commercials (party_id,payment_terms_days,credit_limit,tds_section,tds_rate,tcs_applicable,e_invoice_required,reverse_charge_applicable,default_place_of_supply)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE payment_terms_days=VALUES(payment_terms_days), credit_limit=VALUES(credit_limit), tds_section=VALUES(tds_section), tds_rate=VALUES(tds_rate), tcs_applicable=VALUES(tcs_applicable), e_invoice_required=VALUES(e_invoice_required), reverse_charge_applicable=VALUES(reverse_charge_applicable), default_place_of_supply=VALUES(default_place_of_supply)";
        $this->db->prepare($sql)->execute([
            $partyId,
            (int)($c['payment_terms_days'] ?? 30),
            (float)($c['credit_limit'] ?? 0),
            $c['tds_section'] ?? null,
            $c['tds_rate'] ?? null,
            !empty($c['tcs_applicable']) ? 1 : 0,
            !empty($c['e_invoice_required']) ? 1 : 0,
            !empty($c['reverse_charge_applicable']) ? 1 : 0,
            $c['default_place_of_supply'] ?? null,
        ]);
    }
}
?>