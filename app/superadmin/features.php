<?php
/**
 * app/superadmin/features.php — the platform-wide module catalogue.
 *
 * Two decisions live here, and neither belongs on a single tenant's page:
 *
 *   is_available    — remove a module from EVERY tenant at once, overriding any
 *                     individual grant. For a module that is not ready, is being
 *                     retired, or has just been found to be broken.
 *   default_enabled — what a NEWLY registered company starts with. Existing
 *                     tenants that were left at the default follow it, which is
 *                     exactly why setTenantFeatures() deletes redundant override
 *                     rows instead of pinning everyone the first time Save is hit.
 *
 * Control database only. Like every other page in this panel, it never opens a
 * tenant's own database.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me       = currentSuperadmin();
$features = [];
$error    = null;
$setup    = false;   // true = the control tables do not exist on this host yet

try {
    // Distinguish "not set up here yet" from "something is actually wrong".
    // The control database is created by an operator step and never by a deploy
    // migration, so this page WILL be reached on a host where Phase 11's tables
    // do not exist. Reporting that as a generic read failure leaves the operator
    // with nothing to act on, which is exactly what happened on demo.
    if (!featureTablesReady()) {
        $setup = true;
    } else {
        $features = platformFeatures();
    }
} catch (Throwable $e) {
    error_log('superadmin features: ' . $e->getMessage());
    $error = 'The module catalogue could not be read.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Modules | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .detail-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .detail-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    @media (max-width: 767px) { #tableWrap { display: none; } }
    @media (min-width: 768px) { #cardView { display: none; } }
</style>
</head>
<body>

<?php renderSuperadminHeader('features', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= saUrl('tenants') ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-left"></i></a>
        <h5 class="mb-0 fw-bold"><i class="bi bi-grid text-primary me-1"></i> Modules <span class="text-muted small fw-normal">— platform-wide</span></h5>
    </div>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($error, '') ?></div>
<?php elseif ($setup): ?>
    <div class="card detail-card">
        <div class="card-header"><i class="bi bi-tools text-primary me-1"></i> One setup step remains on this server</div>
        <div class="card-body">
            <p>Module control is not set up on this server yet, so there is nothing to show here.</p>
            <p class="text-muted small">
                The control database is created by an operator step, never by a deploy migration —
                a control-database migration once halted an entire release on a host whose
                application user lacks <code>CREATE</code>. Its new tables therefore do not appear
                on their own. Run this once on this host, then reload this page:
            </p>
            <pre class="p-3 rounded mb-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><code>php scripts/setup_control_db.php</code></pre>
            <p class="text-muted small mb-0">
                It is idempotent, so running it again is always safe. Until it is run nothing is
                broken: every tenant simply has access to every module, exactly as before.
            </p>
        </div>
    </div>
<?php elseif (!$features): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
        No modules in the catalogue yet.
        <div class="small mt-2">Run <code>php scripts/setup_control_db.php</code> to seed it.</div>
    </div>
<?php else: ?>

    <div class="card detail-card">
        <div class="card-header"><i class="bi bi-grid text-primary me-1"></i> Module catalogue</div>
        <div class="card-body">
            <p class="text-muted small">
                <strong>Available</strong> off removes a module from every tenant at once — including
                any tenant it was specifically granted to. <strong>New-tenant default</strong> only
                affects companies registered from now on, plus existing tenants that were never given
                an explicit answer either way. Per-tenant grants live on each tenant's own page.
            </p>

            <div class="d-flex justify-content-end mb-2">
                <input type="search" id="modTblSearch" class="form-control form-control-sm w-auto" placeholder="Search…">
            </div>

            <div id="tableWrap" class="table-responsive">
                <table id="modulesTable" class="table table-sm align-middle" style="width:100%">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th class="text-center">Available</th>
                            <th class="text-center">New-tenant default</th>
                            <th class="text-end">Live tenants using it</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($features as $f): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= safe_output($f['label'], '') ?></div>
                                <div class="text-muted" style="font-size:.75rem"><code><?= safe_output($f['feature_key'], '') ?></code> — <?= safe_output($f['description'] ?? '', '') ?></div>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           data-key="<?= safe_output($f['feature_key'], '') ?>"
                                           data-field="is_available"
                                           data-label="<?= safe_output($f['label'], '') ?>"
                                           data-using="<?= (int)$f['tenants_using'] ?>"
                                           onchange="togglePlatform(this)"
                                           <?= (int)$f['is_available'] === 1 ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           data-key="<?= safe_output($f['feature_key'], '') ?>"
                                           data-field="default_enabled"
                                           data-label="<?= safe_output($f['label'], '') ?>"
                                           onchange="togglePlatform(this)"
                                           <?= (int)$f['default_enabled'] === 1 ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td class="text-end">
                                <?php if ((int)$f['is_available'] === 0): ?>
                                    <span class="badge" style="background:#6c757d;color:#fff">Removed</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#0d6efd;color:#fff">
                                        <?= (int)$f['tenants_using'] ?> of <?= (int)$f['tenants_live'] ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="cardView" class="row g-2">
            <?php foreach ($features as $f): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="fw-bold"><?= safe_output($f['label'], '') ?></div>
                                <?php if ((int)$f['is_available'] === 0): ?>
                                    <span class="badge" style="background:#6c757d;color:#fff">Removed</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#0d6efd;color:#fff"><?= (int)$f['tenants_using'] ?> of <?= (int)$f['tenants_live'] ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted" style="font-size:.78rem"><?= safe_output($f['description'] ?? '', '') ?></div>
                            <div class="d-flex gap-3 mt-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           data-key="<?= safe_output($f['feature_key'], '') ?>" data-field="is_available"
                                           data-label="<?= safe_output($f['label'], '') ?>" data-using="<?= (int)$f['tenants_using'] ?>"
                                           onchange="togglePlatform(this)" <?= (int)$f['is_available'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label small">Available</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           data-key="<?= safe_output($f['feature_key'], '') ?>" data-field="default_enabled"
                                           data-label="<?= safe_output($f['label'], '') ?>"
                                           onchange="togglePlatform(this)" <?= (int)$f['default_enabled'] === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label small">Default on</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Named SA_CSRF_TOKEN, not CSRF_TOKEN — see the note in tenants.php: no page
// under app/ may shadow header.php's declaration of the latter.
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';
$.ajaxSetup({ headers: { 'X-CSRF-Token': SA_CSRF_TOKEN } });

// Same DataTable shape as tenants.php on this same panel: no built-in search
// box (dom: 'rtip'), a plain external input wired to table.search() instead,
// and sorting disabled on the two switch columns and the trailing count column
// — none of those are meaningful to sort on, and a re-sort mid-toggle would
// move the row out from under the operator's cursor.
if (document.getElementById('modulesTable')) {
    const modTable = $('#modulesTable').DataTable({
        responsive: false,
        pageLength: 25,
        order: [[0, 'asc']],
        dom: 'rtip',
        columnDefs: [{ orderable: false, targets: [1, 2, 3] }],
        language: { emptyTable: 'No modules found.', zeroRecords: 'No matching modules.' }
    });
    $('#modTblSearch').on('keyup', function () { modTable.search(this.value).draw(); });
}

function togglePlatform(el) {
    const key    = el.dataset.key;
    const field  = el.dataset.field;
    const label  = el.dataset.label;
    const on     = el.checked;
    const using  = parseInt(el.dataset.using || '0', 10);

    // Removing a module everyone is currently using is the one genuinely
    // dangerous switch on this page, so it states the blast radius before doing
    // anything — and the switch is put back if the operator declines.
    let title = 'Apply this change?';
    let html  = '';
    if (field === 'is_available' && !on) {
        title = 'Remove "' + label + '" platform-wide?';
        html  = using > 0
            ? 'It is currently in use by <strong>' + using + '</strong> live tenant'
              + (using === 1 ? '' : 's') + '. They will lose access immediately, '
              + 'even any tenant it was specifically granted to.<br><br>No data is deleted.'
            : 'No live tenant is currently using it. No data is deleted.';
    } else if (field === 'is_available') {
        html = 'Tenants get it back according to their own setting, or the default.';
    } else {
        html = 'Companies registered from now on will start with "' + label + '" '
             + (on ? 'ON' : 'OFF') + '. Existing tenants that were never given an explicit '
             + 'answer follow this too.';
    }

    Swal.fire({
        title: title, html: html, icon: (field === 'is_available' && !on) ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: (field === 'is_available' && !on) ? '#dc3545' : '#0d6efd',
        confirmButtonText: 'Apply'
    }).then(function (r) {
        if (!r.isConfirmed) { el.checked = !on; return; }

        const data = { _csrf: SA_CSRF_TOKEN, feature_key: key };
        data[field] = on ? 1 : 0;

        $.ajax({ url: '/actions/superadmin_platform_features.php', method: 'POST', dataType: 'json', data: data })
            .done(function (res) {
                if (res && res.success) {
                    Swal.fire({ icon: 'success', title: 'Applied', text: res.message, timer: 2400, showConfirmButton: false });
                    setTimeout(function () { window.location.reload(); }, 2400);
                } else {
                    el.checked = !on;
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not apply.' });
                }
            })
            .fail(function (xhr) {
                el.checked = !on;
                let msg = 'Could not apply.';
                try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
                Swal.fire({ icon: 'error', title: 'Error', text: msg });
            });
    });
}
</script>
</body>
</html>
