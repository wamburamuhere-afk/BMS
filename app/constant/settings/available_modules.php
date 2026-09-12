<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/module_requests.php';

// Admin-only, no permissions-table row — this is inherently platform-facing
// information about the tenant's OWN subscription (what the company has vs.
// what exists to grow into), not a role-configurable page. Same "hard
// isAdmin() gate" pattern as company_profile.php/system_settings.php, minus
// autoEnforcePermission() since there is deliberately no permission row to
// delegate (tenant_module_control_plan.md §6.2).
if (!isAdmin()) {
    header('Location: ' . getUrl('unauthorized'));
    exit;
}

$page_title = 'Available Modules';
require_once __DIR__ . '/../../../header.php';

// Single-tenant installs / CLI resolve no tenant at all — bmsCurrentTenantId()
// is null there, and listAvailableModulesForTenant(null) already knows every
// module reads as active in that case (bmsPrimeTenantFeatures()'s own
// "no tenant -> everything on" rule), so this page still renders something
// sensible instead of an empty/broken list.
$tenantId = function_exists('bmsCurrentTenantId') ? bmsCurrentTenantId() : null;
$modules  = listAvailableModulesForTenant($tenantId);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-grid text-primary me-2"></i><?= t('Available Modules') ?></h4>
    </div>

    <div class="alert alert-light border small mb-4">
        <i class="bi bi-info-circle text-primary me-1"></i>
        <?= t('These are the optional modules your subscription can include.') ?> <strong><?= t('Active') ?></strong> <?= t('modules are already part of your plan. For anything else, send a request — a platform administrator reviews it and lets you know the decision.') ?>
    </div>

    <div class="row g-3">
        <?php foreach ($modules as $m): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100 shadow-sm <?= $m['active'] ? 'border-primary' : '' ?>">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="fw-bold mb-0"><?= safe_output($m['label'], '') ?></h6>
                        <?php if ($m['active']): ?>
                            <span class="badge" style="background:#0d6efd;color:#fff;"><?= t('Active') ?></span>
                        <?php elseif ($m['pending']): ?>
                            <span class="badge" style="background:#e9ecef;color:#495057;"><?= t('Requested') ?></span>
                        <?php else: ?>
                            <span class="badge" style="background:#e9ecef;color:#495057;"><?= t('Available') ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-muted small mb-2 flex-grow-1"><?= safe_output($m['description'], '') ?></p>

                    <?php if (!$m['active'] && $m['requires']): ?>
                    <p class="small mb-2">
                        <i class="bi bi-link-45deg text-muted"></i>
                        <?= t('Requires:') ?>
                        <?= implode(', ', array_map(fn($r) => safe_output($r['label'], ''), $m['requires'])) ?>
                    </p>
                    <?php endif; ?>

                    <?php if ($m['active']): ?>
                        <button class="btn btn-sm btn-outline-primary mt-auto" disabled>
                            <i class="bi bi-check-circle me-1"></i> <?= t('Included in your plan') ?>
                        </button>
                    <?php elseif ($m['pending']): ?>
                        <button class="btn btn-sm btn-outline-secondary mt-auto" disabled>
                            <i class="bi bi-hourglass-split me-1"></i> <?= t('Awaiting approval') ?>
                        </button>
                    <?php else: ?>
                        <button class="btn btn-sm btn-primary mt-auto btn-request-module"
                                data-key="<?= safe_output($m['key'], '') ?>"
                                data-label="<?= safe_output($m['label'], '') ?>">
                            <i class="bi bi-send me-1"></i> <?= t('Request this module') ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
$(document).ready(function () {
    $('.btn-request-module').on('click', function () {
        const key = $(this).data('key');
        const label = $(this).data('label');
        const $btn = $(this);

        Swal.fire({
            title: <?= json_encode(t('Request')) ?> + ' ' + label,
            input: 'textarea',
            inputPlaceholder: <?= json_encode(t('Optional note for the platform administrator (e.g. why you need it)...')) ?>,
            showCancelButton: true,
            confirmButtonText: <?= json_encode(t('Send request')) ?>,
            confirmButtonColor: '#0d6efd'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + <?= json_encode(t('Sending...')) ?>);
            $.ajax({
                url: '<?= buildUrl('api/request_module_access.php') ?>',
                type: 'POST',
                dataType: 'json',
                data: { feature_key: key, note: result.value || '', _csrf: CSRF_TOKEN }
            }).done(function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: <?= json_encode(t('Request sent')) ?>, text: res.message, timer: 2500, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: res.message || <?= json_encode(t('Something went wrong.')) ?> });
                    $btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i> ' + <?= json_encode(t('Request this module')) ?>);
                }
            }).fail(function () {
                Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: <?= json_encode(t('Server error. Please try again.')) ?> });
                $btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i> ' + <?= json_encode(t('Request this module')) ?>);
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../../footer.php'; ?>
