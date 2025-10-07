<?php
// --- Action buttons for list rows (view/edit/delete etc.) ---
function ui_row_actions(array $opts): string {
  // $opts = ['view'=>url|null, 'edit'=>url|null, 'delete'=>url|null, 'extra'=>[['icon'=>'download','label'=>'Download','href'=>'/x']]]
  $html = '<div class="btn-group btn-group-sm" role="group">';
  if (!empty($opts['view']))   $html .= '<a class="btn btn-light" href="'.htmlspecialchars($opts['view']).'" title="View"><i class="bi bi-eye"></i></a>';
  if (!empty($opts['edit']))   $html .= '<a class="btn btn-light" href="'.htmlspecialchars($opts['edit']).'" title="Edit"><i class="bi bi-pencil-square"></i></a>';
  if (!empty($opts['delete'])) $html .= '<a class="btn btn-light" href="'.htmlspecialchars($opts['delete']).'" title="Delete" onclick="return confirm(\'Delete this record?\')"><i class="bi bi-trash"></i></a>';
  if (!empty($opts['extra']) && is_array($opts['extra'])) {
    foreach ($opts['extra'] as $x) {
      $icon  = htmlspecialchars($x['icon']  ?? 'link-45deg');
      $label = htmlspecialchars($x['label'] ?? 'Action');
      $href  = htmlspecialchars($x['href']  ?? '#');
      $html .= '<a class="btn btn-light" href="'.$href.'" title="'.$label.'"><i class="bi bi-'.$icon.'"></i></a>';
    }
  }
  $html .= '</div>';
  return $html;
}

// --- Status pill (badge) ---
function ui_status(string $status): string {
  $s = strtolower(trim($status));
  $map = [
    'active'   => 'bg-success-subtle text-success-emphasis border',
    'enabled'  => 'bg-success-subtle text-success-emphasis border',
    'draft'    => 'bg-secondary-subtle text-secondary-emphasis border',
    'pending'  => 'bg-warning-subtle text-warning-emphasis border',
    'paused'   => 'bg-warning-subtle text-warning-emphasis border',
    'rejected' => 'bg-danger-subtle text-danger-emphasis border',
    'disabled' => 'bg-dark-subtle text-dark-emphasis border',
  ];
  $cls = $map[$s] ?? 'bg-primary-subtle text-primary-emphasis border';
  return '<span class="badge '.$cls.'">'.htmlspecialchars(ucfirst($s)).'</span>';
}