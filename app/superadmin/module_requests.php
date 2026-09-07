<?php
/**
 * app/superadmin/module_requests.php — the approval queue for tenant_module_
 * control_plan.md Phase C: a company asks for a module, a superadmin decides.
 *
 * Control database only, same discipline as plans.php/tenants.php — this page
 * never opens a tenant's own database, so it does not resolve "requested by"
 * to a real name (that would need one). The company + the note they left is
 * enough context to decide.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/module_requests.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me      = currentSuperadmin();
$pending = [];
$history = [];
$setup   = false;
$error   = null;

try {
    if (!moduleRequestsTableReady()) {
        $setup = true;
    } else {
        $pending = listPendingModuleRequests();
        $history = getControlPdo()->query("
            SELECT r.*, t.company_name, t.subdomain
            FROM feature_upgrade_requests r
            JOIN tenants t ON t.id = r.tenant_id
            WHERE r.status != 'pending'
            ORDER BY r.decided_at DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('superadmin module_requests: ' . $e->getMessage());
    $error = 'Module requests could not be read.';
}

$registry = bmsFeatureRegistry();
/** "Projects (also grants Procurement, Warehouses)" — computed fresh, never stored. */
$describeRequest = function (string $featureKey) use ($registry): string {
    $label = $registry[$featureKey]['label'] ?? $featureKey;
    $extra = array_values(array_filter(
        featureDependencyClosure([$featureKey]),
        fn($k) => $k !== $featureKey
    ));
    if (!$extra) return $label;
    $extraLabels = array_map(fn($k) => $registry[$k]['label'] ?? $k, $extra);
    return $label . ' (also grants ' . implode(', ', $extraLabels) . ')';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Module Requests | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .detail-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .detail-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    .request-row:hover { background: #f8f9fb; }
</style>
</head>
<body>

<?php renderSuperadminHeader('module-requests', $me); ?>

<div class="container-fluid p-3">
    <h6 class="mb-3"><i class="bi bi-inbox text-primary me-1"></i> Module Requests</h6>

    <?php if ($setup): ?>
        <div class="card detail-card">
            <div class="card-body">
                <p class="mb-2">Module requests are not set up on this server yet.</p>
                <p class="text-muted small mb-0">Run this once on this host, then reload:</p>
                <pre class="mt-2 mb-0 p-2 rounded" style="background:#e7f0ff;border:1px solid #b6ccfe"><code>php scripts/setup_control_db.php</code></pre>
            </div>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?= safe_output($error, '') ?></div>
    <?php else: ?>

    <div class="card detail-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Pending <span class="badge bg-danger ms-1"><?= count($pending) ?></span></span>
        </div>
        <div class="card-body p-0">
            <?php if (!$pending): ?>
                <div class="text-center text-muted py-5"><i class="bi bi-check2-circle fs-3 d-block mb-2"></i>Nothing waiting on a decision.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Company</th>
                            <th>Module requested</th>
                            <th>Note</th>
                            <th>Requested</th>
                            <th class="text-end">Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $r): ?>
                        <tr class="request-row" data-id="<?= (int)$r['id'] ?>">
                            <td>
                                <div class="fw-semibold"><?= safe_output($r['company_name'], '') ?></div>
                                <div class="text-muted small"><?= safe_output($r['subdomain'], '') ?></div>
                            </td>
                            <td><?= safe_output($describeRequest($r['feature_key']), '') ?></td>
                            <td class="text-muted small" style="max-width:260px;white-space:normal;"><?= safe_output($r['note'], '—') ?></td>
                            <td class="text-muted small"><?= safe_output($r['created_at'], '') ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-primary btn-approve" data-id="<?= (int)$r['id'] ?>"
                                        data-desc="<?= safe_output($describeRequest($r['feature_key']), '') ?>">
                                    <i class="bi bi-check-circle me-1"></i>Approve
                                </button>
                                <button class="btn btn-sm btn-outline-danger btn-decline" data-id="<?= (int)$r['id'] ?>">
                                    <i class="bi bi-x-circle me-1"></i>Decline
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card detail-card">
        <div class="card-header">Recent decisions</div>
        <div class="card-body p-0">
            <?php if (!$history): ?>
                <div class="text-center text-muted py-4">No decisions recorded yet.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Company</th><th>Module</th><th>Decision</th><th>Reason</th><th>When</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $r): ?>
                        <tr>
                            <td><?= safe_output($r['company_name'], '') ?></td>
                            <td><?= safe_output($registry[$r['feature_key']]['label'] ?? $r['feature_key'], '') ?></td>
                            <td>
                                <?php if ($r['status'] === 'approved'): ?>
                                    <span class="badge" style="background:#0d6efd;color:#fff;">Approved</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Declined</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= safe_output($r['decision_note'], '—') ?></td>
                            <td class="text-muted small"><?= safe_output($r['decided_at'], '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';
$.ajaxSetup({ headers: { 'X-CSRF-Token': SA_CSRF_TOKEN } });

function decide(id, action, extra) {
    $.ajax({
        url: '/actions/superadmin_module_requests.php',
        method: 'POST',
        dataType: 'json',
        data: Object.assign({ action: action, id: id, _csrf: SA_CSRF_TOKEN }, extra || {})
    }).done(function (res) {
        if (res && res.success) {
            Swal.fire({ icon: 'success', title: res.message, timer: 2000, showConfirmButton: false })
                .then(() => window.location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Something went wrong.' });
        }
    }).fail(function (xhr) {
        let msg = 'Something went wrong.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Error', text: msg });
    });
}

$('.btn-approve').on('click', function () {
    const id = $(this).data('id');
    const desc = $(this).data('desc');
    Swal.fire({
        title: 'Approve this request?',
        html: 'This grants: <strong>' + desc + '</strong>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Approve',
        confirmButtonColor: '#0d6efd'
    }).then(r => { if (r.isConfirmed) decide(id, 'approve'); });
});

$('.btn-decline').on('click', function () {
    const id = $(this).data('id');
    Swal.fire({
        title: 'Decline this request',
        input: 'textarea',
        inputPlaceholder: 'Reason (shown to the company)...',
        showCancelButton: true,
        confirmButtonText: 'Decline',
        confirmButtonColor: '#dc3545'
    }).then(r => { if (r.isConfirmed) decide(id, 'decline', { decision_note: r.value || '' }); });
});
</script>
</body>
</html>
