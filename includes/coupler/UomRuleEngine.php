
<?php
declare(strict_types=1);
namespace Coupler;
use PDO; use RuntimeException;

/**
 * UomRuleEngine: computes accounting qty/rate for GRN based on item category and inputs.
 * Inputs should include: rate, pcs, weight_kg, length_m, width_m, thickness_m, area_m2, volume_m3.
 */
final class UomRuleEngine
{
    public function __construct(private PDO $pdo) {}

    public function compute(string $itemCategory, array $inputs): array {
        $rule = $this->getRuleForCategory($itemCategory);
        if (!$rule) return ['qty'=> (float)($inputs['weight_kg'] ?? $inputs['pcs'] ?? 0), 'rate'=> (float)($inputs['rate'] ?? 0), 'method'=>'fallback'];
        $method = $rule['method'];
        $params = json_decode((string)($rule['params_json'] ?? '{}'), true) ?: [];
        $rate = (float)($inputs['rate'] ?? 0);
        $qty = 0.0;

        $pcs   = (float)($inputs['pcs'] ?? 0);
        $wkg   = (float)($inputs['weight_kg'] ?? 0);
        $len   = (float)($inputs['length_m'] ?? 0);
        $wid   = (float)($inputs['width_m'] ?? 0);
        $thk   = (float)($inputs['thickness_m'] ?? 0);
        $area  = (float)($inputs['area_m2'] ?? ($len*$wid));
        $vol   = (float)($inputs['volume_m3'] ?? ($area*$thk));

        switch ($method) {
            case 'by_weight': $qty = $wkg; break;
            case 'by_pcs':    $qty = $pcs; break;
            case 'by_area':   $qty = $area; break;
            case 'by_volume': $qty = $vol; break;
            case 'custom_multiplier':
                // params: {"factors":["weight_kg","length_m","width_m"], "scale": 1.0}
                $factors = (array)($params['factors'] ?? []);
                $prod = 1.0;
                foreach ($factors as $f) {
                    $v = (float)($inputs[$f] ?? 0);
                    if ($v==0) { $prod = 0; break; }
                    $prod *= $v;
                }
                $qty = $prod * (float)($params['scale'] ?? 1.0);
                break;
            default: $qty = $wkg ?: $pcs; break;
        }
        return ['qty'=> round($qty, 6), 'rate'=> round($rate, 6), 'method'=>$method, 'rule_id'=>(int)$rule['id']];
    }

    public function getRuleForCategory(string $cat): ?array {
        $st=$this->pdo->prepare("SELECT r.* FROM item_category_rules icr JOIN uom_rules r ON r.id=icr.rule_id WHERE icr.item_category=? AND r.status='active'");
        $st->execute([$cat]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
