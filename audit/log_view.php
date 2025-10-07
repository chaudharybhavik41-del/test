<?php
/** PATH: /public_html/audit/log_view.php */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/auth.php';
require_once $ROOT . '/includes/db.php';

require_login();
if (!has_permission('audit.view')) { http_response_code(403); exit('Forbidden'); }

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT a.*, u.username, u.name
                     FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id
                     WHERE a.id=?");
$st->execute([$id]);
$log = $st->fetch(PDO::FETCH_ASSOC);
if (!$log) { http_response_code(404); exit('Not found'); }

function h($v){ return htmlspecialchars((string)$v); }
function pretty($json) {
  if ($json === null || $json === '') return '<em class="text-muted">—</em>';
  $arr = json_decode((string)$json, true);
  if ($arr === null) return '<code>'.h((string)$json).'</code>';
  return '<pre class="mb-0">'.h(json_encode($arr, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
}
?>
<div class="row g-3">
  <div class="col-md-4">
    <div class="card">
      <div class="card-header"><strong>Meta</strong></div>
      <div class="card-body">
        <div><strong>ID:</strong> <?= (int)$log['id'] ?></div>
        <div><strong>When:</strong> <?= h($log['created_at']) ?></div>
        <div><strong>Actor:</strong> <?= h($log['username'] ?? ('user#'.$log['actor_id'])) ?><?php if(!empty($log['name'])): ?> <small class="text-muted">(<?= h($log['name']) ?>)</small><?php endif; ?></div>
        <div><strong>IP:</strong> <?= h($log['ip_addr'] ?? '') ?></div>
        <div><strong>Entity:</strong> <code><?= h($log['entity_type']) ?></code> #<?= (int)$log['entity_id'] ?></div>
        <div><strong>Action:</strong> <span class="badge text-bg-light border"><?= h($log['action']) ?></span></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card">
      <div class="card-header"><strong>Before</strong></div>
      <div class="card-body"><?= pretty($log['before_json']) ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card">
      <div class="card-header"><strong>After</strong></div>
      <div class="card-body"><?= pretty($log['after_json']) ?></div>
    </div>
  </div>
</div>
