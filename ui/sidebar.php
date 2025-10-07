<?php
declare(strict_types=1);
/** PATH: /public_html/ui/sidebar.php
 * Sidebar navigation (Bootstrap 5) — compact with collapsible groups.
 * Groups: Core, Masters, Purchase, Stores, Production & Planning, Machinery, Maintenance, Quality, Accounts, Audit, Reports
 * Note: Pure UI; no RBAC checks (keep kernel untouched).
 */

$REQ_URI = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('nav_is_active')) {
  /** Return 'active' if any of $needles appear in current URI */
  function nav_is_active(array $needles, string $haystack): string {
    foreach ($needles as $n) { if ($n !== '' && str_contains($haystack, $n)) return 'active'; }
    return '';
  }
}
if (!function_exists('collapse_show')) {
  /** Return 'show' if any of $needles appear in current URI (auto-expand a group) */
  function collapse_show(array $needles, string $haystack): string {
    foreach ($needles as $n) { if ($n !== '' && str_contains($haystack, $n)) return 'show'; }
    return '';
  }
}
?>
<nav class="px-2 py-3">
  <ul class="nav nav-pills flex-column gap-1 w-100">

    <!-- Core -->
    <li class="nav-item text-uppercase small text-muted px-2 mt-1">Core</li>
    <li class="nav-item">
      <a class="nav-link d-flex align-items-center <?= nav_is_active(['/dashboard.php'], $REQ_URI) ?>" href="/dashboard.php">
        <i class="bi bi-speedometer2 me-2"></i> Dashboard
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link d-flex align-items-center <?= nav_is_active(['/notifications/'], $REQ_URI) ?>" href="/notifications/center.php">
        <i class="bi bi-bell me-2"></i> Notifications
      </a>
    </li>

    <!-- Masters (collapsible) -->
    <?php $masters_needles = ['/identity/', '/uom/', '/material/', '/items/', '/parties/', '/locations/', '/projects/']; ?>
    <li class="nav-item text-uppercase small text-muted px-2 mt-3">Masters</li>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($masters_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navMasters" aria-expanded="<?= collapse_show($masters_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-sliders me-2"></i> Masters
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navMasters" class="collapse <?= collapse_show($masters_needles, $REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <li><a class="nav-link <?= nav_is_active(['/identity/'], $REQ_URI) ?>" href="/identity/users_list.php"><i class="bi bi-people me-2"></i> Users & Roles</a></li>
          <li><a class="nav-link <?= nav_is_active(['/uom/uom_list.php'], $REQ_URI) ?>" href="/uom/uom_list.php"><i class="bi bi-rulers me-2"></i> UOM</a></li>
          <li><a class="nav-link <?= nav_is_active(['/uom/uom_conversions_list.php'], $REQ_URI) ?>" href="/uom/uom_conversions_list.php"><i class="bi bi-shuffle me-2"></i> UOM Conversions</a></li>
          <li><a class="nav-link <?= nav_is_active(['/material/'], $REQ_URI) ?>" href="/material/index.php"><i class="bi bi-diagram-2 me-2"></i> Material Taxonomy</a></li>
          <li><a class="nav-link <?= nav_is_active(['/items/'], $REQ_URI) ?>" href="/items/items_list.php"><i class="bi bi-box-seam me-2"></i> Items</a></li>
          <li><a class="nav-link <?= nav_is_active(['/parties/'], $REQ_URI) ?>" href="/parties/parties_list.php"><i class="bi bi-building me-2"></i> Parties</a></li>
          <li><a class="nav-link <?= nav_is_active(['/locations/'], $REQ_URI) ?>" href="/locations/warehouses_list.php"><i class="bi bi-geo-alt me-2"></i> Locations/Warehouses</a></li>
          <li><a class="nav-link <?= nav_is_active(['/projects/'], $REQ_URI) ?>" href="/projects/projects_list.php"><i class="bi bi-kanban me-2"></i> Projects</a></li>
        </ul>
      </div>
    </li>

    <!-- Operations -->
    <li class="nav-item text-uppercase small text-muted px-2 mt-3">Operations</li>

    <!-- Purchase (collapsible) -->
    <?php $purchase_needles = ['/purchase/']; ?>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($purchase_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navPurchase" aria-expanded="<?= collapse_show($purchase_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-bag-check me-2"></i> Purchase
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navPurchase" class="collapse <?= collapse_show($purchase_needles, $REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <li><a class="nav-link <?= nav_is_active(['/purchase/indents_'], $REQ_URI) ?>" href="/purchase/indents_list.php"><i class="bi bi-card-list me-2"></i> Indents</a></li>
          <li><a class="nav-link <?= nav_is_active(['/purchase/rfq_'], $REQ_URI) ?>" href="/purchase/rfq_list.php"><i class="bi bi-envelope-paper me-2"></i> RFQ</a></li>
          <li><a class="nav-link <?= nav_is_active(['/purchase/quotes_','/purchase/quote'], $REQ_URI) ?>" href="/purchase/quotes_list.php"><i class="bi bi-currency-rupee me-2"></i> Quotes</a></li>
          <li><a class="nav-link <?= nav_is_active(['/purchase/po_'], $REQ_URI) ?>" href="/purchase/po_list.php"><i class="bi bi-receipt me-2"></i> Purchase Orders</a></li>
        </ul>
      </div>
    </li>

    <!-- Stores (collapsible) -->
    <?php $stores_needles = ['/stores/']; ?>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($stores_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navStores" aria-expanded="<?= collapse_show($stores_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-boxes me-2"></i> Stores
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navStores" class="collapse <?= collapse_show($stores_needles, $REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <li><a class="nav-link <?= nav_is_active(['/stores/grn_'], $REQ_URI) ?>" href="/stores/grn_list.php"><i class="bi bi-box-arrow-in-down-left me-2"></i> GRN (Receipts)</a></li>
          <li><a class="nav-link <?= nav_is_active(['/stores/issue_'], $REQ_URI) ?>" href="/stores/issues_list.php"><i class="bi bi-box-arrow-up-right me-2"></i> Issues/Returns</a></li>
          <li><a class="nav-link <?= nav_is_active(['/stores/transfer_'], $REQ_URI) ?>" href="/stores/transfer_list.php"><i class="bi bi-arrow-left-right me-2"></i> Stock Transfer</a></li>
          <li><a class="nav-link <?= nav_is_active(['/stores/minmax_report.php'], $REQ_URI) ?>" href="/stores/minmax_report.php"><i class="bi bi-graph-down-arrow me-2"></i> Min/Max Report</a></li>
          <li><a class="nav-link <?= nav_is_active(['/stores/purchase_advice_list.php'], $REQ_URI) ?>" href="/stores/purchase_advice_list.php"><i class="bi bi-clipboard2-plus me-2"></i> Purchase Advice</a></li>
          <li><a class="nav-link <?= nav_is_active(['/stores/stock_adjust_form.php'], $REQ_URI) ?>" href="/stores/stock_adjust_form.php"><i class="bi bi-sliders2 me-2"></i> Stock Adjust</a></li>
          <li><a class="nav-link <?= nav_is_active(['/stores/gatepass_list.php'], $REQ_URI) ?>" href="/stores/gatepass_list.php"><i class="bi bi-card-heading me-2"></i> Gate Pass</a></li>
        </ul>
      </div>
    </li>

    <!-- Production & Planning (collapsible) -->
    <?php $pp_needles = ['/purchase/bom_list.php','/workorders/','/processes/','/workcenters/']; ?>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($pp_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navProdPlan" aria-expanded="<?= collapse_show($pp_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-diagram-3 me-2"></i> Production &amp; Planning
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navProdPlan" class="collapse <?= collapse_show($pp_needles, $REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <!-- Corrected BOM link (as requested) -->
          <li><a class="nav-link <?= nav_is_active(['/purchase/bom_list.php'], $REQ_URI) ?>" href="/purchase/bom_list.php"><i class="bi bi-diagram-3 me-2"></i> BOMs</a></li>
          <!-- Removed routing links per instruction -->
          <li><a class="nav-link <?= nav_is_active(['/workorders/pwo_list.php'], $REQ_URI) ?>" href="/workorders/pwo_list.php"><i class="bi bi-gear me-2"></i> Work Orders (PWO)</a></li>
          <li><a class="nav-link <?= nav_is_active(['/processes/processes_list.php'], $REQ_URI) ?>" href="/processes/processes_list.php"><i class="bi bi-diagram-2 me-2"></i> Processes</a></li>
          <li><a class="nav-link <?= nav_is_active(['/workcenters/workcenters_list.php'], $REQ_URI) ?>" href="/workcenters/workcenters_list.php"><i class="bi bi-hdd-stack me-2"></i> Work Centers</a></li>
        </ul>
      </div>
    </li>

    <!-- Machinery (collapsible) -->
    <?php $mach_needles = ['/machines/','/maintenance_alloc/']; ?>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($mach_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navMachinery" aria-expanded="<?= collapse_show($mach_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-wrench-adjustable-circle me-2"></i> Machinery
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navMachinery" class="collapse <?= collapse_show($mach_needles, $REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <li><a class="nav-link <?= nav_is_active(['/machines/machines_list.php'], $REQ_URI) ?>" href="/machines/machines_list.php"><i class="bi bi-hdd-network me-2"></i> Machines</a></li>
          <li><a class="nav-link <?= nav_is_active(['/machines/categories_list.php'], $REQ_URI) ?>" href="/machines/categories_list.php"><i class="bi bi-ui-checks-grid me-2"></i> Machine Categories</a></li>
          <li><a class="nav-link <?= nav_is_active(['/maintenance_alloc/allocations_list.php'], $REQ_URI) ?>" href="/maintenance_alloc/allocations_list.php"><i class="bi bi-people-gear me-2"></i> Allocations</a></li>
        </ul>
      </div>
    </li>

    <!-- Maintenance (collapsible) -->
    <?php $maint_needles = ['/maintenance/wo_list.php','/maintenance/schedule.php','/maintenance/reports/contractor_costs.php']; ?>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($maint_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navMaintenance" aria-expanded="<?= collapse_show($maint_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-tools me-2"></i> Maintenance
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navMaintenance" class="collapse <?= collapse_show($maint_needles, $REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <li><a class="nav-link <?= nav_is_active(['/maintenance/wo_list.php'], $REQ_URI) ?>" href="/maintenance/wo_list.php"><i class="bi bi-clipboard2-check me-2"></i> Work Orders</a></li>
          <li><a class="nav-link <?= nav_is_active(['/maintenance/schedule.php'], $REQ_URI) ?>" href="/maintenance/schedule.php"><i class="bi bi-calendar2-check me-2"></i> Schedule</a></li>
          <li><a class="nav-link <?= nav_is_active(['/maintenance/reports/contractor_costs.php'], $REQ_URI) ?>" href="/maintenance/reports/contractor_costs.php"><i class="bi bi-currency-rupee me-2"></i> Contractor Costs</a></li>
        </ul>
      </div>
    </li>

    <!-- Quality (collapsible kept for future; remove if not needed) -->
    <?php $qc_needles = ['/qc/']; ?>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($qc_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navQuality" aria-expanded="<?= collapse_show($qc_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-shield-check me-2"></i> Quality / ITP
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navQuality" class="collapse <?= collapse_show($qc_needles, $REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <li><a class="nav-link <?= nav_is_active(['/qc/inspections_'], $REQ_URI) ?>" href="/qc/inspections_list.php"><i class="bi bi-clipboard-check me-2"></i> Inspections</a></li>
          <li><a class="nav-link <?= nav_is_active(['/qc/dossier_'], $REQ_URI) ?>" href="/qc/dossier_list.php"><i class="bi bi-folder2-open me-2"></i> Dossiers</a></li>
        </ul>
      </div>
    </li>

    <!-- Accounts (collapsible) -->
    <?php $acct_needles = ['/accounts/journals_list.php','/accounts/ledger.php']; ?>
    <li class="nav-item text-uppercase small text-muted px-2 mt-3">Finance</li>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($acct_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navAccounts" aria-expanded="<?= collapse_show($acct_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-cash-coin me-2"></i> Accounts
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navAccounts" class="collapse <?= collapse_show($acct_needles, $REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <li><a class="nav-link <?= nav_is_active(['/accounts/journals_list.php'], $REQ_URI) ?>" href="/accounts/journals_list.php"><i class="bi bi-journal-text me-2"></i> Journals</a></li>
          <li><a class="nav-link <?= nav_is_active(['/accounts/ledger.php'], $REQ_URI) ?>" href="/accounts/ledger.php"><i class="bi bi-journal-richtext me-2"></i> Ledger</a></li>
        </ul>
      </div>
    </li>

    <!-- Audit -->
    <li class="nav-item">
      <a class="nav-link d-flex align-items-center <?= nav_is_active(['/audit/'], $REQ_URI) ?>" href="/audit/log_list.php">
        <i class="bi bi-activity me-2"></i> Audit Log
      </a>
    </li>

    <!-- Reports (collapsible) -->
    <?php $reports_needles = ['/reports/']; ?>
    <li class="nav-item text-uppercase small text-muted px-2 mt-3">Reports</li>
    <li class="nav-item">
      <button class="btn w-100 text-start nav-link d-flex align-items-center <?= nav_is_active($reports_needles, $REQ_URI) ?>"
              data-bs-toggle="collapse" data-bs-target="#navReports" aria-expanded="<?= collapse_show($reports_needles,$REQ_URI)?'true':'false' ?>">
        <i class="bi bi-graph-up me-2"></i> Reports
        <i class="bi bi-caret-down ms-auto"></i>
      </button>
      <div id="navReports" class="collapse <?= collapse_show($reports_needles,$REQ_URI) ?>">
        <ul class="nav flex-column ms-3 mt-1">
          <li><a class="nav-link <?= nav_is_active(['/reports/inventory/stock_summary.php'], $REQ_URI) ?>" href="/reports/inventory/stock_summary.php"><i class="bi bi-clipboard-data me-2"></i> Inventory Summary</a></li>
          <li><a class="nav-link <?= nav_is_active(['/reports/inventory/stock_ledger.php'], $REQ_URI) ?>" href="/reports/inventory/stock_ledger.php"><i class="bi bi-journal-check me-2"></i> Stock Ledger</a></li>
          <li><a class="nav-link <?= nav_is_active(['/reports/purchase/indent_register.php'], $REQ_URI) ?>" href="/reports/purchase/indent_register.php"><i class="bi bi-receipt me-2"></i> Purchase Register</a></li>
        </ul>
      </div>
    </li>

  </ul>
</nav>
