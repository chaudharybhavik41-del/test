<?php
declare(strict_types=1);
/**
 * Pure UI notifications bell. No DB calls here.
 * If $unread_count and $unread_items are set by the page, we render them.
 * Otherwise, show a graceful empty state.
 */
$unread_count = isset($unread_count) && is_numeric($unread_count) ? (int)$unread_count : 0;
$items = isset($unread_items) && is_array($unread_items) ? $unread_items : [];
?>
<div class="dropdown">
  <a class="btn btn-outline-secondary btn-sm position-relative" href="#" id="notifDropdown"
     role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
    <i class="bi bi-bell"></i>
    <?php if ($unread_count > 0): ?>
      <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
        <?= (int)$unread_count ?>
      </span>
    <?php endif; ?>
  </a>
  <div class="dropdown-menu dropdown-menu-end p-0 shadow" aria-labelledby="notifDropdown" style="min-width: 360px;">
    <div class="list-group list-group-flush">
      <?php if (!$unread_count || empty($items)): ?>
        <div class="p-3 text-muted">No new notifications.</div>
      <?php else: ?>
        <?php foreach ($items as $n): ?>
          <div class="list-group-item">
            <div class="d-flex justify-content-between align-items-start">
              <div class="me-2">
                <div class="fw-semibold"><?= htmlspecialchars((string)($n['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></div>
                <small class="text-muted"><?= htmlspecialchars((string)($n['time'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
              </div>
              <?php if (!empty($n['link'])): ?>
                <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars((string)$n['link'], ENT_QUOTES, 'UTF-8') ?>">Open</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="p-2 text-end">
      <a class="btn btn-light btn-sm" href="/notifications/center.php"><i class="bi bi-bell-fill me-1"></i> Notification Center</a>
    </div>
  </div>
</div>
