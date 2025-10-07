
<?php
declare(strict_types=1);

namespace Coupler;
use RuntimeException;

final class MeasurementEngine
{
    public function __construct(private RuleRepo $repo) {}

    /** @param array $inputs e.g. ['L_mm'=>3000,'W_mm'=>1500,'Thk_mm'=>12,'Qty'=>2,'wt_per_m'=>23.4] */
    public function compute(string $category, array $inputs): array
    {
        $rule = $this->repo->getActiveRuleSet($category, 'measurement');
        if (!$rule) throw new RuntimeException("No active measurement rule for $category");
        $expr = $rule['expression'] ?? '';
        $params = $rule['params_json'] ?? [];
        $ctx = array_merge($params, $inputs);
        $vars = Expression::evaluate($expr, $ctx);
        if (!array_key_exists('acc_qty', $vars)) {
            throw new RuntimeException("Rule for $category did not set acc_qty");
        }
        return ['acc_qty' => (float)$vars['acc_qty'], 'debug' => $vars];
    }
}
