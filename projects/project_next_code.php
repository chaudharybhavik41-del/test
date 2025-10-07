<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('project.project.manage');

$pdo = db();

function format_project_code(int $year, int $seq): string {
  return sprintf('PA-%04d-%04d', $year, $seq); // PA-2025-0001
}

$mode = strtolower(trim($_GET['mode'] ?? 'peek'));
$year = (int)date('Y');

header('Content-Type: application/json');

if ($mode === 'peek') {
  // Just show next number without incrementing
  $stmt = $pdo->prepare("SELECT seq FROM project_sequences WHERE year = ?");
  $stmt->execute([$year]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $seq = $row ? ((int)$row['seq'] + 1) : 1;
  echo json_encode(['ok' => true, 'code' => format_project_code($year, $seq), 'reserved' => false]);
  exit;
}

if ($mode === 'allocate') {
  // Reserve/allocate the next code (use POST if you expose this)
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'use POST for allocate']);
    exit;
  }

  $pdo->beginTransaction();
  try {
    $s = $pdo->prepare("SELECT seq FROM project_sequences WHERE year = ? FOR UPDATE");
    $s->execute([$year]);
    $row = $s->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $pdo->prepare("INSERT INTO project_sequences(year, seq) VALUES(?, 0)")->execute([$year]);
      $seq = 0;
    } else {
      $seq = (int)$row['seq'];
    }

    $seq++;
    $pdo->prepare("UPDATE project_sequences SET seq = ? WHERE year = ?")->execute([$seq, $year]);

    $code = format_project_code($year, $seq);
    $pdo->commit();

    echo json_encode(['ok' => true, 'code' => $code, 'reserved' => true]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'allocate_failed']);
  }
  exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'invalid_mode']);
