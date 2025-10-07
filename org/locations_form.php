<?php
/** PATH: /public_html/org/locations_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('org.location.manage');

$pdo = db();

/** Detect columns */
$cols = $pdo->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_COLUMN);
$hasCode    = in_array('code', $cols, true);
$hasStatus  = in_array('status', $cols, true);
$hasCity    = in_array('city', $cols, true);
$hasState   = in_array('state', $cols, true);
$hasCountry = in_array('country', $cols, true);
$hasPincode = in_array('pincode', $cols, true);
$hasAddress = in_array('address', $cols, true);
$hasAddr1   = in_array('address1', $cols, true);
$hasAddr2   = in_array('address2', $cols, true);
$hasLat     = in_array('latitude', $cols, true);
$hasLng     = in_array('longitude', $cols, true);
$hasCreated = in_array('created_at', $cols, true);
$hasUpdated = in_array('updated_at', $cols, true);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $id > 0;

$loc = ['name' => ''];
if ($hasCode)    $loc['code']    = '';
if ($hasStatus)  $loc['status']  = 'active';
if ($hasCity)    $loc['city']    = '';
if ($hasState)   $loc['state']   = '';
if ($hasCountry) $loc['country'] = '';
if ($hasPincode) $loc['pincode'] = '';
if ($hasAddress) $loc['address'] = '';
if ($hasAddr1)   $loc['address1']= '';
if ($hasAddr2)   $loc['address2']= '';
if ($hasLat)     $loc['latitude']= null;
if ($hasLng)     $loc['longitude']= null;

