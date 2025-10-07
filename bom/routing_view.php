<?php
// /routing_view.php  — shows routing grouped by component, with Scope, Assembly & FIT‑UP badges
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

$bom_id = isset($_GET['bom_id']) ? (int)$_GET['bom_id'] : 0;
if ($bom_id <= 0) { http_response_code(400); echo "Missing bom_id"; exit; }

$st = $pdo->prepare("SELECT id, bom_no, status FROM bom WHERE id=?");
$st->execute([$bom_id]);
$bom = $st->fetch(PDO::FETCH_ASSOC);
if (!$bom) { echo "BOM not found"; exit; }

$sql = "SELECT 
          bc.id AS comp_id, bc.sr_no, bc.line_code, bc.segment_idx, bc.description,
          bc.length_mm, bc.width_mm, bc.thickness_mm,
          ro.id AS ro_id, ro.seq_no, ro.inspection_gate,
          ro.applies_to, ro.assembly_id, ro.is_merge_op,
          ro.default_plan_prod_qty, ro.default_prod_uom_id,
          ro.default_plan_comm_qty, ro.default_comm_uom_id,
          p.code AS p_code, p.name AS p_name,
          wc.code AS wc_code, wc.name AS wc_name,
          ba.code AS a_code, ba.name AS a_name,
          up.code AS prod_uom, uc.code AS comm_uom
        FROM routing_ops ro
        JOIN bom_components bc ON bc.id = ro.bom_component_id
        JOIN processes p ON p.id = ro.process_id
        JOIN work_centers wc ON wc.id = ro.work_center_id
        LEFT JOIN bom_assemblies ba ON ba.id = ro.assembly_id
        LEFT JOIN uom up ON up.id = ro.default_prod_uom_id
        LEFT JOIN uom uc ON uc.id = ro.default_comm_uom_id
        WHERE bc.bom_id=?
        ORDER BY bc.line_code, bc.segment_idx, bc.sr_no, ro.seq_no, ro.id";
$ops = $pdo->prepare($sql);
$ops->execute([$bom_id]);

$byComp = [];
foreach ($ops as $r) $byComp[$r['comp_id']][] = $r;

// Load component headers
$comps = [];
$st = $pdo->prepare("SELECT id, sr_no, line_code, segment_idx, description, length_mm, width_mm, thickness_mm
                     FROM bom_components WHERE bom_id=? ORDER BY line_code, segment_idx, sr_no, id");
$st->execute([$bom_id]);
foreach ($st as $c) $comps[$c['id']] = $c;

function h($s){ return htmlspecialchars((string)$s); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Routing — View (BOM <?=h($bom['bom_no'])?>)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/assets/bootstrap.min.css" rel="stylesheet">
  <style>.badge-fit{background:#f7c948;color:#332e1f}</style>
</head>
<body class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="mb-0">Routing — BOM <?=h($bom['bom_no'])?> <small class="text-muted">(<?=h($bom['status'])?>)</small></h5>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="routing_form.php?bom_id=<?=$bom_id?>">Edit</a>
      <a class="btn btn-sm btn-primary" href="pwo_form.php?bom_id=<?=$bom_id?>">Generate PWOs</a>
    </div>
  </div>

  <?php foreach ($comps as $cid => $c): ?>
    <div class="card mb-3">
      <div class="card-header">
        <strong>#<?=$c['sr_no']?> <?=h($c['description'])?></strong>
        <?php if ($c['line_code']): ?><span class="badge text-bg-secondary">Line: <?=h($c['line_code'])?></span><?php endif; ?>
        <?php if ($c['segment_idx']): ?><span class="badge text-bg-secondary">Seg: <?=h($c['segment_idx'])?></span><?php endif; ?>
        <span class="ms-2 small text-muted">(L <?=h($c['length_mm'])?> × W <?=h($c['width_mm'])?> × T <?=h($c['thickness_mm'])?> mm)</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:70px;">Seq</th>
                <th style="width:210px;">Process</th>
                <th style="width:210px;">Work Center</th>
                <th style="width:180px;">Scope</th>
                <th style="width:220px;">Assembly</th>
                <th style="width:110px;">Insp Gate</th>
                <th style="width:210px;">Plan (Prod)</th>
                <th style="width:210px;">Plan (Comm)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($byComp[$cid] ?? [] as $r): ?>
                <tr>
                  <td><?= (int)$r['seq_no'] ?></td>
                  <td><?=h($r['p_code'])?> — <?=h($r['p_name'])?></td>
                  <td><?=h($r['wc_code'])?> — <?=h($r['wc_name'])?></td>
                  <td>
                    <?php if (($r['applies_to'] ?? 'component') === 'assembly'): ?>
                      <span class="badge text-bg-primary">Assembly</span>
                    <?php else: ?>
                      <span class="badge text-bg-secondary">Component</span>
                    <?php endif; ?>
                    <?php if (!empty($r['is_merge_op'])): ?>
                      <span class="badge badge-fit">FIT‑UP</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($r['applies_to']==='assembly' && $r['a_code']): ?>
                      <?=h($r['a_code'])?> — <?=h($r['a_name'])?>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= !empty($r['inspection_gate']) ? '<span class="badge text-bg-warning">QC</span>' : '<span class="text-muted">—</span>' ?></td>
                  <td>
                    <?php
                      $v = $r['default_plan_prod_qty']; $u = $r['prod_uom'];
                      echo ($v!==null && $u) ? h($v.' '.$u) : '<span class="text-muted">—</span>';
                    ?>
                  </td>
                  <td>
                    <?php
                      $v = $r['default_plan_comm_qty']; $u = $r['comm_uom'];
                      echo ($v!==null && $u) ? h($v.' '.$u) : '<span class="text-muted">—</span>';
                    ?>
                  </td>
                </tr>
              <?php endforeach; if (empty($byComp[$cid])): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No steps.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</body>
</html>
