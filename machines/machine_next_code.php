<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
require_permission('machines.manage');

$pdo = db();
$type_id = (int)($_GET['type_id'] ?? 0);
if (!$type_id) { http_response_code(400); echo "Type required"; exit; }

// prefer machine_types.machine_code if present; fallback to code (legacy)
$col = $pdo->query("SHOW COLUMNS FROM machine_types LIKE 'machine_code'")->fetch() ? 'machine_code' : 'code';

$stmt = $pdo->prepare("
  SELECT c.id AS cat_id, c.prefix, t.$col AS mcode, t.id AS type_id
  FROM machine_types t
  JOIN machine_categories c ON c.id = t.category_id
  WHERE t.id = ?
");
$stmt->execute([$type_id]);
$tok = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tok) { http_response_code(404); echo "Invalid type"; exit; }

// detect machine_sequences shape
$hasTypeId = (bool)$pdo->query("SHOW COLUMNS FROM machine_sequences LIKE 'type_id'")->fetch();
$hasMCode  = (bool)$pdo->query("SHOW COLUMNS FROM machine_sequences LIKE 'machine_code'")->fetch();

$pdo->beginTransaction();
try {
  if ($hasTypeId) {
    // new schema: (category_id, type_id)
    $sel = $pdo->prepare("SELECT last_seq FROM machine_sequences WHERE category_id=? AND type_id=? FOR UPDATE");
    $sel->execute([(int)$tok['cat_id'], (int)$tok['type_id']]);
    $last = $sel->fetchColumn();
    if ($last === false) {
      $pdo->prepare("INSERT INTO machine_sequences(category_id,type_id,last_seq) VALUES(?,?,0)")
          ->execute([(int)$tok['cat_id'], (int)$tok['type_id']]);
      $last = 0;
    }
    $next = (int)$last + 1;
    $pdo->prepare("UPDATE machine_sequences SET last_seq=? WHERE category_id=? AND type_id=?")
        ->execute([$next, (int)$tok['cat_id'], (int)$tok['type_id']]);
  } else {
    // legacy: (category_id, machine_code)
    if (!$hasMCode) { throw new RuntimeException("machine_sequences schema not recognized"); }
    $sel = $pdo->prepare("SELECT last_seq FROM machine_sequences WHERE category_id=? AND machine_code=? FOR UPDATE");
    $sel->execute([(int)$tok['cat_id'], (string)$tok['mcode']]);
    $last = $sel->fetchColumn();
    if ($last === false) {
      $pdo->prepare("INSERT INTO machine_sequences(category_id,machine_code,last_seq) VALUES(?,?,0)")
          ->execute([(int)$tok['cat_id'], (string)$tok['mcode']]);
      $last = 0;
    }
    $next = (int)$last + 1;
    $pdo->prepare("UPDATE machine_sequences SET last_seq=? WHERE category_id=? AND machine_code=?")
        ->execute([$next, (int)$tok['cat_id'], (string)$tok['mcode']]);
  }

  $pdo->commit();
  echo sprintf('%s-%s-%03d', $tok['prefix'], $tok['mcode'], $next);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(500);
  echo "ERR";
}