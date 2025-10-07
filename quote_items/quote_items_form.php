<?php
/** PATH: /public_html/quote_items/quote_items_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
require_permission($isEdit ? 'quote_items.edit' : 'quote_items.create');

$pdo = db();

// Active UOMs from your master
$uoms = $pdo->query("SELECT code, name FROM uom WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$uomOptionsHtml = '';
foreach ($uoms as $u) {
  $uomOptionsHtml .= '<option value="'.h((string)$u['code']).'">'.h($u['code'].' - '.$u['name']).'</option>';
}

$row = [
  'code' => '',
  'name' => '',
  'hsn_sac' => '',
  'uom' => 'Nos',
  'rate_default' => '0.00',
  'tax_pct_default' => '0.00',
  'is_active' => 1,
];

if ($isEdit) {
  $st = $pdo->prepare("SELECT * FROM quote_items WHERE id=? AND deleted_at IS NULL");
  $st->execute([$id]);
  if ($dbrow = $st->fetch(PDO::FETCH_ASSOC)) $row = array_merge($row, $dbrow);
}

include __DIR__ . '/../ui/layout_start.php';
render_flash();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0"><?= $isEdit ? 'Edit Quote Item' : 'New Quote Item' ?></h1>
  <a href="<?= h('quote_items_list.php') ?>" class="btn btn-outline-secondary">Back</a>
</div>

<form method="post" action="<?= h('quote_items_save.php') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="row g-3">
    <div class="col-md-3">
      <label class="form-label">Code</label>
      <input class="form-control" name="code" value="<?= h((string)$row['code']) ?>" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Name</label>
      <input class="form-control" name="name" value="<?= h((string)$row['name']) ?>" required>
    </div>
    <div class="col-md-3">
      <label class="form-label">HSN / SAC</label>
      <input class="form-control" name="hsn_sac" value="<?= h((string)$row['hsn_sac']) ?>">
    </div>

    <div class="col-md-2">
      <label class="form-label">UOM</label>
      <select class="form-select" name="uom">
        <?php
          $uomSel = (string)$row['uom'];
          $html = $uomOptionsHtml;
          if ($uomSel && !array_filter($uoms, fn($u)=>$u['code']===$uomSel)) {
            echo '<option value="'.h($uomSel).'" selected>'.h($uomSel).'</option>';
            echo $html;
          } else {
            echo str_replace('value="'.h($uomSel).'"', 'value="'.h($uomSel).'" selected', $html);
          }
        ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Default Rate</label>
      <input class="form-control" name="rate_default" value="<?= h((string)$row['rate_default']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Default Tax %</label>
      <input class="form-control" name="tax_pct_default" value="<?= h((string)$row['tax_pct_default']) ?>">
    </div>
    <div class="col-md-2 form-check mt-4">
      <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= ((int)$row['is_active'] ? 'checked' : '') ?>>
      <label class="form-check-label" for="is_active">Active</label>
    </div>
  </div>

  <div class="mt-3">
    <button class="btn btn-primary"><?= $isEdit ? 'Update' : 'Create' ?></button>
  </div>
</form>
<?php include __DIR__ . '/../ui/layout_end.php'; ?>