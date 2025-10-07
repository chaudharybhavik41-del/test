<?php
/** Simple mailer: SMTP if configured, else mail() */
function send_mail(string $to, string $subject, string $html, string $text='') {
  // Configure in a .php you don't commit (or .env if you have a loader)
  $smtp_host = getenv('EMS_SMTP_HOST') ?: '';   // e.g., smtp.yourhost.com
  $smtp_port = (int)(getenv('EMS_SMTP_PORT') ?: 587);
  $smtp_user = getenv('EMS_SMTP_USER') ?: '';
  $smtp_pass = getenv('EMS_SMTP_PASS') ?: '';
  $from      = getenv('EMS_MAIL_FROM') ?: 'no-reply@yourdomain.tld';
  $fromName  = getenv('EMS_MAIL_NAME') ?: 'EMS ERP';

  if ($smtp_host && $smtp_user && $smtp_pass) {
    // Lightweight SMTP without composer: use PHP's fsockopen if PHPMailer not available.
    // If your host allows PHPMailer, prefer that (vendor/autoload.php).
    $headers = "From: {$fromName} <{$from}>\r\n".
               "MIME-Version: 1.0\r\n".
               "Content-Type: text/html; charset=UTF-8\r\n";
    // Many shared hosts require authenticated SMTP libraries; if not available, use mail() as fallback:
    return mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);
  } else {
    $headers = "From: {$fromName} <{$from}>\r\n".
               "MIME-Version: 1.0\r\n".
               "Content-Type: text/html; charset=UTF-8\r\n";
    return mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $html, $headers);
  }
}
