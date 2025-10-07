<?php
declare(strict_types=1);

/**
 * Current holder (contractor) if machine is issued.
 * Returns: ['alloc_id','alloc_code','contractor_id','contractor_code','contractor_name','alloc_date','expected_return']
 */
function machine_current_holder(PDO $pdo, int $machine_id): ?array {
  $sql = "SELECT ma.id AS alloc_id, ma.alloc_code, ma.contractor_id,
                 p.code AS contractor_code, p.name AS contractor_name,
                 ma.alloc_date, ma.expected_return
          FROM machine_allocations ma
          JOIN parties p ON p.id = ma.contractor_id
          WHERE ma.machine_id = ? AND ma.status = 'issued'
          ORDER BY ma.alloc_date DESC LIMIT 1";
  $st = $pdo->prepare($sql); $st->execute([$machine_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/** Common badge HTML (Bootstrap 5) */
function machine_holder_badge(?array $holder): string {
  if (!$holder) return '<span class="badge bg-success">Available</span>';
  $txt = htmlspecialchars($holder['contractor_code'].' — '.$holder['contractor_name']);
  $date= htmlspecialchars((string)$holder['alloc_date']);
  return '<span class="badge bg-warning text-dark" title="Issued on '.$date.'">Held by '.$txt.'</span>';
}

/** Small helper to render an Issue/Return button set */
function machine_issue_return_buttons(int $machine_id, ?array $holder): string {
  if ($holder) {
    return '<a class="btn btn-success btn-sm" href="/maintenance_alloc/allocations_return.php?id='.$holder['alloc_id'].'">Return</a>';
  }
  return '<a class="btn btn-success btn-sm" href="/maintenance_alloc/allocations_form.php?machine_id='.$machine_id.'">Issue</a>';
}