<?php
/**
 * app/superadmin/plans.php — reusable pricing/feature bundles.
 *
 * Control database only. Applying a plan to a tenant happens from
 * tenant_view.php (that's the page that already knows which tenant); this
 * page only manages the catalogue itself.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/plans.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me      = currentSuperadmin();
$plans   = [];
$error   = null;
$setup   = false;
$availableFeatures = [];

try {
    if (!planTablesReady()) {
        $setup = true;
    } else {
        $plans = listPlans();
        $availableFeatures = getControlPdo()
            ->query("SELECT feature_key, label FROM features WHERE is_available = 1 ORDER BY sort_order, feature_key")
            ->fetchAll();
    }
} catch (Throwable $e) {
    error_log('superadmin plans: ' . $e->getMessage());
    $error = 'The plan catalogue could not be read.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Plans | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .detail-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .detail-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    .plan-card.retired { opacity: .6; }
    .feature-pick { background: #f8f9fb; border: 1px solid #e9ecef; border-radius: 6px; }
</style>
</head>
<body>

<?php renderSuperadminHeader('plans', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h5 class="mb-0 fw-bold"><i class="bi bi-box-seam text-primary me-1"></i> Plans</h5>
        <?php if (!$setup && !$error): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#planModal" onclick="openCreate()">
            <i class="bi bi-plus-circle me-1"></i> New plan
        </button>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= safe_output($error, '') ?></div>
    <?php elseif ($setup): ?>
        <div class="card detail-card">
            <div class="card-header"><i class="bi bi-tools text-primary me-1"></i> One setup step remains on this server</div>
            <div class="card-body">
                <p>Plan management is not set up on this server yet, so there is nothing to show here.</p>
                <p class="text-muted small">Run this once on this host, then reload this page:</p>
                <pre class="p-3 rounded mb-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><code>php scripts/setup_control_db.php</code></pre>
            </div>
        </div>
    <?php elseif (!$plans): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2"></i>
            No plans yet.
            <div class="mt-3">
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#planModal" onclick="openCreate()">
                    <i class="bi bi-plus-circle me-1"></i> Create the first plan
                </button>
            </div>
        </div>
    <?php else: ?>
    <p class="text-muted small">
        Applying a plan to a tenant sets its modules and quotas in one click — using the same
        <a href="<?= saUrl('features') ?>">Modules</a> switches and usage limits you can already edit per
        tenant. A tenant stays fully, independently editable afterward; nothing here is a hard link.
    </p>
    <div class="row g-3">
        <?php foreach ($plans as $p): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card plan-card detail-card h-100 <?= $p['is_active'] ? '' : 'retired' ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-box-seam text-primary me-1"></i><?= safe_output($p['name'], '') ?></span>
                    <?php if (!$p['is_active']): ?><span class="badge bg-secondary">Retired</span><?php endif; ?>
                </div>
                <div class="card-body d-flex flex-column">
                    <p class="text-muted small mb-2"><?= safe_output($p['description'] ?? '', 'No description.') ?></p>
                    <div class="small mb-2">
                        <i class="bi bi-people me-1 text-primary"></i><?= $p['max_users'] !== null ? (int)$p['max_users'] . ' max users' : 'Unlimited users' ?><br>
                        <i class="bi bi-hdd me-1 text-primary"></i><?= $p['max_storage_mb'] !== null ? number_format((int)$p['max_storage_mb']) . ' MB storage' : 'Unlimited storage' ?><br>
                        <i class="bi bi-grid me-1 text-primary"></i><?= count(planFeatureKeys((int)$p['id'])) ?> module<?= count(planFeatureKeys((int)$p['id'])) === 1 ? '' : 's' ?> included
                    </div>
                    <div class="small text-muted mb-3"><?= (int)$p['tenants_using'] ?> tenant<?= (int)$p['tenants_using'] === 1 ? '' : 's' ?> currently on this plan</div>
                    <div class="mt-auto d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary flex-fill"
                                onclick='openEdit(<?= json_encode([
                                    "id" => (int)$p["id"], "name" => $p["name"], "description" => $p["description"],
                                    "max_users" => $p["max_users"], "max_storage_mb" => $p["max_storage_mb"],
                                    "sort_order" => $p["sort_order"], "feature_keys" => planFeatureKeys((int)$p["id"]),
                                ], JSON_UNESCAPED_UNICODE) ?>)' data-bs-toggle="modal" data-bs-target="#planModal">
                            <i class="bi bi-pencil me-1"></i> Edit
                        </button>
                        <?php if ($p['is_active']): ?>
                        <button class="btn btn-sm btn-outline-secondary" onclick="toggleActive(<?= (int)$p['id'] ?>, false)" title="Retire">
                            <i class="bi bi-pause-circle"></i>
                        </button>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline-primary" onclick="toggleActive(<?= (int)$p['id'] ?>, true)" title="Restore">
                            <i class="bi bi-play-circle"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit modal -->
<div class="modal fade" id="planModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="planModalTitle"><i class="bi bi-box-seam me-1"></i> New plan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="planForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="f_id" name="id">
                    <div id="plan-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Plan name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="f_name" name="name" required maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sort order</label>
                            <input type="number" class="form-control" id="f_sort" name="sort_order" value="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <input type="text" class="form-control" id="f_desc" name="description" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Max active users</label>
                            <input type="text" inputmode="numeric" class="form-control" id="f_users" name="max_users" placeholder="Unlimited">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Max storage (MB)</label>
                            <input type="text" inputmode="numeric" class="form-control" id="f_storage" name="max_storage_mb" placeholder="Unlimited">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Included modules</label>
                            <div class="feature-pick p-2">
                                <div class="row row-cols-2 row-cols-md-3">
                                    <?php foreach ($availableFeatures as $f): ?>
                                    <div class="col">
                                        <div class="form-check">
                                            <input class="form-check-input plan-feature" type="checkbox" value="<?= safe_output($f['feature_key'], '') ?>" id="pf_<?= safe_output($f['feature_key'], '') ?>">
                                            <label class="form-check-label small" for="pf_<?= safe_output($f['feature_key'], '') ?>"><?= safe_output($f['label'], '') ?></label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text">Applying this plan turns these modules ON for a tenant and everything else OFF.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';
$.ajaxSetup({ headers: { 'X-CSRF-Token': SA_CSRF_TOKEN } });

function openCreate() {
    $('#planForm')[0].reset();
    $('#f_id').val('');
    $('.plan-feature').prop('checked', false);
    $('#planModalTitle').html('<i class="bi bi-box-seam me-1"></i> New plan');
    $('#plan-message').html('');
}

function openEdit(p) {
    $('#planForm')[0].reset();
    $('#f_id').val(p.id);
    $('#f_name').val(p.name);
    $('#f_desc').val(p.description || '');
    $('#f_users').val(p.max_users !== null ? p.max_users : '');
    $('#f_storage').val(p.max_storage_mb !== null ? p.max_storage_mb : '');
    $('#f_sort').val(p.sort_order || 0);
    $('.plan-feature').prop('checked', false);
    (p.feature_keys || []).forEach(function (k) { $('#pf_' + k).prop('checked', true); });
    $('#planModalTitle').html('<i class="bi bi-pencil me-1"></i> Edit plan');
    $('#plan-message').html('');
}

$('#planForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    const featureKeys = $('.plan-feature:checked').map(function () { return this.value; }).get();
    const data = {
        _csrf: SA_CSRF_TOKEN,
        action: $('#f_id').val() ? 'update' : 'create',
        id: $('#f_id').val(),
        name: $('#f_name').val(),
        description: $('#f_desc').val(),
        max_users: $('#f_users').val(),
        max_storage_mb: $('#f_storage').val(),
        sort_order: $('#f_sort').val(),
        feature_keys: featureKeys,
    };

    $.ajax({ url: '/actions/superadmin_plans.php', method: 'POST', dataType: 'json', data: data })
        .done(function (res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                $('#plan-message').html('<div class="alert alert-danger py-2 mb-0">' + ((res && res.message) || 'Could not save.') + '</div>');
            }
        })
        .fail(function (xhr) {
            let msg = 'Could not save.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
            $('#plan-message').html('<div class="alert alert-danger py-2 mb-0">' + msg + '</div>');
        })
        .always(function () { btn.prop('disabled', false).html(orig); });
});

function toggleActive(id, active) {
    Swal.fire({
        title: active ? 'Restore this plan?' : 'Retire this plan?',
        text: active ? 'It becomes available to apply to tenants again.' : 'It can no longer be applied to new tenants. Tenants already on it are unaffected.',
        icon: 'question', showCancelButton: true, confirmButtonColor: '#0d6efd',
        confirmButtonText: active ? 'Restore' : 'Retire',
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '/actions/superadmin_plans.php', method: 'POST', dataType: 'json',
            data: { _csrf: SA_CSRF_TOKEN, action: 'toggle_active', id: id, active: active ? 1 : 0 },
        }).done(function (res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Done', timer: 1500, showConfirmButton: false }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not update.' });
            }
        }).fail(function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Could not update.' }); });
    });
}
</script>
</body>
</html>
