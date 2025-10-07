<?php
/** PATH: /public_html/identity/provision_queue.php */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rbac.php';

require_login();
require_permission('iam.provision.view');

$UI_PATH     = dirname(__DIR__) . '/ui';
$PAGE_TITLE  = 'Provisioning Approvals';
$ACTIVE_MENU = 'identity.provision_queue';

include $UI_PATH . '/layout_start.php';
?>
<div class="container-fluid">
  <main class="px-3 py-3">
    <h1 class="h4 mb-3">Provisioning Queue</h1>

    <div id="queue" class="mb-3"></div>

    <script>
      async function loadQueue() {
        const r = await fetch('../api/iam_approvals.php?action=list');
        const data = await r.json();
        const el = document.getElementById('queue');
        if (!data.items || !data.items.length) { el.innerHTML = '<div class="alert alert-info">No pending approvals.</div>'; return; }
        let html = '<div class="table-responsive"><table class="table table-sm align-middle">';
        html += '<thead class="table-light"><tr><th>#</th><th>Employee</th><th>Proposed Roles</th><th>Step</th><th>Actions</th></tr></thead><tbody>';
        for (const it of data.items) {
          let roles = [];
          try { roles = JSON.parse(it.proposed_roles || '[]'); } catch(e) {}
          html += `<tr>
            <td>${it.approval_id}</td>
            <td>${it.employee_id}</td>
            <td><code>${roles.join(', ')}</code></td>
            <td>${it.step_no}</td>
            <td>
              <button class="btn btn-success btn-sm" onclick="decide(${it.approval_id}, 'approved')">Approve</button>
              <button class="btn btn-outline-danger btn-sm" onclick="decide(${it.approval_id}, 'rejected')">Reject</button>
            </td>
          </tr>`;
        }
        html += '</tbody></table></div>';
        el.innerHTML = html;
      }
      async function decide(approvalId, decision) {
        const r = await fetch('../api/iam_approvals.php?action=decide', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: `approval_id=${encodeURIComponent(approvalId)}&decision=${encodeURIComponent(decision)}`
        });
        const data = await r.json();
        if (data.error) { alert(data.error); return; }
        loadQueue();
      }
      loadQueue();
    </script>
  </main>
</div>
<?php include $UI_PATH . '/layout_end.php'; ?>
