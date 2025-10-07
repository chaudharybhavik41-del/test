<?php
/**
 * PATH: /public_html/tools/workflow_configurator.php
 * Workflow Configurator (PHP 8.4 + Bootstrap 5)
 * - Manage workflows and ordered approval steps per entity
 * - Auto-creates tables if missing
 * Security: requires workflow.manage + central CSRF
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Kolkata');

// Safe esc that tolerates null/ints
if (!function_exists('h')) {
  function h(string|int|float|null $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

$errors = []; $notice = null; $pdo = null;

try {
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/db.php';
  require_once __DIR__ . '/../includes/rbac.php';
  require_once __DIR__ . '/../includes/csrf.php'; // central CSRF

  require_login();
  require_permission('workflow.manage');

  $pdo = db();

  // schema (idempotent)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS workflows (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      entity VARCHAR(100) NOT NULL,
      name VARCHAR(150) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      UNIQUE KEY (entity, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS workflow_steps (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      workflow_id INT UNSIGNED NOT NULL,
      step_order INT UNSIGNED NOT NULL,
      role_code VARCHAR(100) NOT NULL,
      min_approvers INT UNSIGNED NOT NULL DEFAULT 1,
      CONSTRAINT fk_wf_steps_wf FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
      UNIQUE KEY (workflow_id, step_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
} catch (Throwable $e) {
  $errors[] = "Bootstrap failed: " . $e->getMessage();
}

// ---- CSRF compatibility wrapper ----
/**
 * Some stacks define csrf_token() only. If require_csrf() doesn't exist,
 * we validate against the POSTed token manually.
 */
function wf_require_csrf(): void {
  if (function_exists('require_csrf')) {
    require_csrf();
    return;
  }
  $posted = $_POST['csrf_token'] ?? '';
  // Prefer central token if available
  $sessionToken = function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? '');
  if (!is_string($posted) || !is_string($sessionToken) || $posted === '' || $sessionToken === '' || !hash_equals((string)$sessionToken, (string)$posted)) {
    http_response_code(400);
    exit('CSRF token mismatch.');
  }
}

// ---- Controller ----
$action = $_POST['action'] ?? '';
$csrf = function_exists('csrf_token') ? csrf_token() : ($_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32))));

try {
  if ($action !== '') {
    wf_require_csrf();
  }

  if ($action === 'create_wf') {
    $entity = trim((string)($_POST['entity'] ?? ''));
    $name   = trim((string)($_POST['name'] ?? ''));
    if ($entity === '' || $name === '') throw new RuntimeException('Entity and Name are required.');
    $st = $pdo->prepare("INSERT INTO workflows (entity, name, is_active) VALUES (?, ?, 1)");
    $st->execute([$entity, $name]);
    $notice = 'Workflow created.';
  }

  if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE workflows SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
  }

  if ($action === 'add_step') {
    $wf   = (int)($_POST['workflow_id'] ?? 0);
    $role = trim((string)($_POST['role_code'] ?? ''));
    $min  = (int)($_POST['min_approvers'] ?? 1);
    if ($wf <= 0 || $role === '') throw new RuntimeException('Workflow and Role are required.');
    $next = (int)$pdo->query("SELECT COALESCE(MAX(step_order),0)+1 FROM workflow_steps WHERE workflow_id={$wf}")->fetchColumn();
    $st = $pdo->prepare("INSERT INTO workflow_steps (workflow_id, step_order, role_code, min_approvers) VALUES (?, ?, ?, ?)");
    $st->execute([$wf, $next, $role, max(1, $min)]);
  }

  if ($action === 'del_step') {
    $sid = (int)($_POST['step_id'] ?? 0);
    $pdo->prepare("DELETE FROM workflow_steps WHERE id = ?")->execute([$sid]);
  }

  if ($action === 'reorder') {
    $sid = (int)($_POST['step_id'] ?? 0);
    $dir = $_POST['dir'] ?? 'up';
    $st = $pdo->prepare("SELECT workflow_id, step_order FROM workflow_steps WHERE id = ?");
    $st->execute([$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $wf  = (int)$row['workflow_id'];
      $ord = (int)$row['step_order'];
      $swapOrd = $dir === 'up' ? $ord - 1 : $ord + 1;
      if ($swapOrd >= 1) {
        $st2 = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND step_order = ?");
        $st2->execute([$wf, $swapOrd]);
        $swap = $st2->fetch(PDO::FETCH_ASSOC);
        if ($swap) {
          $pdo->beginTransaction();
          try {
            $pdo->prepare("UPDATE workflow_steps SET step_order = 999999 WHERE id = ?")->execute([$sid]);
            $pdo->prepare("UPDATE workflow_steps SET step_order = ? WHERE id = ?")->execute([$ord, (int)$swap['id']]);
            $pdo->prepare("UPDATE workflow_steps SET step_order = ? WHERE id = ?")->execute([$swapOrd, $sid]);
            $pdo->commit();
          } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
          }
        }
      }
    }
  }
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}

