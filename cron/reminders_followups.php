<?php
declare(strict_types=1);
require_once __DIR__ . '/../public_html/includes/db.php';

$pdo = db();

// Due today or overdue and still open
$rows = $pdo->query("
  SELECT a.*, u.email AS owner_email, u.name AS owner_name
  FROM crm_activities a
  LEFT JOIN users u ON u.id=a.owner_id
  WHERE a.status IN ('Open','In Progress')
    AND a.due_at IS NOT NULL
    AND (DATE(a.due_at) <= CURRENT_DATE)
  ORDER BY a.owner_id, a.due_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($rows as $r) $grouped[$r['owner_id']][] = $r;

foreach ($grouped as $uid => $list) {
  $lines = [];
  foreach ($list as $r) {
    $flag = (strtotime($r['due_at']) < time()) ? 'OVERDUE' : 'TODAY';
    $lines[] = sprintf("[%s] %s — %s (Due: %s)", $flag, $r['type'], $r['subject'], $r['due_at']);
  }
  $subject = "Follow-ups: ".count($list)." item(s) due";
  $body = "Hi,\n\nHere are your due follow-ups:\n\n".implode("\n", $lines)."\n\n— CRM";
  // optional: use your send_mail() helper if available
  if (function_exists('send_mail') && !empty($list[0]['owner_email'])) {
    @send_mail($list[0]['owner_email'], $subject, nl2br(htmlspecialchars($body,ENT_QUOTES|ENT_SUBSTITUTE)));
  }
  $pdo->prepare("INSERT INTO notifications_log(user_id,channel,subject,body,created_at) VALUES(:u,'Email',:s,:b,NOW())")
      ->execute([':u'=>$uid, ':s'=>$subject, ':b'=>$body]);
}