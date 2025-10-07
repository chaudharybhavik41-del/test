<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('purchase.indent.manage');

$pdo = db();

function fmt_indent(int $y, int $seq): string { return sprintf('IND-%04d-%04d', $y, $seq); }

$mode = strtolower($_GET['mode'] ?? 'peek');
$y = (int)date('Y');
header('Content-Type: application/json');

if ($mode === 'peek') {
  $st = $pdo->prepare("SELECT seq FROM indent_sequences WHERE year=?");
  $st->execute([$y]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $seq = $row ? ((int)$row['seq'] + 1) : 1;
  echo json_encode(['ok'=>true,'number'=>fmt_indent($y,$seq),'reserved'=>false]); exit;
}

if ($mode === 'allocate' && $_SERVER['REQUEST_METHOD']==='POST') {
  $pdo->beginTransaction();
  try {
    $s = $pdo->prepare("SELECT seq FROM indent_sequences WHERE year=? FOR UPDATE");
    $s->execute([$y]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $seq = $row ? (int)$row['seq'] : 0;
    if (!$row) $pdo->prepare("INSERT INTO indent_sequences(year,seq) VALUES(?,0)")->execute([$y]);
    $seq++;
    $pdo->prepare("UPDATE indent_sequences SET seq=? WHERE year=?")->execute([$seq,$y]);
    $no = fmt_indent($y,$seq);
    $pdo->commit();
    echo json_encode(['ok'=>true,'number'=>$no,'reserved'=>true]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'allocate_failed']);
  }
  exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'invalid']);