// ---- Query for view ----
$workflows = [];
$wfId = (int)($_GET['wf'] ?? ($_POST['workflow_id'] ?? 0));
$steps = [];
try {
  if ($pdo instanceof PDO) {
    $workflows = $pdo->query("SELECT * FROM workflows ORDER BY entity, name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($wfId > 0) {
      $st = $pdo->prepare("SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order");
      $st->execute([$wfId]);
      $steps = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  }
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Workflow Configurator</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-3">
<div class="container-lg">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Workflow Configurator</h1>
    <span class="badge bg-secondary">PHP 8.4</span>
  </div>

  <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>'.h($er).'</li>'; ?></ul></div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header"><strong>Create Workflow</strong></div>
    <div class="card-body">
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="col-md-4"><input class="form-control" name="entity" placeholder="entity (e.g. indent, inquiry)"></div>
        <div class="col-md-5"><input class="form-control" name="name" placeholder="name (e.g. Standard Approval)"></div>
        <div class="col-md-3"><button class="btn btn-primary" name="action" value="create_wf">Create</button></div>
      </form>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header"><strong>Workflows</strong></div>
        <div class="list-group list-group-flush">
          <?php foreach ($workflows as $w): ?>
            <a class="list-group-item list-group-item-action <?= $wfId === (int)$w['id'] ? 'active' : '' ?>" href="?wf=<?= (int)$w['id'] ?>">
              <div class="d-flex justify-content-between">
                <div><strong><?= h($w['entity'] ?? '') ?></strong> — <?= h($w['name'] ?? '') ?></div>
                <form method="post" class="ms-2">
                  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                  <button class="btn btn-sm <?= ($w['is_active'] ?? 0) ? 'btn-success' : 'btn-outline-secondary' ?>" name="action" value="toggle">
                    <?= ($w['is_active'] ?? 0) ? 'Active' : 'Inactive' ?>
                  </button>
                </form>
              </div>
            </a>
          <?php endforeach; ?>
          <?php if (!$workflows): ?><div class="list-group-item text-muted">No workflows yet.</div><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card">
        <div class="card-header"><strong>Steps</strong></div>
        <div class="card-body">
          <?php if ($wfId > 0): ?>
            <form method="post" class="row g-2 mb-3">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="workflow_id" value="<?= (int)$wfId ?>">
              <div class="col-md-6"><input class="form-control" name="role_code" placeholder="role code (e.g. HOD, PURCHASE, DIR)"></div>
              <div class="col-md-3"><input type="number" class="form-control" name="min_approvers" value="1" min="1"></div>
              <div class="col-md-3"><button class="btn btn-primary" name="action" value="add_step">Add Step</button></div>
            </form>

            <div class="table-responsive">
              <table class="table table-sm table-striped align-middle">
                <thead><tr><th>#</th><th>Role</th><th>Min Approvers</th><th>Actions</th></tr></thead>
                <tbody>
                  <?php foreach ($steps as $s): ?>
                    <tr>
                      <td><?= (int)($s['step_order'] ?? 0) ?></td>
                      <td><?= h($s['role_code'] ?? '') ?></td>
                      <td><?= (int)($s['min_approvers'] ?? 1) ?></td>
                      <td class="d-flex gap-1">
                        <form method="post">
                          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                          <input type="hidden" name="step_id" value="<?= (int)$s['id'] ?>">
                          <input type="hidden" name="dir" value="up">
                          <button class="btn btn-outline-secondary btn-sm" name="action" value="reorder">↑</button>
                        </form>
                        <form method="post">
                          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                          <input type="hidden" name="step_id" value="<?= (int)$s['id'] ?>">
                          <input type="hidden" name="dir" value="down">
                          <button class="btn btn-outline-secondary btn-sm" name="action" value="reorder">↓</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this step?')">
                          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                          <input type="hidden" name="step_id" value="<?= (int)$s['id'] ?>">
                          <button class="btn btn-outline-danger btn-sm" name="action" value="del_step">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$steps): ?><tr><td colspan="4" class="text-muted">No steps yet.</td></tr><?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-muted">Select a workflow from the left to manage steps.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>