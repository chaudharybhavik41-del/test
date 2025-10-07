<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
$pdo = db(); $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

if ($_SERVER['REQUEST_METHOD']==='POST') {
  require_permission('core.settings.mailer.manage');
  $driver = $_POST['driver']==='smtp'?'smtp':'mail';
  $smtp_host = trim($_POST['smtp_host'] ?? '');
  $smtp_port = $_POST['smtp_port']!=='' ? (int)$_POST['smtp_port'] : null;
  $smtp_secure = in_array($_POST['smtp_secure'] ?? '', ['','tls','ssl'], true) ? $_POST['smtp_secure'] : '';
  $smtp_user = trim($_POST['smtp_user'] ?? '');
  $smtp_pass = trim($_POST['smtp_pass'] ?? '');
  $from_email = trim($_POST['from_email'] ?? '');
  $from_name  = trim($_POST['from_name'] ?? '');
  $reply_to_email = trim($_POST['reply_to_email'] ?? '');
  $reply_to_name  = trim($_POST['reply_to_name'] ?? '');

  $pdo->prepare("UPDATE mailer_settings SET driver=?, smtp_host=?, smtp_port=?, smtp_secure=?, smtp_user=?, smtp_pass=?, from_email=?, from_name=?, reply_to_email=?, reply_to_name=? WHERE id=1")
      ->execute([$driver,$smtp_host,$smtp_port,$smtp_secure,$smtp_user,$smtp_pass,$from_email,$from_name,$reply_to_email,$reply_to_name]);

  header('Location: /settings/mailer_form.php?saved=1'); exit;
}

require_permission('core.settings.mailer.view');
$cfg = $pdo->query("SELECT * FROM mailer_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/../ui/layout_start.php';
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Mailer Settings</h1>
  </div>

  <?php if (!empty($_GET['saved'])): ?>
    <div class="alert alert-success">Saved.</div>
  <?php endif; ?>

  <form method="post" class="card shadow-sm p-3">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Driver</label>
        <select name="driver" class="form-select" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
          <option value="mail" <?= ($cfg['driver']??'mail')==='mail'?'selected':'' ?>>PHP mail()</option>
          <option value="smtp" <?= ($cfg['driver']??'mail')==='smtp'?'selected':'' ?>>SMTP (PHPMailer)</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">From Email</label>
        <input name="from_email" class="form-control" value="<?=htmlspecialchars($cfg['from_email'] ?? '')?>" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
      </div>
      <div class="col-md-5">
        <label class="form-label">From Name</label>
        <input name="from_name" class="form-control" value="<?=htmlspecialchars($cfg['from_name'] ?? '')?>" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
      </div>

      <div class="col-md-4">
        <label class="form-label">Reply-To Email</label>
        <input name="reply_to_email" class="form-control" value="<?=htmlspecialchars($cfg['reply_to_email'] ?? '')?>" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
      </div>
      <div class="col-md-4">
        <label class="form-label">Reply-To Name</label>
        <input name="reply_to_name" class="form-control" value="<?=htmlspecialchars($cfg['reply_to_name'] ?? '')?>" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
      </div>

      <div class="col-12"><hr></div>

      <div class="col-md-4">
        <label class="form-label">SMTP Host</label>
        <input name="smtp_host" class="form-control" value="<?=htmlspecialchars($cfg['smtp_host'] ?? '')?>" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
      </div>
      <div class="col-md-2">
        <label class="form-label">Port</label>
        <input name="smtp_port" type="number" class="form-control" value="<?=htmlspecialchars((string)($cfg['smtp_port'] ?? ''))?>" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
      </div>
      <div class="col-md-2">
        <label class="form-label">Security</label>
        <select name="smtp_secure" class="form-select" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
          <option value="" <?= ($cfg['smtp_secure']??'')===''?'selected':'' ?>>None</option>
          <option value="tls" <?= ($cfg['smtp_secure']??'')==='tls'?'selected':'' ?>>TLS</option>
          <option value="ssl" <?= ($cfg['smtp_secure']??'')==='ssl'?'selected':'' ?>>SSL</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">SMTP Username</label>
        <input name="smtp_user" class="form-control" value="<?=htmlspecialchars($cfg['smtp_user'] ?? '')?>" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
      </div>
      <div class="col-md-4">
        <label class="form-label">SMTP Password</label>
        <input name="smtp_pass" type="password" class="form-control" value="<?=htmlspecialchars($cfg['smtp_pass'] ?? '')?>" <?= has_permission('core.settings.mailer.manage')?'':'disabled' ?>>
      </div>
    </div>

    <?php if (has_permission('core.settings.mailer.manage')): ?>
      <div class="mt-3">
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    <?php endif; ?>
  </form>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>