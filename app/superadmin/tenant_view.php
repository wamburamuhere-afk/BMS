<?php
/**
 * app/superadmin/tenant_view.php — one tenant's detail and lifecycle actions.
 *
 * Shows only what the control database holds about the account: identity,
 * routing, status and history. It never connects to the tenant's own database,
 * so none of that company's business data can be displayed here — and the
 * tenant's database password is never even selected (see getTenant()).
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me = currentSuperadmin();
$id = (int)($_GET['id'] ?? 0);

$tenant = null;
$log    = [];
$error  = null;

try {
    $tenant = $id > 0 ? getTenant($id) : null;
    if ($tenant) $log = tenantAdminLog($id, 50);
} catch (Throwable $e) {
    error_log('superadmin tenant_view: ' . $e->getMessage());
    $error = 'The tenant registry could not be read.';
}

function svBadge(string $status): string
{
    $map = [
        'active'    => ['#0d6efd', '#fff'],
        'trial'     => ['#cfe2ff', '#084298'],
        'suspended' => ['#6c757d', '#fff'],
        'deleted'   => ['#dc3545', '#fff'],
    ];
    [$bg, $fg] = $map[$status] ?? ['#e9ecef', '#495057'];
    return '<span class="badge" style="background:' . $bg . ';color:' . $fg . '">'
         . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= $tenant ? safe_output($tenant['company_name'], '') : 'Tenant' ?> | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .page-header { position: sticky; top: 0; z-index: 1020; background: #fff; border-bottom: 1px solid #e9ecef; }
    .detail-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .detail-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    dt { font-weight: 500; color: #6c757d; font-size: .875rem; }
    dd { margin-bottom: .75rem; }
</style>
</head>
<body>

<div class="page-header px-3 py-2 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
        <a href="tenants.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-left"></i></a>
        <div>
            <div class="fw-bold">Tenant details</div>
            <small class="text-muted">Signed in as <?= safe_output($me['name'] ?? '', '') ?></small>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="profile.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-person-gear me-1"></i> My Account</a>
        <a href="logout.php" class="btn btn-sm btn-secondary"><i class="bi bi-box-arrow-right me-1"></i> Sign out</a>
    </div>
</div>

<div class="container-fluid p-3">

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($error, '') ?></div>
<?php elseif (!$tenant): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-question-circle fs-1 d-block mb-2"></i>
        No such tenant.
        <div class="mt-3"><a href="tenants.php" class="btn btn-sm btn-primary">Back to tenants</a></div>
    </div>
<?php else: ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card detail-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-building text-primary me-1"></i><?= safe_output($tenant['company_name'], '') ?></span>
                    <?= svBadge((string)$tenant['status']) ?>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <div class="col-sm-6">
                            <dt>Web address</dt>
                            <dd><code><?= safe_output($tenant['subdomain'], '') ?></code></dd>
                            <dt>Owner</dt>
                            <dd><?= safe_output($tenant['owner_email'], '') ?></dd>
                            <dt>Plan</dt>
                            <dd><?= safe_output($tenant['plan'] ?? '', '—') ?></dd>
                        </div>
                        <div class="col-sm-6">
                            <dt>Database</dt>
                            <dd><code><?= safe_output($tenant['db_name'], '—') ?></code></dd>
                            <dt>Database user</dt>
                            <dd><code><?= safe_output($tenant['db_username'], '—') ?></code></dd>
                            <dt>Host</dt>
                            <dd><?= safe_output($tenant['db_host'], '—') ?></dd>
                        </div>
                        <div class="col-sm-4">
                            <dt>Registered</dt>
                            <dd><?= safe_output($tenant['created_at'], '—') ?></dd>
                        </div>
                        <div class="col-sm-4">
                            <dt>Activated</dt>
                            <dd><?= safe_output($tenant['activated_at'] ?? '', '—') ?></dd>
                        </div>
                        <div class="col-sm-4">
                            <dt>Suspended</dt>
                            <dd><?= safe_output($tenant['suspended_at'] ?? '', '—') ?></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card detail-card">
                <div class="card-header"><i class="bi bi-sliders text-primary me-1"></i> Lifecycle</div>
                <div class="card-body">
                <?php if ($tenant['status'] === 'deleted'): ?>
                    <p class="text-muted mb-0">
                        <i class="bi bi-slash-circle me-1"></i>
                        This tenant has been deleted. Its database and database user were removed, so it
                        cannot be reactivated. The record is kept so the subdomain stays claimed and the
                        history below remains meaningful.
                    </p>
                <?php else: ?>
                    <?php if ($tenant['status'] === 'suspended'): ?>
                        <p class="small text-muted">Suspended tenants are locked out of their system, but no data has been deleted.</p>
                        <button class="btn btn-primary w-100 mb-2" onclick="doActivate()">
                            <i class="bi bi-play-circle me-1"></i> Reactivate
                        </button>
                    <?php else: ?>
                        <p class="small text-muted">Suspending locks this company out immediately. No data is deleted, and no other tenant is affected.</p>
                        <button class="btn btn-primary w-100 mb-2" onclick="doSuspend()">
                            <i class="bi bi-pause-circle me-1"></i> Suspend
                        </button>
                    <?php endif; ?>
                    <hr>
                    <p class="small text-danger mb-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Deleting destroys this company's entire database permanently. There is no undo.
                    </p>
                    <button class="btn btn-outline-danger w-100" onclick="doDelete()">
                        <i class="bi bi-trash me-1"></i> Delete permanently
                    </button>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card detail-card">
                <div class="card-header"><i class="bi bi-clock-history text-primary me-1"></i> History</div>
                <div class="card-body p-0">
                    <?php if (!$log): ?>
                        <div class="p-3 text-muted small">No lifecycle actions recorded.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead><tr><th>When</th><th>Action</th><th>By</th><th>Detail</th></tr></thead>
                            <tbody>
                            <?php foreach ($log as $l): ?>
                                <tr>
                                    <td class="text-nowrap"><?= safe_output($l['created_at'], '') ?></td>
                                    <td><code><?= safe_output($l['action'], '') ?></code></td>
                                    <td><?= safe_output($l['actor_email'] ?? '', 'system') ?></td>
                                    <td class="small"><?= safe_output($l['detail'] ?? '', '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Named SA_CSRF_TOKEN, not CSRF_TOKEN: header.php is the canonical
// declarer of the latter, and tests/test_csrf_token_redeclaration_cli.php
// forbids any page under app/ from shadowing it. These pages never include
// header.php, but keeping the invariant absolute is safer than exempting them.
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';
const TENANT_ID  = <?= (int)($tenant['id'] ?? 0) ?>;
const TENANT_NAME = <?= json_encode((string)($tenant['company_name'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
$.ajaxSetup({ headers: { 'X-CSRF-Token': SA_CSRF_TOKEN } });

function postAction(data, title, redirect) {
    $.ajax({
        url: '/actions/superadmin_tenant_action.php',
        method: 'POST', dataType: 'json',
        data: Object.assign({ _csrf: SA_CSRF_TOKEN, tenant_id: TENANT_ID }, data)
    }).done(function (res) {
        if (res && res.success) {
            Swal.fire({ icon: 'success', title: title, text: res.message, timer: 1800, showConfirmButton: false });
            setTimeout(function () { window.location.href = redirect || window.location.href; }, 1800);
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Action failed.' });
        }
    }).fail(function (xhr) {
        let msg = 'Action failed.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Error', text: msg });
    });
}

function doSuspend() {
    Swal.fire({
        title: 'Suspend this tenant?',
        text: 'They will be locked out immediately. No data is deleted, and no other tenant is affected.',
        icon: 'warning', input: 'text', inputPlaceholder: 'Reason (optional)',
        showCancelButton: true, confirmButtonColor: '#0d6efd', confirmButtonText: 'Suspend'
    }).then(r => { if (r.isConfirmed) postAction({ action: 'suspend', reason: r.value || '' }, 'Suspended'); });
}

function doActivate() {
    Swal.fire({
        title: 'Reactivate this tenant?', text: 'Their system becomes available again immediately.',
        icon: 'question', showCancelButton: true, confirmButtonColor: '#0d6efd', confirmButtonText: 'Activate'
    }).then(r => { if (r.isConfirmed) postAction({ action: 'activate' }, 'Reactivated'); });
}

function doDelete() {
    Swal.fire({
        title: 'Delete this tenant permanently?',
        html: 'This <strong>destroys their entire database</strong> — every invoice, ledger entry, '
            + 'employee record and document. <strong>It cannot be undone.</strong><br><br>'
            + 'Type <code>' + $('<div>').text(TENANT_NAME).html() + '</code> to confirm:',
        icon: 'warning', input: 'text', inputPlaceholder: 'Company name',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, delete permanently',
        preConfirm: function (v) {
            if ((v || '').trim() !== TENANT_NAME.trim()) {
                Swal.showValidationMessage('The name does not match.');
                return false;
            }
            return v;
        }
    }).then(r => { if (r.isConfirmed) postAction({ action: 'delete', confirm_name: r.value }, 'Deleted', 'tenants.php'); });
}
</script>
</body>
</html>
