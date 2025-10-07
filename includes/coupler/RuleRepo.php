
<?php
declare(strict_types=1);

namespace Coupler;
use PDO;

final class RuleRepo
{
    public function __construct(private PDO $pdo) {}

    /** Return one active rule row (as array) for a category+type */
    public function getActiveRuleSet(string $category, string $type): ?array
    {
        $sql = "SELECT cr.*
                FROM coupler_rule_sets rs
                JOIN coupler_rules cr ON cr.rule_set_id = rs.id AND cr.type = :type AND cr.is_active = 1
                WHERE rs.category = :cat AND rs.status='active'
                ORDER BY rs.version_no DESC, cr.id DESC LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':cat'=>$category, ':type'=>$type]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!empty($row['params_json']) && is_string($row['params_json'])) {
            $row['params_json'] = json_decode($row['params_json'], true) ?: [];
        }
        return $row;
    }

    public function getGLMap(string $category, array $match): ?array
    {
        $sql = "SELECT cr.params_json
                FROM coupler_rule_sets rs
                JOIN coupler_rules cr ON cr.rule_set_id = rs.id AND cr.type='gl_map' AND cr.is_active=1
                WHERE rs.category = :cat AND rs.status='active'
                ORDER BY rs.version_no DESC, cr.id DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':cat'=>$category]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $params = json_decode($row['params_json'] ?? "{}", true) ?: [];
            $ok = true;
            foreach ($match as $k=>$v) {
                if (!array_key_exists($k, $params) || strval($params[$k]) !== strval($v)) { $ok=false; break; }
            }
            if ($ok) return $params;
        }
        return null;
    }
}
