<?php
declare(strict_types=1);

function next_po_no(PDO $pdo): string {
  $y = (int)date('Y');
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT seq FROM po_sequences WHERE y=? FOR UPDATE");
    $st->execute([$y]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $seq = (int)$row['seq'] + 1;
      $up  = $pdo->prepare("UPDATE po_sequences SET seq=? WHERE y=?");
      $up->execute([$seq, $y]);
    } else {
      $seq = 1;
      $ins = $pdo->prepare("INSERT INTO po_sequences (y, seq) VALUES (?, ?)");
      $ins->execute([$y, $seq]);
    }
    $pdo->commit();
    return 'PO-' . $y . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
}
