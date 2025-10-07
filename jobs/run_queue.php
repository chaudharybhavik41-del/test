<?php
/** PATH: /public_html/jobs/run_queue.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notify.php';

require_login();
if (!has_permission('queue.run')) { http_response_code(403); exit('Forbidden'); }

$pdo = db();
$now = date('Y-m-d H:i:s');

$st = $pdo->prepare("SELECT * FROM notification_queue
                     WHERE status='queued' AND (not_before_at IS NULL OR not_before_at <= ?)
                     ORDER BY id ASC LIMIT 50");
$st->execute([$now]);
$jobs = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($jobs as $j) {
  $pdo->prepare("UPDATE notification_queue SET status='running', attempts=attempts+1 WHERE id=?")->execute([(int)$j['id']]);
  try {
    if ($j['job_type'] === 'notify') {
      $p = json_decode((string)$j['payload_json'], true) ?: [];
      $uids = array_map('intval', (array)($p['user_ids'] ?? []));
      $title = (string)($p['title'] ?? '');
      $body  = (string)($p['body']  ?? '');
      notify_inbox($pdo, $uids, $title, $body);
    }
    $pdo->prepare("UPDATE notification_queue SET status='done', last_error=NULL WHERE id=?")->execute([(int)$j['id']]);
  } catch (Throwable $e) {
    $pdo->prepare("UPDATE notification_queue SET status='error', last_error=? WHERE id=?")
        ->execute([substr($e->getMessage(),0,480), (int)$j['id']]);
  }
}

echo "Processed: ".count($jobs);