if ($editing) {
  $sel = ['id','name'];
  foreach (['code','status','city','state','country','pincode','address','address1','address2','latitude','longitude'] as $c) {
    if (in_array($c, $cols, true)) $sel[] = $c;
  }
  $st = $pdo->prepare("SELECT ".implode(',', $sel)." FROM locations WHERE id = ?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) { http_response_code(404); exit('Location not found.'); }
  $loc = array_merge($loc, $row);
}

function slugify_code(string $s): string {
  $s = trim($s);
  $s = str_replace(['/', '-', ' '], '_', $s);
  $s = preg_replace('/[^a-zA-Z0-9_]/', '', $s);
  $s = preg_replace('/_+/', '_', $s);
  return strtolower($s ?: 'loc');
}

$errors = [];
$okMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // if (function_exists('csrf_validate_or_die')) csrf_validate_or_die();

  $name = trim((string)($_POST['name'] ?? ''));
  if ($name === '') $errors[] = 'Name is required.';

  $code    = $hasCode ? trim((string)($_POST['code'] ?? '')) : null;
  $status  = $hasStatus ? (($_POST['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive') : null;
  $city    = $hasCity ? trim((string)($_POST['city'] ?? '')) : null;
  $state   = $hasState ? trim((string)($_POST['state'] ?? '')) : null;
  $country = $hasCountry ? trim((string)($_POST['country'] ?? '')) : null;
  $pincode = $hasPincode ? trim((string)($_POST['pincode'] ?? '')) : null;
  $address = $hasAddress ? trim((string)($_POST['address'] ?? '')) : null;
  $address1= $hasAddr1 ? trim((string)($_POST['address1'] ?? '')) : null;
  $address2= $hasAddr2 ? trim((string)($_POST['address2'] ?? '')) : null;
  $lat     = $hasLat ? ($_POST['latitude'] === '' ? null : (float)$_POST['latitude']) : null;
  $lng     = $hasLng ? ($_POST['longitude'] === '' ? null : (float)$_POST['longitude']) : null;

  if ($hasCode) {
    if ($code === '') $code = slugify_code($name);
    $sqlU = "SELECT id FROM locations WHERE code = ?";
    $parU = [$code];
    if ($editing) { $sqlU .= " AND id <> ?"; $parU[] = $id; }
    $stU = $pdo->prepare($sqlU);
    $stU->execute($parU);
    if ($stU->fetch(PDO::FETCH_ASSOC)) $errors[] = 'Code already exists. Choose another.';
  }

  if (!$errors) {
    if ($editing) {
      $sets = ['name = ?']; $vals = [$name];
      foreach ([
        'code'=>$code,'status'=>$status,'city'=>$city,'state'=>$state,'country'=>$country,
        'pincode'=>$pincode,'address'=>$address,'address1'=>$address1,'address2'=>$address2,
        'latitude'=>$lat,'longitude'=>$lng
      ] as $c=>$v) {
        if (in_array($c, $cols, true)) { $sets[] = "$c = ?"; $vals[] = $v; }
      }
      if ($hasUpdated) $sets[] = 'updated_at = NOW()';
      $vals[] = $id;

      $sql = "UPDATE locations SET ".implode(', ', $sets)." WHERE id = ?";
      $pdo->prepare($sql)->execute($vals);
      $okMsg = 'Location updated.';
    } else {
      $insCols = ['name']; $qs=['?']; $vals = [$name];
      foreach ([
        'code'=>$code,'status'=>$status,'city'=>$city,'state'=>$state,'country'=>$country,
        'pincode'=>$pincode,'address'=>$address,'address1'=>$address1,'address2'=>$address2,
        'latitude'=>$lat,'longitude'=>$lng
      ] as $c=>$v) {
        if (in_array($c, $cols, true)) { $insCols[] = $c; $qs[]='?'; $vals[]=$v; }
      }
      if ($hasCreated){ $insCols[]='created_at'; $qs[]='NOW()'; }
      if ($hasUpdated){ $insCols[]='updated_at'; $qs[]='NOW()'; }

      $sql = "INSERT INTO locations (".implode(',', $insCols).") VALUES (".implode(',', $qs).")";
      $pdo->prepare($sql)->execute($vals);
      $id = (int)$pdo->lastInsertId();
      $editing = true;
      $okMsg = 'Location created.';
    }

    // reload
    $st = $pdo->prepare("SELECT * FROM locations WHERE id = ?");
    $st->execute([$id]);
    $loc = $st->fetch(PDO::FETCH_ASSOC) ?: $loc;
  }
}

$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = $editing ? 'Edit Location' : 'Add Location';
$ACTIVE_MENU = 'org.locations';

require_once $UI_PATH . '/init.php';
require_once $UI_PATH . '/layout_start.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><?= $editing ? 'Edit Location' : 'Add Location' ?></h1>
  <a class="btn btn-outline-secondary" href="/org/locations_list.php">Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php elseif ($okMsg): ?>
  <div class="alert alert-success"><?= htmlspecialchars($okMsg) ?></div>
<?php endif; ?>

<form method="post" class="row g-3">
  <div class="col-md-6">
    <label class="form-label">Name</label>
    <input name="name" class="form-control" required value="<?= htmlspecialchars((string)$loc['name']) ?>">
  </div>

  <?php if ($hasCode): ?>
  <div class="col-md-6">
    <label class="form-label">Code (unique)</label>
    <input name="code" class="form-control" value="<?= htmlspecialchars((string)($loc['code'] ?? '')) ?>">
    <div class="form-text">Auto-generated from name if left blank.</div>
  </div>
  <?php endif; ?>

  <?php if ($hasStatus): ?>
  <div class="col-md-4">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <option value="active"   <?= ($loc['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= ($loc['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
    </select>
  </div>
  <?php endif; ?>

  <?php if ($hasCity): ?>
  <div class="col-md-4"><label class="form-label">City</label>
    <input name="city" class="form-control" value="<?= htmlspecialchars((string)($loc['city'] ?? '')) ?>">
  </div><?php endif; ?>

  <?php if ($hasState): ?>
  <div class="col-md-4"><label class="form-label">State</label>
    <input name="state" class="form-control" value="<?= htmlspecialchars((string)($loc['state'] ?? '')) ?>">
  </div><?php endif; ?>

  <?php if ($hasCountry): ?>
  <div class="col-md-4"><label class="form-label">Country</label>
    <input name="country" class="form-control" value="<?= htmlspecialchars((string)($loc['country'] ?? '')) ?>">
  </div><?php endif; ?>

  <?php if ($hasPincode): ?>
  <div class="col-md-4"><label class="form-label">Pincode</label>
    <input name="pincode" class="form-control" value="<?= htmlspecialchars((string)($loc['pincode'] ?? '')) ?>">
  </div><?php endif; ?>

  <?php if ($hasAddress): ?>
  <div class="col-12"><label class="form-label">Address</label>
    <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars((string)($loc['address'] ?? '')) ?></textarea>
  </div><?php endif; ?>

  <?php if ($hasAddr1 || $hasAddr2): ?>
    <?php if ($hasAddr1): ?>
    <div class="col-md-6"><label class="form-label">Address 1</label>
      <input name="address1" class="form-control" value="<?= htmlspecialchars((string)($loc['address1'] ?? '')) ?>">
    </div><?php endif; ?>
    <?php if ($hasAddr2): ?>
    <div class="col-md-6"><label class="form-label">Address 2</label>
      <input name="address2" class="form-control" value="<?= htmlspecialchars((string)($loc['address2'] ?? '')) ?>">
    </div><?php endif; ?>
  <?php endif; ?>

  <?php if ($hasLat || $hasLng): ?>
  <div class="col-md-3"><label class="form-label">Latitude</label>
    <input type="number" step="any" name="latitude" class="form-control" value="<?= htmlspecialchars((string)($loc['latitude'] ?? '')) ?>">
  </div>
  <div class="col-md-3"><label class="form-label">Longitude</label>
    <input type="number" step="any" name="longitude" class="form-control" value="<?= htmlspecialchars((string)($loc['longitude'] ?? '')) ?>">
  </div>
  <?php endif; ?>

  <div class="col-12">
    <button class="btn btn-primary" type="submit"><?= $editing ? 'Save Changes' : 'Create Location' ?></button>
  </div>
</form>

<?php require_once $UI_PATH . '/layout_end.php'; ?>
