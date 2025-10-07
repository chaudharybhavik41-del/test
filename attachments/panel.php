<?php
/** PATH: /public_html/attachments/panel.php
 * Required vars before include: $ENTITY (string), $ENTITY_ID (int), $UI_PATH, $pdo
 * Requires: includes/auth.php, includes/db.php already loaded by parent.
 */
if (!isset($ENTITY, $ENTITY_ID, $pdo)) { http_response_code(500); exit('Attachments panel vars missing'); }
$canUpload = has_permission('attachment.manage') ?: true; // relax if you prefer

$st = $pdo->prepare("SELECT al.id link_id, a.id, a.original_name, a.mime_type, a.byte_size, a.storage_path, a.created_at
                     FROM attachment_links al
                     JOIN attachments a ON a.id = al.attachment_id
                     WHERE al.entity_type = ? AND al.entity_id = ?
                     ORDER BY a.id DESC");
$st->execute([$ENTITY, $ENTITY_ID]);
$files = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Attachments</strong>
    <?php if ($canUpload): ?>
      <form class="d-inline" action="/attachments/upload.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="entity" value="<?= htmlspecialchars($ENTITY) ?>">
        <input type="hidden" name="entity_id" value="<?= (int)$ENTITY_ID ?>">
        <input type="file" name="file" class="form-control form-control-sm d-inline-block" style="width:280px" required>
        <button class="btn btn-sm btn-primary">Upload</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$files): ?>
      <div class="text-muted">No files yet.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th>#</th><th>File</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($files as $f): ?>
              <tr>
                <td><?= (int)$f['id'] ?></td>
                <td><a href="<?= htmlspecialchars((string)$f['storage_path']) ?>" target="_blank"><?= htmlspecialchars((string)$f['original_name']) ?></a></td>
                <td><?= number_format((int)$f['byte_size']/1024, 1) ?> KB</td>
                <td><?= htmlspecialchars((string)$f['created_at']) ?></td>
                <td>
                  <a class="btn btn-sm btn-outline-danger" href="/attachments/delete.php?link_id=<?= (int)$f['link_id'] ?>&return=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/') ?>"
                     onclick="return confirm('Remove link to this file? File stays on disk if linked elsewhere.')">Remove</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
