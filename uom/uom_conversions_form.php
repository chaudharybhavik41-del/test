<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.uom.conversion.manage');

$pdo = db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$row = null;

if ($id > 0) {
  $stmt = $pdo->prepare("
    SELECT uc.*, fu.code AS from_code, tu.code AS to_code
    FROM uom_conversions uc
    JOIN uom fu ON fu.id = uc.from_uom_id
    JOIN uom tu ON tu.id = uc.to_uom_id
    WHERE uc.id=?");
  $stmt->execute([$id]);
  $row = $stmt->fetch() ?: null;
}

$uoms = $pdo->query("SELECT id, code FROM uom WHERE status='active' ORDER BY code")->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST') {
  verify_csrf_or_die();
  $from_id = (int)($_POST['from_id'] ?? 0);
  $to_id   = (int)($_POST['to_id'] ?? 0);
  $factor  = (float)($_POST['factor'] ?? 0);
  $add_inv = isset($_POST['make_inverse']);

  if ($from_id<=0 || $to_id<=0 || $factor<=0 || $from_id===$to_id) {
    $err = 'Please select different UOMs and a positive factor.';
  } else {
    try {
      if ($id>0) {
        $upd = $pdo->prepare("UPDATE uom_conversions SET from_uom_id=?, to_uom_id=?, factor=?, offset_val=0 WHERE id=?");
        $upd->execute([$from_id,$to_id,$factor,$id]);
      } else {
        $ins = $pdo->prepare("INSERT INTO uom_conversions (from_uom_id,to_uom_id,factor,offset_val) VALUES (?,?,?,0)");
        $ins->execute([$from_id,$to_id,$factor]);
      }
      if ($add_inv) {
        $inv = $pdo->prepare("INSERT INTO uom_conversions (from_uom_id,to_uom_id,factor,offset_val)
                              VALUES (?,?,?,0)
                              ON DUPLICATE KEY UPDATE factor=VALUES(factor), offset_val=0");
        $inv->execute([$to_id, $from_id, 1.0/$factor]);
      }
      header('Location: uom_conversions_list.php'); exit;
    } catch (Throwable $e) {
      $err = 'Duplicate pair or DB error.';
    }
  }
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title><?php echo $id?'Edit':'Add';?> Conversion</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="/styles.css" rel="stylesheet">
</head><body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="uom_list.php">UOM</a></li>
        <li class="breadcrumb-item"><a href="uom_conversions_list.php">Conversions</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?php echo $id?'Edit':'Add'; ?></li>
      </ol>
    </nav>
    <a class="btn btn-light btn-sm" href="uom_conversions_list.php"><i class="bi bi-arrow-left"></i> Back</a>
  </div>

  <div class="row justify-content-center">
    <div class="col-lg-6">
      <div class="card shadow-sm rounded-4">
        <div class="card-body">
          <h5 class="mb-3"><?php echo $id?'Edit':'Add';?> Conversion</h5>
          <?php if (!empty($err)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err);?></div><?php endif; ?>

          <form method="post">
            <?php echo csrf_field(); ?>
            <div class="row g-3">
              <div class="col-md-5">
                <label class="form-label">From</label>
                <select class="form-select" name="from_id" required>
                  <option value="">Choose...</option>
                  <?php foreach ($uoms as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>" <?php echo ($row && $row['from_uom_id']==$u['id'])?'selected':''; ?>>
                      <?php echo htmlspecialchars($u['code']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2 d-flex align-items-end justify-content-center">
                <span class="fw-bold fs-5">→</span>
              </div>
              <div class="col-md-5">
                <label class="form-label">To</label>
                <select class="form-select" name="to_id" required>
                  <option value="">Choose...</option>
                  <?php foreach ($uoms as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>" <?php echo ($row && $row['to_uom_id']==$u['id'])?'selected':''; ?>>
                      <?php echo htmlspecialchars($u['code']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="mt-3">
              <label class="form-label">Factor</label>
              <input class="form-control" type="number" step="0.0000000001" min="0" name="factor"
                     value="<?php echo $row? htmlspecialchars((string)$row['factor']):''; ?>" required>
              <div class="form-text">qty_to = qty_from × factor</div>
            </div>

            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" name="make_inverse" id="inv" checked>
              <label class="form-check-label" for="inv">Automatically create/update inverse</label>
            </div>

            <div class="d-flex gap-2 mt-3">
              <button class="btn btn-primary"><?php echo $id?'Update':'Create';?></button>
              <a class="btn btn-outline-secondary" href="uom_conversions_list.php">Cancel</a>
            </div>
          </form>

        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>