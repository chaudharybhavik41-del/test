<?php
require_once __DIR__ . '/../config.php';
require_login();
require_permission('master.uom.view');

$pdo = db();
$q   = trim($_GET['q'] ?? '');
$sql = "SELECT id, code, name, type, status FROM uom";
$bind = [];
if ($q !== '') {
  $sql .= " WHERE code LIKE ? OR name LIKE ?";
  $bind = ["%$q%", "%$q%"];
}
$sql .= " ORDER BY code ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($bind);
$rows = $stmt->fetchAll();
$canManage = has_permission('master.uom.manage');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>UOM — EMS Infra ERP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/styles.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?php echo app_url('dashboard.php');?>">EMS Infra ERP</a>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="<?php echo app_url('dashboard.php');?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
      <a class="btn btn-sm btn-outline-primary" href="uom_conversions_list.php"><i class="bi bi-shuffle"></i> Conversions</a>
      <?php if ($canManage): ?>
        <a class="btn btn-sm btn-primary" href="uom_form.php"><i class="bi bi-plus-circle"></i> New UOM</a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?php echo app_url('logout.php');?>"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
  </div>
</nav>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Units of Measure</h5>
    <form class="d-flex" method="get" action="">
      <input class="form-control form-control-sm me-2" name="q" placeholder="Search code or name" value="<?php echo htmlspecialchars($q);?>">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
    </form>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table align-middle table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:100px;">Code</th>
              <th>Name</th>
              <th style="width:120px;">Type</th>
              <th style="width:110px;">Status</th>
              <th style="width:150px;" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><code><?php echo htmlspecialchars($r['code']);?></code></td>
                <td><?php echo htmlspecialchars($r['name']);?></td>
                <td><span class="badge text-bg-secondary"><?php echo htmlspecialchars($r['type']);?></span></td>
                <td>
                  <span class="badge <?php echo $r['status']==='active'?'text-bg-success':'text-bg-secondary';?>">
                    <?php echo htmlspecialchars(ucfirst($r['status']));?>
                  </span>
                </td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm" role="group">
                    <a class="btn btn-outline-secondary" href="uom_form.php?id=<?php echo (int)$r['id'];?>"
                       title="Edit"><i class="bi bi-pencil-square"></i></a>
                    <?php if ($canManage): ?>
                      <a class="btn btn-outline-danger" href="uom_delete.php?id=<?php echo (int)$r['id'];?>"
                         onclick="return confirm('Delete this UOM?');" title="Delete"><i class="bi bi-trash"></i></a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; if (!$rows): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No records.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>