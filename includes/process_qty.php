<?php
// /includes/process_qty.php
// Safe process-qty evaluator + helper that reads your rules and component dims.
// Requires: $pdo from /includes/db.php
declare(strict_types=1);

/**
 * Very small safe evaluator:
 * - Replaces variable names (a–z, 0–9, _) with numeric values you pass
 * - Supports + - * / and parentheses
 * - No eval(), no functions
 */
function pq_eval(string $expr, array $vars): float {
  $replaced = preg_replace_callback('/[A-Za-z_][A-Za-z0-9_]*/', function($m) use ($vars) {
    $k = $m[0];
    if (!array_key_exists($k, $vars)) throw new RuntimeException("Missing var: $k");
    $v = $vars[$k];
    if (!is_numeric($v)) throw new RuntimeException("Non-numeric var: $k");
    return (string)(0 + $v);
  }, $expr);

  $tokens = preg_split('/(?<=[+\-*\/\(\)])|(?=[+\-*\/\(\)])/', $replaced, -1, PREG_SPLIT_NO_EMPTY);
  $prec = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

  $out = []; $ops = [];
  foreach ($tokens as $t) {
    if (is_numeric($t)) { $out[] = (float)$t; continue; }
    if ($t === '(') { $ops[] = $t; continue; }
    if ($t === ')') {
      while ($ops && end($ops) !== '(') $out[] = array_pop($ops);
      if (!$ops || array_pop($ops) !== '(') throw new RuntimeException("Mismatched parentheses");
      continue;
    }
    if (isset($prec[$t])) {
      while ($ops && isset($prec[end($ops)]) && $prec[end($ops)] >= $prec[$t]) $out[] = array_pop($ops);
      $ops[] = $t; continue;
    }
    throw new RuntimeException("Bad token: $t");
  }
  while ($ops) {
    $op = array_pop($ops);
    if ($op === '(') throw new RuntimeException("Mismatched parentheses");
    $out[] = $op;
  }
  $st = [];
  foreach ($out as $tok) {
    if (!is_string($tok)) { $st[] = $tok; continue; }
    $b = array_pop($st); $a = array_pop($st);
    $st[] = match($tok) {
      '+' => $a + $b,
      '-' => $a - $b,
      '*' => $a * $b,
      '/' => ($b == 0 ? 0.0 : $a / $b),
      default => throw new RuntimeException("Bad op")
    };
  }
  if (count($st) !== 1) throw new RuntimeException("Eval error");
  return (float)$st[0];
}

/**
 * Compute process-qty for an existing routing_op id.
 * Uses: processes.code => process_qty_rules.operation_code
 * Pulls dims from bom_components (length_mm, width_mm, thickness_mm) when available.
 * Returns: [qty(float), uom_id(int), inputs(array)] or null if no rule.
 */
function pq_compute_for_op(PDO $pdo, int $routing_op_id): ?array {
  $sql = "SELECT ro.id, ro.bom_component_id, ro.process_id, p.code AS process_code,
                 bc.length_mm, bc.width_mm, bc.thickness_mm
          FROM routing_ops ro
          JOIN processes p ON p.id=ro.process_id
          LEFT JOIN bom_components bc ON bc.id=ro.bom_component_id
          WHERE ro.id=?";
  $st = $pdo->prepare($sql); $st->execute([$routing_op_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) return null;

  // Find matching rule by process code
  $q = $pdo->prepare("SELECT id, expr, result_uom_id, required_vars_json
                      FROM process_qty_rules WHERE operation_code=? LIMIT 1");
  $q->execute([$row['process_code']]);
  $rule = $q->fetch(PDO::FETCH_ASSOC);
  if (!$rule) return null;

  $req = json_decode($rule['required_vars_json'] ?? '[]', true) ?: [];
  $vars = [];
  foreach ($req as $k) {
    $vars[$k] = match($k) {
      'length_mm'    => (float)($row['length_mm'] ?? 0),
      'width_mm'     => (float)($row['width_mm'] ?? 0),
      'thickness_mm' => (float)($row['thickness_mm'] ?? 0),
      default        => 0.0
    };
  }
  $qty = pq_eval($rule['expr'], $vars);
  return [$qty, (int)$rule['result_uom_id'], $vars];
}
