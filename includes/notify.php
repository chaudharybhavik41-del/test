<?php
/** PATH: /public_html/includes/notify.php */
declare(strict_types=1);

/** Direct inbox write (synchronous) */
function notify_inbox(PDO $pdo, array $userIds, string $title, ?string $body = null): void {
  if (!$userIds) return;
  $ins = $pdo->prepare("INSERT INTO notifications (user_id, title, body, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
  foreach ($userIds as $uid) $ins->execute([(int)$uid, $title, $body]);
}

/** Enqueue async job (for batches/email/SMS later) */
function queue_notify(PDO $pdo, array $userIds, string $title, ?string $body = null, ?string $notBefore = null): void {
  $payload = json_encode(['user_ids'=>$userIds, 'title'=>$title, 'body'=>$body], JSON_UNESCAPED_UNICODE);
  $st = $pdo->prepare("INSERT INTO notification_queue (job_type, payload_json, not_before_at, status, created_at)
                       VALUES ('notify', ?, ?, 'queued', NOW())");
  $st->execute([$payload, $notBefore]);
}
