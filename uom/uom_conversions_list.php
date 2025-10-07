<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.uom.conversion.view');

$pdo = db();
$q = trim($_GET['q'] ?? '');

$sql = "
SELECT uc.id, fu.code AS from_code, tu.code AS to_code, uc.factor
FROM uom_conversions uc
JOIN uom fu ON fu.id = uc.from_uom_id
JOIN uom tu ON tu.id = uc.to_uom_id
";
$bind = [];
if ($q !== '') {
  $sql .= " WHERE fu.code LIKE ? OR tu.code LIKE ?";
  $bind = ["%$q%","%$q%"];
}
$sql .= " ORDER BY fu.code, tu.code";

$stmt = $pdo->prepare($sql);
$stmt->execute($bind);
$rows = $stmt->fetchAll();
$canManage = has_permission('master.uom.conversion.manage');
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>UOM Conversions</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="/styles.css" rel="stylesheet">
</head><body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="uom_list.php">UOM</a></li>
        <li class="breadcrumb-item active" aria-current="page">Conversions</li>
      </ol>
    </nav>
    <div class="d-flex gap-2">
      <form class="d-flex" method="get">
        <input class="form-control form-control-sm" name="q" placeholder="Search code" value="<?php echo htmlspecialchars($q);?>">
      </form>
      <?php if ($canManage): ?>
        <a class="btn btn-sm btn-primary" href="uom_conversions_form.php"><i class="bi bi-plus-lg"></i> Add</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>From</th><th>To</th><th>Factor</th><th class="text-end" style="width:160px;">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><code><?php echo htmlspecialchars($r['from_code']);?></code></td>
                <td><code><?php echo htmlspecialchars($r['to_code']);?></code></td>
                <td><?php echo rtrim(rtrim(number_format((float)$r['factor'], 10, '.', ''), '0'), '.'); ?></td>
                <td class="text-end">
                  <?php if ($canManage): ?>
                    <div class="btn-group btn-group-sm">
                      <a class="btn btn-outline-secondary" href="uom_conversions_form.php?id=<?php echo (int)$r['id'];?>" title="Edit"><i class="bi bi-pencil-square"></i></a>
                      <a class="btn btn-outline-danger" href="uom_conversions_delete.php?id=<?php echo (int)$r['id'];?>"
                         onclick="return confirm('Delete this conversion?');" title="Delete"><i class="bi bi-trash"></i></a>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; if (!$rows): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">No records.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="mt-3">
    <a class="btn btn-light" href="uom_list.php"><i class="bi bi-arrow-left"></i> Back to UOM</a>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>