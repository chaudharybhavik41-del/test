<?php
/** PATH: /public_html/quote_items/quote_items_save.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
$pdo = db();

$action = (string)($_POST['action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);

if ($action === 'delete') {
  verify_csrf_or_die();
  require_permission('quote_items.delete');
  if ($id <= 0) { http_response_code(400); exit('Invalid id'); }
  $pdo->prepare("UPDATE quote_items SET deleted_at=NOW() WHERE id=?")->execute([$id]);
  audit_log($pdo, 'quote_items', 'delete', $id, null);
  set_flash('success', 'Quote item deleted.');
  header('Location: quote_items_list.php'); exit;
}

verify_csrf_or_die();
$isEdit = $id > 0;
require_permission($isEdit ? 'quote_items.edit' : 'quote_items.create');

$data = [];
$data['code'] = trim((string)($_POST['code'] ?? ''));
$data['name'] = trim((string)($_POST['name'] ?? ''));
$data['hsn_sac'] = trim((string)($_POST['hsn_sac'] ?? ''));
$data['uom'] = trim((string)($_POST['uom'] ?? 'Nos'));
$data['rate_default'] = (string)($_POST['rate_default'] ?? '0.00');
$data['tax_pct_default'] = (string)($_POST['tax_pct_default'] ?? '0.00');
$data['is_active'] = isset($_POST['is_active']) ? 1 : 0;

try {
  if (!$isEdit) {
    $sql = "INSERT INTO quote_items
      (code,name,hsn_sac,uom,rate_default,tax_pct_default,is_active,created_at,deleted_at)
      VALUES
      (:code,:name,:hsn_sac,:uom,:rate_default,:tax_pct_default,:is_active,NOW(),NULL)";
    $st = $pdo->prepare($sql);
    $st->execute($data);
    $newId = (int)$pdo->lastInsertId();
    audit_log($pdo, 'quote_items', 'create', $newId, $data);
    set_flash('success', 'Quote item created.');
    header('Location: quote_items_form.php?id='.$newId); exit;
  } else {
    $data['id'] = $id;
    $sql = "UPDATE quote_items SET
      code=:code, name=:name, hsn_sac=:hsn_sac, uom=:uom, rate_default=:rate_default, tax_pct_default=:tax_pct_default, is_active=:is_active
      WHERE id=:id AND deleted_at IS NULL";
    $st = $pdo->prepare($sql);
    $st->execute($data);
    audit_log($pdo, 'quote_items', 'update', $id, $data);
    set_flash('success', 'Quote item updated.');
    header('Location: quote_items_form.php?id='.$id); exit;
  }
} catch (Throwable $e) {
  set_flash('danger', $e->getMessage());
  header('Location: quote_items_form.php'.($isEdit?('?id='.$id):'')); exit;
}
