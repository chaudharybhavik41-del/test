<?php
/** PATH: /public_html/items/items_form.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;

if ($is_edit) {
  require_permission('materials.item.view');
} else {
  require_permission('materials.item.manage');
}

// Load dropdowns
$cats = $pdo->query("SELECT id, name FROM material_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$subs = $pdo->query("SELECT id, category_id, name FROM material_subcategories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$uoms = $pdo->query("SELECT id, code, name FROM uom ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

// Load suggestions for makes (schema-safe: falls back if 'code' column missing)
$makesAll = [];
try {
  $stmt = $pdo->query("SELECT id, code, name FROM makes WHERE status='active' ORDER BY name");
  $makesAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  if ($e->getCode() === '42S22') { // unknown column
    $stmt = $pdo->query("SELECT id, name FROM makes WHERE status='active' ORDER BY name");
    $tmp = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tmp as $r) $makesAll[] = ['id'=>$r['id'], 'code'=>null, 'name'=>$r['name']];
  } else {
    throw $e;
  }
}

// Load item
$item = [
  'material_code' => '',
  'name' => '',
  'category_id' => '',
  'subcategory_id' => '',
  'uom_id' => '',
  'grade' => '',
  'thickness_mm' => '',
  'width_mm' => '',
  'length_mm' => '',
  'spec' => '',
  'status' => 'active',
];
$itemMakeNames = [];

if ($is_edit) {
  $st = $pdo->prepare("SELECT * FROM items WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) $item = array_merge($item, $row);

  $stm = $pdo->prepare("SELECT m.name FROM item_makes im JOIN makes m ON m.id=im.make_id WHERE im.item_id=? ORDER BY m.name");
  $stm->execute([$id]);
  $itemMakeNames = array_map(fn($r)=>$r['name'], $stm->fetchAll(PDO::FETCH_ASSOC));
}

// SAVE (unchanged business logic)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_permission('materials.item.manage');

  $material_code = trim($_POST['material_code'] ?? '');
  $name          = trim($_POST['name'] ?? '');
  $category_id   = (int)($_POST['category_id'] ?? 0);
  $subcategory_id= $_POST['subcategory_id'] !== '' ? (int)$_POST['subcategory_id'] : null;
  $uom_id        = (int)($_POST['uom_id'] ?? 0);
  $grade         = trim($_POST['grade'] ?? '');
  $thickness_mm  = $_POST['thickness_mm'] !== '' ? (float)$_POST['thickness_mm'] : null;
  $width_mm      = $_POST['width_mm'] !== '' ? (float)$_POST['width_mm'] : null;
  $length_mm     = $_POST['length_mm'] !== '' ? (float)$_POST['length_mm'] : null;
  $spec          = trim($_POST['spec'] ?? '');
  $status        = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

  // validate
  $errs = [];
  if ($material_code === '') $errs[] = 'Material code is required.';
  if ($name === '') $errs[] = 'Name is required.';
  if ($category_id <= 0) $errs[] = 'Category is required.';
  if ($uom_id <= 0) $errs[] = 'UOM is required.';

  if (!$errs) {
    if ($is_edit) {
      $sql = "UPDATE items SET material_code=?, name=?, category_id=?, subcategory_id=?, uom_id=?, grade=?, thickness_mm=?, width_mm=?, length_mm=?, spec=?, status=? WHERE id=?";
      $pdo->prepare($sql)->execute([$material_code,$name,$category_id,$subcategory_id,$uom_id,$grade,$thickness_mm,$width_mm,$length_mm,$spec,$status,$id]);
      $item_id = $id;
    } else {
      $sql = "INSERT INTO items (material_code, name, category_id, subcategory_id, uom_id, grade, thickness_mm, width_mm, length_mm, spec, status)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)";
      $pdo->prepare($sql)->execute([$material_code,$name,$category_id,$subcategory_id,$uom_id,$grade,$thickness_mm,$width_mm,$length_mm,$spec,$status]);
      $item_id = (int)$pdo->lastInsertId();
    }

    // ---- Make chips save (no dependency on makes.code column) ----
    $tokens = array_filter(array_map('trim', $_POST['make_tokens'] ?? []));
    $pdo->prepare("DELETE FROM item_makes WHERE item_id=?")->execute([$item_id]);

    if ($tokens) {
      $hasCode = false;
      try {
        $pdo->query("SELECT code FROM makes LIMIT 0"); // test presence
        $hasCode = true;
      } catch (PDOException $e) { if ($e->getCode() !== '42S22') throw $e; }

      $findByName = $pdo->prepare("SELECT id FROM makes WHERE name=? LIMIT 1");
      $findByCode = $hasCode ? $pdo->prepare("SELECT id FROM makes WHERE code=? LIMIT 1") : null;
      $insMake    = $hasCode
        ? $pdo->prepare("INSERT INTO makes (code,name,status) VALUES (?,?, 'active')")
        : $pdo->prepare("INSERT INTO makes (name,status) VALUES (?, 'active')");
      $insLink    = $pdo->prepare("INSERT IGNORE INTO item_makes (item_id, make_id) VALUES (?,?)");

      $makeCodeFrom = function(string $name): string {
        $code = preg_replace('/[^A-Z0-9-]+/u', '', strtoupper(str_replace(' ', '-', $name)));
        $code = trim($code, '-');
        return $code !== '' ? $code : strtoupper(bin2hex(random_bytes(2)));
      };

      foreach ($tokens as $t) {
        $makeId = null;

        $findByName->execute([$t]);
        $r = $findByName->fetch(PDO::FETCH_ASSOC);
        if ($r) {
          $makeId = (int)$r['id'];
        } else {
          if ($hasCode && $findByCode) {
            $findByCode->execute([$t]);
            $r = $findByCode->fetch(PDO::FETCH_ASSOC);
            if ($r) $makeId = (int)$r['id'];
          }
          if (!$makeId) {
            if ($hasCode) {
              $base = $makeCodeFrom($t);
              $try = $base; $n=1;
              while (true) {
                $chk = $pdo->prepare("SELECT 1 FROM makes WHERE code=? LIMIT 1");
                $chk->execute([$try]);
                if (!$chk->fetchColumn()) break;
                $n++; $try = $base . '-' . $n;
              }
              $insMake->execute([$try, $t]); // (code, name)
            } else {
              $insMake->execute([$t]); // (name) only
            }
            $makeId = (int)$pdo->lastInsertId();
          }
        }
        if ($makeId) $insLink->execute([$item_id, $makeId]);
      }
    }
    // --------------------------------------------------------------

    header('Location: /items/items_list.php');
    exit;
  } else {
    $item = compact('material_code','name','category_id','subcategory_id','uom_id','grade','thickness_mm','width_mm','length_mm','spec','status');
    $itemMakeNames = $tokens ?? $itemMakeNames;
  }
}

include __DIR__ . '/../ui/layout_start.php';
?>
<!-- Page header bar -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="/dashboard.php">Dashboard</a></li>
      <li class="breadcrumb-item"><a href="/items/items_list.php">Items</a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= $is_edit ? 'Edit' : 'Add' ?></li>
    </ol>
  </nav>
  <div class="d-flex gap-2">
    <a class="btn btn-light btn-sm" href="/items/items_list.php"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<?php if (!empty($errs)): ?>
  <div class="alert alert-danger shadow-sm">
    <div class="fw-semibold mb-1">Please fix the following:</div>
    <ul class="mb-0"><?php foreach ($errs as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="card shadow-sm p-3">
  <div class="row g-3">
    <div class="col-12">
      <h2 class="h6 text-uppercase text-muted mb-2">Basic</h2>
    </div>

    <div class="col-md-3">
      <label class="form-label">Material Code <span class="text-danger">*</span></label>
      <input name="material_code" class="form-control" value="<?= htmlspecialchars((string)$item['material_code']) ?>" required>
      <div class="form-text">Use your code pattern (e.g., CAT-SUB-YYYY-SEQ4).</div>
    </div>
    <div class="col-md-5">
      <label class="form-label">Name <span class="text-danger">*</span></label>
      <input name="name" class="form-control" value="<?= htmlspecialchars((string)$item['name']) ?>" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">UOM <span class="text-danger">*</span></label>
      <select name="uom_id" class="form-select" required>
        <option value="">--</option>
        <?php foreach ($uoms as $u): ?>
          <option value="<?=$u['id']?>" <?= (int)$item['uom_id']===(int)$u['id']?'selected':'' ?>>
            <?= htmlspecialchars($u['code']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <option value="active"   <?= $item['status']==='active'?'selected':'' ?>>Active</option>
        <option value="inactive" <?= $item['status']==='inactive'?'selected':'' ?>>Inactive</option>
      </select>
    </div>

    <div class="col-12 mt-2">
      <h2 class="h6 text-uppercase text-muted mb-2">Classification</h2>
    </div>

    <div class="col-md-3">
      <label class="form-label">Category <span class="text-danger">*</span></label>
      <select name="category_id" class="form-select" required>
        <option value="">--</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?=$c['id']?>" <?= (int)$item['category_id']===(int)$c['id']?'selected':'' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Subcategory</label>
      <select name="subcategory_id" class="form-select">
        <option value="">--</option>
        <?php foreach ($subs as $s): ?>
          <option data-cat="<?=$s['category_id']?>" value="<?=$s['id']?>" <?= (int)$item['subcategory_id']===(int)$s['id']?'selected':'' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Filtered by selected category.</div>
    </div>

    <div class="col-12 mt-2">
      <h2 class="h6 text-uppercase text-muted mb-2">Material Specs</h2>
    </div>

    <div class="col-md-2">
      <label class="form-label">Grade</label>
      <input name="grade" class="form-control" value="<?= htmlspecialchars((string)$item['grade']) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Thickness (mm)</label>
      <input name="thickness_mm" type="number" step="0.01" class="form-control" value="<?= htmlspecialchars((string)$item['thickness_mm']) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Width (mm)</label>
      <input name="width_mm" type="number" step="0.01" class="form-control" value="<?= htmlspecialchars((string)$item['width_mm']) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Length (mm)</label>
      <input name="length_mm" type="number" step="0.01" class="form-control" value="<?= htmlspecialchars((string)$item['length_mm']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Spec</label>
      <input name="spec" class="form-control" value="<?= htmlspecialchars((string)$item['spec']) ?>">
    </div>

    <!-- TAG-STYLE MAKES -->
    <div class="col-12 mt-2">
      <h2 class="h6 text-uppercase text-muted mb-2">Preferred Makes</h2>
    </div>
    <div class="col-md-8">
      <label class="form-label">Brands / Makes</label>
      <div id="makeChips" class="form-control d-flex flex-wrap gap-2" style="min-height:42px;padding:.375rem .75rem;">
        <input id="makeInput" class="border-0 flex-grow-1" placeholder="Type a make and press Enter" list="makeList" style="min-width:200px;outline:none;">
      </div>
      <datalist id="makeList">
        <?php foreach ($makesAll as $m): ?>
          <?php if (!empty($m['name'])): ?>
            <option value="<?= htmlspecialchars($m['name']) ?>"></option>
          <?php endif; ?>
          <?php if (!empty($m['code'])): ?>
            <option value="<?= htmlspecialchars($m['code']) ?>"></option>
          <?php endif; ?>
        <?php endforeach; ?>
      </datalist>
      <div class="form-text">Add multiple brands. Press <kbd>Enter</kbd> after each one.</div>
      <div id="makeHiddenBin"></div>
    </div>
    <!-- /TAG-STYLE MAKES -->

  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i> Save</button>
    <a class="btn btn-light" href="/items/items_list.php"><i class="bi bi-arrow-left"></i> Back to list</a>
  </div>
</form>

<style>
.chip{display:inline-flex;align-items:center;gap:.5rem;padding:.25rem .5rem;border-radius:999px;background:#e9ecef;font-size:.9rem}
.chip .btn-close{width:.6rem;height:.6rem}
</style>

<script>
(function(){
  // Subcategory filter by category
  const catSel = document.querySelector('select[name="category_id"]');
  const subSel = document.querySelector('select[name="subcategory_id"]');
  const allSubs = Array.from(subSel.querySelectorAll('option'));
  function filterSubs(){
    const c = catSel.value;
    subSel.value = '';
    subSel.querySelectorAll('option').forEach(o => o.style.display='');
    if (!c) return;
    allSubs.forEach(o=>{
      if (!o.value) return;
      if (o.dataset.cat !== c) o.style.display='none';
    });
  }
  if (catSel && subSel) {
    catSel.addEventListener('change', filterSubs);
    filterSubs();
  }

  // Make chips
  const makeChips = document.getElementById('makeChips');
  const makeInput = document.getElementById('makeInput');
  const hiddenBin = document.getElementById('makeHiddenBin');

  const preselected = <?php echo json_encode($itemMakeNames, JSON_UNESCAPED_UNICODE); ?>;
  const slug = s => s.trim().replace(/\s+/g,' ').replace(/[^\p{L}\p{N}\- ]/gu,'').trim();

  function addToken(raw){
    const val = slug(raw);
    if (!val) return;
    const existing = [...hiddenBin.querySelectorAll('input[name="make_tokens[]"]')].map(i=>i.value.toLowerCase());
    if (existing.includes(val.toLowerCase())) return;

    const hid = document.createElement('input');
    hid.type='hidden'; hid.name='make_tokens[]'; hid.value=val;
    hiddenBin.appendChild(hid);

    const chip = document.createElement('span');
    chip.className='chip';
    chip.textContent=val+' ';
    const close=document.createElement('button');
    close.type='button'; close.className='btn-close'; close.ariaLabel='Remove';
    close.addEventListener('click', ()=>{ hid.remove(); chip.remove(); });
    chip.appendChild(close);
    makeChips.insertBefore(chip, makeInput);
  }

  makeInput.addEventListener('keydown', (e)=>{
    if ((e.key==='Enter'||e.key===','||e.key==='Tab') && makeInput.value.trim()!==''){
      e.preventDefault();
      addToken(makeInput.value);
      makeInput.value='';
    } else if (e.key==='Backspace' && makeInput.value===''){
      const chips = makeChips.querySelectorAll('.chip');
      if (chips.length) chips[chips.length-1].querySelector('.btn-close').click();
    }
  });
  makeInput.addEventListener('paste', (e)=>{
    const t = (e.clipboardData||window.clipboardData).getData('text');
    if (t && t.includes(',')){
      e.preventDefault();
      t.split(',').map(s=>s.trim()).filter(Boolean).forEach(addToken);
    }
  });
  preselected.forEach(addToken);
})();
</script>

<?php include __DIR__ . '/../ui/layout_end.php'; ?>