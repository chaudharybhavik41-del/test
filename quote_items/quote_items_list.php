<?php
/** PATH: /public_html/quote_items/quote_items_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_permission('quote_items.view');

$pdo = db();
$q = trim($_GET['q'] ?? '');

$params = [];
$where = " WHERE I.deleted_at IS NULL ";
if ($q !== '') {
  $i = 1; $terms = [];
  foreach (['I.code','I.name','I.hsn_sac','I.uom'] as $col) {
    $ph = ":q{$i}";
    $terms[] = "{$col} LIKE {$ph}";
    $params[$ph] = "%{$q}%"; $i++;
  }
  $where .= " AND (".implode(' OR ', $terms).") ";
}

$sql = "
SELECT I.*
FROM quote_items I
{$where}
ORDER BY I.id DESC
LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
render_flash();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0">Quote Items / Services</h1>
  <div class="d-flex gap-2">
    <form method="get" class="d-flex gap-2">
      <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search code/name/HSN/UOM">
      <button class="btn btn-outline-secondary">Search</button>
    </form>
    <?php if (has_permission('quote_items.create')): ?>
      <a href="<?= h('quote_items_form.php') ?>" class="btn btn-primary">+ New</a>
    <?php endif; ?>
  </div>
</div>

<div class="table-responsive">
  <table class="table table-striped align-middle">
    <thead>
      <tr>
        <th>ID</th><th>Code</th><th>Name</th><th>HSN/SAC</th>
        <th>UOM</th><th>Default Rate</th><th>Default Tax %</th><th>Active</th>
        <th style="width:200px">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="999" class="text-center text-muted py-4">No quote items found.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= h((string)$r['code']) ?></td>
          <td><?= h((string)$r['name']) ?></td>
          <td><?= h((string)$r['hsn_sac']) ?></td>
          <td><?= h((string)$r['uom']) ?></td>
          <td class="text-end"><?= h((string)$r['rate_default']) ?></td>
          <td class="text-end"><?= h((string)$r['tax_pct_default']) ?></td>
          <td><?= ((int)$r['is_active'] ? 'Yes' : 'No') ?></td>
          <td>
            <div class="btn-group btn-group-sm">
              <?php if (has_permission('quote_items.edit')): ?>
                <a class="btn btn-outline-secondary" href="<?= h('quote_items_form.php?id='.(int)$r['id']) ?>">Edit</a>
              <?php endif; ?>
              <?php if (has_permission('quote_items.delete')): ?>
                <form method="post" action="<?= h('quote_items_save.php') ?>" onsubmit="return confirm('Delete this item?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-outline-danger">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>
