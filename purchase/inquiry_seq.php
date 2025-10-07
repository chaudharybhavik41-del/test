<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

function next_inquiry_no(PDO $pdo): string {
  $yr = (int)date('Y');
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT seq FROM inquiry_sequences WHERE yr=? FOR UPDATE");
    $st->execute([$yr]);
    $seq = $st->fetchColumn();
    if ($seq === false) {
      $seq = 1;
      $pdo->prepare("INSERT INTO inquiry_sequences (yr, seq) VALUES (?, ?)")->execute([$yr, $seq]);
    } else {
      $seq = (int)$seq + 1;
      $pdo->prepare("UPDATE inquiry_sequences SET seq=? WHERE yr=?")->execute([$seq, $yr]);
    }
    $pdo->commit();
    return sprintf('INQ-%04d-%04d', $yr, (int)$seq);
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}