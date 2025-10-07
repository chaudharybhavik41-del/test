<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function app_mail_send(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
  $pdo = db();
  $cfg = $pdo->query("SELECT * FROM mailer_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];
  $fromEmail = $cfg['from_email'] ?? null;
  $fromName  = $cfg['from_name']  ?? 'ERP';

  if (($cfg['driver'] ?? 'mail') === 'smtp' && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
      $mail->isSMTP();
      $mail->Host = (string)($cfg['smtp_host'] ?? '');
      $mail->Port = (int)($cfg['smtp_port'] ?? 587);
      $sec = (string)($cfg['smtp_secure'] ?? '');
      if ($sec==='tls' || $sec==='ssl') $mail->SMTPSecure = $sec;
      $mail->SMTPAuth = !empty($cfg['smtp_user']);
      if ($mail->SMTPAuth) { $mail->Username = (string)$cfg['smtp_user']; $mail->Password = (string)$cfg['smtp_pass']; }
      $mail->setFrom($fromEmail ?: 'no-reply@example.com', $fromName);
      if (!empty($cfg['reply_to_email'])) $mail->addReplyTo($cfg['reply_to_email'], $cfg['reply_to_name'] ?? '');
      $mail->addAddress($toEmail, $toName);
      $mail->isHTML(true); $mail->Subject = $subject; $mail->Body = $htmlBody;
      return $mail->send();
    } catch (\Throwable $e) {
      // fall back to mail()
    }
  }
  $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\n";
  if ($fromEmail) $headers .= "From: {$fromEmail}\r\n";
  return @mail($toEmail, $subject, $htmlBody, $headers);
}