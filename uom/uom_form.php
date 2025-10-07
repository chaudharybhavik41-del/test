<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.uom.manage');

$pdo = db();
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;

if ($id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM uom WHERE id=?");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  if (!$row) { http_response_code(404); exit('UOM not found'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf_or_die();
  $code   = strtoupper(trim($_POST['code'] ?? ''));
  $name   = trim($_POST['name'] ?? '');
  $type   = $_POST['type'] ?? 'qty';
  $status = $_POST['status'] ?? 'active';

  if ($code === '' || $name === '') {
    $err = 'Code and Name are required.';
  } else {
    try {
      if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE uom SET code=?, name=?, type=?, status=? WHERE id=?");
        $stmt->execute([$code, $name, $type, $status, $id]);
      } else {
        $stmt = $pdo->prepare("INSERT INTO uom (code,name,type,status) VALUES (?,?,?,?)");
        $stmt->execute([$code, $name, $type, $status]);
        $id = (int)$pdo->lastInsertId();
      }
      header('Location: uom_list.php'); exit;
    } catch (Throwable $e) {
      $err = 'Error saving UOM. (Maybe duplicate code?)';
    }
  }
}
$val = fn($k,$d='') => htmlspecialchars($row[$k] ?? $d, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo $id?'Edit':'New';?> UOM — EMS Infra ERP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/styles.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="uom_list.php">UOM</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?php echo $id ? 'Edit' : 'New'; ?></li>
      </ol>
    </nav>
    <a class="btn btn-light btn-sm" href="uom_list.php"><i class="bi bi-arrow-left"></i> Back</a>
  </div>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <h5 class="mb-3"><?php echo $id?'Edit':'New';?> UOM</h5>
          <?php if (!empty($err)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($err);?></div>
          <?php endif; ?>

          <form method="post">
            <?php echo csrf_field(); ?>
            <div class="mb-3">
              <label class="form-label">Code</label>
              <input class="form-control" name="code" maxlength="16" required value="<?php echo $val('code');?>">
              <div class="form-text">e.g., KG, M, NOS (auto-uppercased)</div>
            </div>
            <div class="mb-3">
              <label class="form-label">Name</label>
              <input class="form-control" name="name" maxlength="64" required value="<?php echo $val('name');?>">
            </div>
            <div class="mb-3">
              <label class="form-label">Type</label>
              <?php $typeSel = $row['type'] ?? 'qty'; ?>
              <select class="form-select" name="type">
                <?php foreach (['qty','length','area','volume','weight','time','other'] as $t): ?>
                  <option value="<?php echo $t; ?>" <?php echo $typeSel===$t?'selected':''; ?>>
                    <?php echo ucfirst($t); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Status</label>
              <?php $statusSel = $row['status'] ?? 'active'; ?>
              <select class="form-select" name="status">
                <option value="active"  <?php echo $statusSel==='active'?'selected':''; ?>>Active</option>
                <option value="inactive"<?php echo $statusSel==='inactive'?'selected':''; ?>>Inactive</option>
              </select>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary"><?php echo $id?'Update':'Create';?></button>
              <a class="btn btn-outline-secondary" href="uom_list.php">Cancel</a>
            </div>
          </form>

        </div>
      </div>
    </div>
  </div>
</div>
<script>
  document.addEventListener('input', e => {
    if (e.target.name === 'code') e.target.value = e.target.value.toUpperCase();
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>