<?php
/** PATH: /public_html/includes/services/WorkOrderService.php
 * Uses MetricService to auto-compute planned qty from process_qty_rule if linked.
 */
declare(strict_types=1);

if (!function_exists('db')) {
  require_once __DIR__ . '/../db.php';
}
require_once __DIR__ . '/MetricService.php';

final class WorkOrderService
{
    public static function ensurePwoForRouting(PDO $pdo, int $routing_op_id, array $overrides = []): array
    {
        $pdo->beginTransaction();
        try {
            $ro = self::fetchRoutingOp($pdo, $routing_op_id);
            if (!$ro) throw new RuntimeException("Routing op not found: {$routing_op_id}");

            $existing = self::findExistingOpenPwo($pdo, $routing_op_id);
            if ($existing) { $pdo->commit(); return ['pwo_id'=>(int)$existing['id'],'created'=>false]; }

            $proc = self::fetchProcess($pdo, (int)$ro['process_id']);

            $assign_type  = $overrides['assign_type']  ?? ($ro['default_assign_type'] ?? 'company');
            $contractor_id= ($assign_type === 'contractor')
                ? (int)($overrides['contractor_id'] ?? ($ro['default_contractor_id'] ?? 0)) ?: null
                : null;

            $work_center_id = (int)($overrides['work_center_id'] ?? ($ro['work_center_id'] ?? 0)) ?: null;

            // --- derive planned qty via rule if available ---
            $computed = MetricService::computeForRoutingOp($pdo, $routing_op_id);
            $planned_prod_qty = isset($overrides['planned_prod_qty']) ? (float)$overrides['planned_prod_qty']
                                 : ($computed ? (float)$computed['qty'] : (isset($ro['default_plan_prod_qty']) ? (float)$ro['default_plan_prod_qty'] : 0));

            $planned_comm_qty = isset($overrides['planned_comm_qty']) ? (float)$overrides['planned_comm_qty']
                                 : (isset($ro['default_plan_comm_qty']) ? (float)$ro['default_plan_comm_qty'] : null);

            // UOMs: prefer rule->result_uom, else process defaults
            $prod_uom_id = (int)($overrides['prod_uom_id'] ?? ($computed['uom_id'] ?? ($proc['prod_uom_id'] ?? 0))) ?: null;
            $comm_uom_id = (int)($overrides['comm_uom_id'] ?? ($proc['output_uom_id'] ?? 0)) ?: null;

            $plan_start_date = $overrides['plan_start_date'] ?? null;
            $plan_end_date   = $overrides['plan_end_date']   ?? null;

            // legacy columns
            $planned_qty = $planned_prod_qty;
            $uom_id      = $prod_uom_id;

            $sql = "INSERT INTO process_work_orders
                       (routing_op_id, bom_component_id, process_id, work_center_id,
                        planned_qty, planned_prod_qty, uom_id, prod_uom_id,
                        planned_comm_qty, comm_uom_id,
                        plan_start_date, plan_end_date,
                        assign_type, contractor_id, status, assembly_id, created_at)
                    VALUES
                       (:routing_op_id, :bom_component_id, :process_id, :work_center_id,
                        :planned_qty, :planned_prod_qty, :uom_id, :prod_uom_id,
                        :planned_comm_qty, :comm_uom_id,
                        :plan_start_date, :plan_end_date,
                        :assign_type, :contractor_id, 'planned', :assembly_id, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':routing_op_id'     => $routing_op_id,
                ':bom_component_id'  => (int)$ro['bom_component_id'],
                ':process_id'        => (int)$ro['process_id'],
                ':work_center_id'    => $work_center_id,
                ':planned_qty'       => $planned_qty,
                ':planned_prod_qty'  => $planned_prod_qty,
                ':uom_id'            => $uom_id,
                ':prod_uom_id'       => $prod_uom_id,
                ':planned_comm_qty'  => $planned_comm_qty,
                ':comm_uom_id'       => $comm_uom_id,
                ':plan_start_date'   => $plan_start_date,
                ':plan_end_date'     => $plan_end_date,
                ':assign_type'       => $assign_type,
                ':contractor_id'     => $contractor_id,
                ':assembly_id'       => $ro['assembly_id'] ?? null,
            ]);

            $pwo_id = (int)$pdo->lastInsertId();
            $pdo->commit();
            return ['pwo_id' => $pwo_id, 'created' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function bulkGenerateForBom(PDO $pdo, int $bom_id, ?int $component_id, array $overrides = []): array
    {
        $ops = self::fetchRoutingOpsForScope($pdo, $bom_id, $component_id);
        $results = ['created' => 0, 'skipped' => 0, 'items' => []];
        foreach ($ops as $op) {
            try {
                $r = self::ensurePwoForRouting($pdo, (int)$op['id'], $overrides);
                $results['items'][] = ['routing_op_id' => (int)$op['id'], 'pwo_id' => (int)$r['pwo_id'], 'created' => $r['created']];
                if ($r['created']) $results['created']++; else $results['skipped']++;
            } catch (Throwable $e) {
                $results['items'][] = ['routing_op_id' => (int)$op['id'], 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    private static function fetchRoutingOp(PDO $pdo, int $id): ?array
    {
        $sql = "SELECT ro.* FROM routing_ops ro WHERE ro.id=:id";
        $st = $pdo->prepare($sql);
        $st->execute([':id'=>$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function findExistingOpenPwo(PDO $pdo, int $routing_op_id): ?array
    {
        $sql = "SELECT id, status
                  FROM process_work_orders
                 WHERE routing_op_id = :rid
                   AND status NOT IN ('closed','completed')
                 ORDER BY id DESC LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':rid'=>$routing_op_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function fetchProcess(PDO $pdo, int $process_id): array
    {
        $st = $pdo->prepare("SELECT id, prod_uom_id, output_uom_id FROM processes WHERE id=:id");
        $st->execute([':id'=>$process_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Process not found: {$process_id}");
        return $row;
    }

    private static function fetchRoutingOpsForScope(PDO $pdo, int $bom_id, ?int $component_id): array
    {
        if ($component_id) {
            $sql = "SELECT ro.id
                      FROM routing_ops ro
                      JOIN bom_components bc ON bc.id = ro.bom_component_id
                     WHERE bc.bom_id = :bom AND bc.id = :comp
                     ORDER BY ro.seq_no ASC";
            $st = $pdo->prepare($sql);
            $st->execute([':bom'=>$bom_id, ':comp'=>$component_id]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql = "SELECT ro.id
                      FROM routing_ops ro
                      JOIN bom_components bc ON bc.id = ro.bom_component_id
                     WHERE bc.bom_id = :bom
                     ORDER BY ro.bom_component_id, ro.seq_no ASC";
            $st = $pdo->prepare($sql);
            $st->execute([':bom'=>$bom_id]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
