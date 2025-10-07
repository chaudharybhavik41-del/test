<?php
/** PATH: /public_html/includes/services/MetricService.php
 * Adds per-operation variables (routing_op_vars) for count-based operations like drilling.
 * These vars are merged with the standard BOM dimension variables.
 */
declare(strict_types=1);

final class MetricService
{
    /** Standard variables from bom_components */
    public static function varsForBomComponent(array $bc): array
    {
        $Lmm = (float)($bc['length_mm'] ?? 0);
        $Wmm = (float)($bc['width_mm'] ?? 0);
        $Tmm = (float)($bc['thickness_mm'] ?? 0);
        $Dmm = (float)($bc['diameter_mm'] ?? 0);
        $Hmm = (float)($bc['height_mm'] ?? 0);
        $Qty = (float)($bc['qty'] ?? 1);
        $density_kg_m3 = isset($bc['material_density_kg_m3']) ? (float)$bc['material_density_kg_m3'] : ((float)($bc['density_gcc'] ?? 7.85) * 1000.0);

        $vars = [
            // mm inputs
            'L_mm' => $Lmm,
            'W_mm' => $Wmm,
            'T_mm' => $Tmm,
            'D_mm' => $Dmm,
            'H_mm' => $Hmm,
            'qty'  => $Qty,
            'density_kg_m3' => $density_kg_m3,

            // meters convenience
            'L' => $Lmm / 1000.0,
            'W' => $Wmm / 1000.0,
            'T' => $Tmm / 1000.0,
            'D' => $Dmm / 1000.0,
            'H' => $Hmm / 1000.0,

            // common synonyms
            'Thk' => $Tmm / 1000.0,
            'THK' => $Tmm / 1000.0,
            't'   => $Tmm / 1000.0,
            'thk' => $Tmm / 1000.0,
            'THK_mm' => $Tmm,
            'thk_mm' => $Tmm,

            // areas (rectangular assumptions)
            'area_top_m2' => ($Lmm * $Wmm) / 1e6,
            'area_side_m2' => ($Lmm * $Tmm) / 1e6,
            'area_front_m2' => ($Wmm * $Tmm) / 1e6,
        ];

        // surface area approximations (plate/cuboid)
        $vars['area_rect_m2'] = 2.0 * ($vars['area_top_m2'] + $vars['area_side_m2'] + $vars['area_front_m2']);

        // cylindrical helpers
        $pi = 3.141592653589793;
        $radius_m = ($Dmm / 1000.0) / 2.0;
        $vars['circumference_m'] = 2.0 * $pi * $radius_m;
        $vars['cyl_area_m2'] = 2.0 * $pi * $radius_m * ($Lmm/1000.0); // side area only

        // volume estimates
        $vars['vol_rect_m3'] = ($Lmm/1000.0) * ($Wmm/1000.0) * ($Tmm/1000.0);
        $vars['vol_cyl_m3']  = $pi * $radius_m * $radius_m * ($Lmm/1000.0);

        // weight estimates
        $vars['weight_rect_kg'] = $vars['vol_rect_m3'] * $density_kg_m3 * $Qty;
        $vars['weight_cyl_kg']  = $vars['vol_cyl_m3']  * $density_kg_m3 * $Qty;

        return $vars;
    }

    /** Merge in variables defined per routing operation */
    public static function mergeOpVars(PDO $pdo, int $routing_op_id, array $vars): array
    {
        try {
            $st = $pdo->prepare("SELECT name, value FROM routing_op_vars WHERE routing_op_id=:id");
            $st->execute([':id'=>$routing_op_id]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $name = (string)$row['name'];
                if ($name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                    $vars[$name] = (float)$row['value'];
                }
            }
        } catch (Throwable $e) {
            // table might not exist yet; ignore
        }
        return $vars;
    }

    /** Fetch UOM id by code (e.g., 'm','m2','kg','nos','ea') */
    public static function uomIdByCode(PDO $pdo, string $code): ?int
    {
        static $cache = [];
        if (isset($cache[$code])) return $cache[$code];
        $st = $pdo->prepare("SELECT id FROM uom WHERE code=:c");
        $st->execute([':c'=>$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $cache[$code] = (int)$row['id'];
        return $cache[$code];
    }

    /** Safely evaluate a math expression with variables */
    public static function evalExpr(string $expr, array $vars): float
    {
        $expr_sub = preg_replace_callback('/\b([A-Za-z_][A-Za-z0-9_]*)\b/', function($m) use ($vars) {
            $k = $m[1];
            if (array_key_exists($k, $vars)) {
                $v = (float)$vars[$k];
                return rtrim(rtrim(number_format($v, 12, '.', ''), '0'), '.');
            }
            $allowed = ['abs','round','ceil','floor','min','max','pow','pi'];
            if (in_array($k, $allowed, true)) return $k;
            throw new RuntimeException("Unknown identifier in formula: {$k}");
        }, $expr);

        if (preg_match('/[^0-9\.\,\+\-\*\/\%\(\)\sA-Za-z_]/', $expr_sub)) {
            throw new RuntimeException("Invalid characters in formula.");
        }
        foreach (['//','/*','*/','--','`','"',"'","$","=",";"] as $bad) {
            if (strpos($expr_sub, $bad) !== false) throw new RuntimeException("Unsafe token detected in formula.");
        }
        $result = 0.0;
        try {
            if (strpos($expr_sub, 'pi') !== false && !defined('M_PI')) { $pi = 3.141592653589793; }
            $result = eval('return ('.$expr_sub.');');
        } catch (Throwable $e) {
            throw new RuntimeException("Error evaluating formula.");
        }
        return (float)$result;
    }

    /** Compute quantity for a routing op using its attached process_qty_rule (with merged op vars) */
    public static function computeForRoutingOp(PDO $pdo, int $routing_op_id): ?array
    {
        $sql = "SELECT ro.id, ro.process_id, ro.bom_component_id, ro.process_qty_rule_id,
                       bc.*,
                       rqr.expr, rqr.result_uom_id
                  FROM routing_ops ro
            INNER JOIN bom_components bc ON bc.id = ro.bom_component_id
             LEFT JOIN process_qty_rules rqr ON rqr.id = ro.process_qty_rule_id
                 WHERE ro.id = :id";
        $st = $pdo->prepare($sql);
        $st->execute([':id'=>$routing_op_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!$row['expr']) return null;

        $vars = self::varsForBomComponent($row);
        $vars = self::mergeOpVars($pdo, (int)$row['id'], $vars);

        $qty = self::evalExpr((string)$row['expr'], $vars);
        $uom_id = (int)$row['result_uom_id'] ?: null;
        return ['qty' => $qty, 'uom_id' => $uom_id];
    }
}
