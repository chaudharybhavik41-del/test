
<?php
declare(strict_types=1);
namespace Coupler;
use PDO;

final class CouplerGL
{
    public function __construct(private PDO $pdo, private RuleRepo $repo) {}

    /** Queue GL interface lines into outbox based on category & context */
    public function queueGLEntries(string $category, array $ctx): void
    {
        // $ctx: doc_type, owner_type, acct_qty, acct_rate, refs..., policy?, skip?
        $map = $this->repo->getGLMap($category, [
            'doc_type'   => $ctx['doc_type']   ?? 'PO-GRN',
            'owner_type' => $ctx['owner_type'] ?? 'company',
            'policy'     => $ctx['foc_policy'] ?? null
        ]);
        if (!$map) return;
        if (!empty($map['skip'])) return;

        $rate = isset($ctx['gl_rate']) ? (float)$ctx['gl_rate'] : (float)($ctx['acct_rate'] ?? 0);
        $amount = round((float)($ctx['acct_qty'] ?? 0) * $rate, 2);

        $payload = [
            'dr' => $map['dr'] ?? '140100-RM',
            'cr' => $map['cr'] ?? '220500-GRN-Clearing',
            'amount' => $amount,
            'refs' => $ctx['refs'] ?? [],
            'doc_type' => $ctx['doc_type'] ?? '',
            'owner_type' => $ctx['owner_type'] ?? ''
        ];

        $sql = "INSERT INTO gl_interface_outbox (event_type, payload_json) VALUES (:t, :p)";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':t' => 'GRN_POSTED',
            ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]);
    }
}
