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
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me = currentSuperadmin();
$id = (int)($_GET['id'] ?? 0);

$tenant = null;
$log    = [];
$error  = null;

$features      = [];
$featuresSetup = false;   // true = tables not created on this host yet

try {
    $tenant = $id > 0 ? getTenant($id) : null;
    if ($tenant) $log = tenantAdminLog($id, 50);
} catch (Throwable $e) {
    error_log('superadmin tenant_view: ' . $e->getMessage());
    $error = 'The tenant registry could not be read.';
}

// Entitlements are read SEPARATELY and never inside the block above. They were,
// and it was wrong: on a host where scripts/setup_control_db.php had not been
// re-run since Phase 11, the missing `features` table threw, and this whole page
// — identity, lifecycle, Suspend/Activate/Delete, history — collapsed into
// "The tenant registry could not be read." A module panel that cannot load must
// cost the operator the module panel, nothing else.
if ($tenant && $tenant['status'] !== 'deleted') {
    try {
        if (featureTablesReady()) {
            $features = tenantFeatureMatrix($id);
        } else {
            $featuresSetup = true;
        }
    } catch (Throwable $e) {
        error_log('superadmin tenant_view (features): ' . $e->getMessage());
        $featuresSetup = true;
    }
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
    .feature-row { background: #e7f0ff; border: 1px solid #b6ccfe; }
    .feature-row .form-check-input:disabled { opacity: .45; }
    .detail-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .detail-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    dt { font-weight: 500; color: #6c757d; font-size: .875rem; }
    dd { margin-bottom: .75rem; }
</style>
</head>
<body>

<?php renderSuperadminHeader('tenants', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= saUrl('tenants') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-left"></i></a>
        <h5 class="mb-0 fw-bold text-muted">Tenant details</h5>
    </div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($error, '') ?></div>
<?php elseif (!$tenant): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-question-circle fs-1 d-block mb-2"></i>
        No such tenant.
        <div class="mt-3"><a href="<?= saUrl('tenants') ?>" class="btn btn-sm btn-primary">Back to tenants</a></div>
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

        <?php if ($tenant['status'] !== 'deleted'): ?>
        <div class="col-12">
            <div class="card detail-card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-speedometer text-primary me-1"></i> Usage &amp; Limits</span>
                    <button class="btn btn-sm btn-outline-primary" onclick="checkUsage()" id="btnCheckUsage">
                        <i class="bi bi-arrow-clockwise me-1"></i> Check current usage
                    </button>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Caps how many active staff accounts and how much uploaded storage this company
                        may use. Leave a field blank for unlimited. "Current usage" is read on demand —
                        it is not kept on this page automatically.
                    </p>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Max active users</label>
                            <input type="text" inputmode="numeric" class="form-control" id="f-max-users"
                                   placeholder="Unlimited"
                                   value="<?= $tenant['max_users'] !== null ? (int)$tenant['max_users'] : '' ?>">
                            <div class="form-text" id="usage-users">Current usage not checked yet.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold">Max storage (MB)</label>
                            <input type="text" inputmode="numeric" class="form-control" id="f-max-storage"
                                   placeholder="Unlimited"
                                   value="<?= $tenant['max_storage_mb'] !== null ? (int)$tenant['max_storage_mb'] : '' ?>">
                            <div class="form-text" id="usage-storage">Current usage not checked yet.</div>
                        </div>
                    </div>

                    <button class="btn btn-primary mt-3" onclick="saveQuotas()" id="btnSaveQuotas">
                        <i class="bi bi-save me-1"></i> Save limits
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($featuresSetup): ?>
        <div class="col-12">
            <div class="card detail-card">
                <div class="card-header"><i class="bi bi-grid text-primary me-1"></i> Modules</div>
                <div class="card-body">
                    <p class="mb-2">Module control is not set up on this server yet.</p>
                    <p class="text-muted small mb-0">
                        The control database is created by an operator step, never by a deploy
                        migration, so its new tables do not appear on their own. Run this once on
                        this host and reload:
                    </p>
                    <pre class="mt-2 mb-0 p-2 rounded" style="background:#e7f0ff;border:1px solid #b6ccfe"><code>php scripts/setup_control_db.php</code></pre>
                    <p class="text-muted small mt-2 mb-0">
                        Nothing is broken in the meantime — until it is run, every tenant simply has
                        access to every module, exactly as before.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($features): ?>
        <div class="col-12">
            <div class="card detail-card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="bi bi-grid text-primary me-1"></i> Modules</span>
                    <button class="btn btn-sm btn-primary" onclick="saveFeatures()" id="btnSaveFeatures">
                        <i class="bi bi-save me-1"></i> Save modules
                    </button>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        What this company's subscription includes. Switching a module off hides it
                        completely — from the menu, from direct links and from its API — for
                        <strong>every user in this company, including their own administrator</strong>.
                        Their data is never deleted; switching it back on restores access unchanged.
                        No other tenant is affected.
                    </p>

                    <div class="row g-2">
                    <?php foreach ($features as $f): ?>
                        <div class="col-12 col-md-6">
                            <div class="feature-row d-flex align-items-start gap-2 p-2 rounded">
                                <div class="form-check form-switch mt-1">
                                    <input class="form-check-input feature-switch" type="checkbox"
                                           role="switch"
                                           id="f_<?= safe_output($f['key'], '') ?>"
                                           data-key="<?= safe_output($f['key'], '') ?>"
                                           <?= $f['effective'] ? 'checked' : '' ?>
                                           <?= $f['available'] ? '' : 'disabled' ?>>
                                </div>
                                <div class="flex-grow-1">
                                    <label class="form-check-label fw-semibold" for="f_<?= safe_output($f['key'], '') ?>">
                                        <?= safe_output($f['label'], '') ?>
                                    </label>
                                    <div class="text-muted" style="font-size:.78rem">
                                        <?= safe_output($f['description'] ?? '', '') ?>
                                    </div>
                                    <div style="font-size:.72rem">
                                        <?php if (!$f['available']): ?>
                                            <span class="badge" style="background:#6c757d;color:#fff">
                                                <?= safe_output($f['reason'], '') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted"><?= safe_output($f['reason'], '') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <div class="small text-muted mt-3">
                        <i class="bi bi-info-circle me-1"></i>
                        A module marked <em>Removed platform-wide</em> cannot be switched on here —
                        change that on the <a href="<?= saUrl('features') ?>">Modules (platform)</a> page, which
                        affects every tenant at once.
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

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

function saveFeatures() {
    // Every switch is posted, not just the changed ones, so an unchecked box
    // arrives as an explicit 0 rather than simply being absent. Disabled
    // switches (removed platform-wide) are posted as-is; the server ignores a
    // request to enable something the platform has withdrawn.
    const features = {};
    document.querySelectorAll('.feature-switch').forEach(function (el) {
        features[el.dataset.key] = el.checked ? 1 : 0;
    });

    const off = Array.from(document.querySelectorAll('.feature-switch'))
        .filter(function (el) { return !el.checked && !el.disabled; })
        .map(function (el) { return el.closest('.feature-row').querySelector('label').textContent.trim(); });

    Swal.fire({
        title: 'Save module access?',
        html: off.length
            ? 'These will be hidden from <strong>every user</strong> in this company, its own '
              + 'administrator included:<br><br><strong>' + off.map(function (t) {
                  return $('<div>').text(t).html();
                }).join('<br>') + '</strong><br><br>No data is deleted.'
            : 'This company will have access to every module.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: 'Save'
    }).then(function (r) {
        if (!r.isConfirmed) return;

        const btn  = $('#btnSaveFeatures');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

        $.ajax({
            url: '/actions/superadmin_tenant_features.php',
            method: 'POST', dataType: 'json',
            data: { _csrf: SA_CSRF_TOKEN, tenant_id: TENANT_ID, features: features }
        }).done(function (res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 2400, showConfirmButton: false });
                setTimeout(function () { window.location.reload(); }, 2400);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not save.' });
            }
        }).fail(function (xhr) {
            let msg = 'Could not save.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
        }).always(function () {
            btn.prop('disabled', false).html(orig);
        });
    });
}

function saveQuotas() {
    const maxUsers   = $('#f-max-users').val().trim();
    const maxStorage = $('#f-max-storage').val().trim();

    const btn  = $('#btnSaveQuotas');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    $.ajax({
        url: '/actions/superadmin_tenant_quotas.php',
        method: 'POST', dataType: 'json',
        data: { _csrf: SA_CSRF_TOKEN, tenant_id: TENANT_ID, max_users: maxUsers, max_storage_mb: maxStorage }
    }).done(function (res) {
        if (res && res.success) {
            Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 2200, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not save.' });
        }
    }).fail(function (xhr) {
        let msg = 'Could not save.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Error', text: msg });
    }).always(function () {
        btn.prop('disabled', false).html(orig);
    });
}

function checkUsage() {
    // On demand, deliberately — see tenantUsageSnapshotFor()'s docblock for why
    // this is the one thing on this page that briefly opens the tenant's own
    // database, and why it only ever happens on this explicit click.
    const btn  = $('#btnCheckUsage');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Checking...');

    $.ajax({
        url: '/actions/superadmin_tenant_usage.php',
        method: 'POST', dataType: 'json',
        data: { _csrf: SA_CSRF_TOKEN, tenant_id: TENANT_ID }
    }).done(function (res) {
        if (res && res.success) {
            $('#usage-users').text(res.active_users + ' active user' + (res.active_users === 1 ? '' : 's') + ' right now.');
            $('#usage-storage').text(res.storage_used_mb + ' MB used right now.');
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not read current usage.' });
        }
    }).fail(function (xhr) {
        let msg = 'Could not read current usage.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Error', text: msg });
    }).always(function () {
        btn.prop('disabled', false).html(orig);
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
