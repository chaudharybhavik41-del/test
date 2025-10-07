<?php
/** PATH: /public_html/crm_leads/crm_leads_list.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_permission('crm.lead.view');

$pdo = db();
$q = trim($_GET['q'] ?? '');

// columns to render
$displayCols = ['id','code','title','status','amount','is_hot','follow_up_on','party_name','contact_name'];

// SEARCH (unique placeholders)
$params = [];
$where = " WHERE L.deleted_at IS NULL ";
if ($q !== '') {
  $terms = [];
  $i = 1;
  foreach (['L.code','L.title','L.status','P.name','C.name'] as $col) {
    $ph = ":q{$i}";
    $terms[] = "{$col} LIKE {$ph}";
    $params[$ph] = "%{$q}%";
    $i++;
  }
  $where .= " AND (" . implode(' OR ', $terms) . ") ";
}

$sql = "
SELECT
  L.*,
  P.name AS party_name,
  C.name AS contact_name
FROM crm_leads L
LEFT JOIN parties P ON P.id = L.party_id AND P.deleted_at IS NULL
LEFT JOIN party_contacts C ON C.id = L.party_contact_id AND C.deleted_at IS NULL
{$where}
ORDER BY L.id DESC
LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../ui/layout_start.php';
render_flash();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 mb-0">CRM Leads</h1>
  <div class="d-flex gap-2">
    <form method="get" class="d-flex gap-2">
      <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Search code/title/status/party/contact">
      <button class="btn btn-outline-secondary">Search</button>
    </form>
    <?php if (has_permission('crm.lead.create')): ?>
      <a href="<?= h('crm_leads_form.php') ?>" class="btn btn-primary">+ New</a>
    <?php endif; ?>
  </div>
</div>

<div class="table-responsive">
  <table class="table table-striped align-middle">
    <thead>
      <tr>
        <?php foreach ($displayCols as $c): ?>
          <th><?= h(ucwords(str_replace('_',' ', $c))) ?></th>
        <?php endforeach; ?>
        <th style="width:220px">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="999" class="text-center text-muted py-4">No records found.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($displayCols as $c): ?>
            <td><?= h((string)($r[$c] ?? '')) ?></td>
          <?php endforeach; ?>
          <td>
            <div class="btn-group btn-group-sm">
              <?php if (has_permission('crm.lead.edit')): ?>
                <a class="btn btn-outline-secondary" href="<?= h('crm_leads_form.php?id='.(int)$r['id']) ?>">Edit</a>
              <?php endif; ?>
              <?php if (has_permission('sales.order.create')): ?>
                <a class="btn btn-outline-primary" href="<?= h('convert_to_order.php?lead_id='.(int)$r['id']) ?>">Convert → Order</a>
              <?php endif; ?>
              <?php if (has_permission('crm.lead.delete')): ?>
                <form method="post" action="<?= h('crm_leads_save.php') ?>" onsubmit="return confirm('Delete this lead?')">
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