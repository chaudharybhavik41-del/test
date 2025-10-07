<?php
/** PATH: /public_html/notifications/mark_read.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
$pdo = db();

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
  $st = $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
  $st->execute([$id, current_user_id()]);
}
header('Content-Type: application/json');
echo json_encode(['ok'=>true]);
