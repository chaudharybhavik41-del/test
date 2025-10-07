<?php
/** PATH: /public_html/parties/party_next_code.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

try {
  require_login();

  $type = strtolower(trim((string)($_GET['type'] ?? 'supplier')));
  $valid = ['client','supplier','contractor','other'];
  if (!in_array($type, $valid, true)) { throw new RuntimeException('Invalid type'); }

  $pdo = db();

  // Prefix from meta (fallback map)
  $st = $pdo->prepare("SELECT code_prefix FROM party_type_meta WHERE type=?");
  $st->execute([$type]);
  $prefix = (string)($st->fetchColumn() ?: '');
  if ($prefix === '') {
    $map = ['client'=>'CL','supplier'=>'SP','contractor'=>'CT','other'=>'OT'];
    $prefix = $map[$type] ?? 'PT';
  }

  // Peek next (NO INCREMENT here)
  $scope = 'party:' . $prefix;
  $row = $pdo->prepare("SELECT current_value FROM party_sequences WHERE scope=?");
  $row->execute([$scope]);
  $cur = (int)($row->fetchColumn() ?: 0);
  $next = $cur + 1;

  // Preserve historical hyphen style per prefix (e.g., CL-0001 vs SP0023)
  $cst = $pdo->prepare("SELECT code FROM parties WHERE code LIKE ? ORDER BY id DESC LIMIT 1");
  $cst->execute([$prefix . '%']);
  $last = (string)($cst->fetchColumn() ?: '');
  $hyphen = $last && strpos($last, '-') !== false;
  if (!$last && $type === 'client') $hyphen = true;

  $code = $prefix . ($hyphen?'-':'') . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

  echo json_encode(['ok'=>true,'code'=>$code]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
