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

// Simple Mode — reachable straight from the "Point of Sale" card below via its
// "More" button, instead of only being buried in POS Settings. Same
// underlying system_settings key either page writes to (api/pos/save_simple_mode.php),
// so both stay in sync automatically. See core/pos_nav.php::posSimpleModeEnabled().
$pos_simple_mode_value = get_setting('pos_simple_mode', '0');

// A platform superadmin can lock this so only THEY manage it for this tenant
// (app/superadmin/tenant_view.php > Point of Sale > More) — the tenant's own
// "More" button is then genuinely absent here, not just disabled, and
// api/pos/save_simple_mode.php refuses the write server-side too. Single-
// tenant installs (bmsCurrentTenant() === null) are never locked.
$tenantRow = function_exists('bmsCurrentTenant') ? bmsCurrentTenant() : null;
$pos_simple_mode_locked = $tenantRow ? !empty($tenantRow['pos_simple_mode_locked']) : false;
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
                        <div class="d-flex gap-2 mt-auto">
                            <button class="btn btn-sm btn-outline-primary flex-grow-1" disabled>
                                <i class="bi bi-check-circle me-1"></i> <?= t('Included in your plan') ?>
                            </button>
                            <?php if ($m['key'] === 'pos' && !$pos_simple_mode_locked): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#posSimpleModeModal">
                                <?= t('More') ?>
                            </button>
                            <?php endif; ?>
                        </div>
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

<!-- Simple Mode — opened from the Point of Sale card's "More" button. Genuinely
     absent (not just its trigger button) when a superadmin has locked this
     tenant out of self-managing it — see $pos_simple_mode_locked above. -->
<?php if (!$pos_simple_mode_locked): ?>
<div class="modal fade" id="posSimpleModeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-shop me-1"></i> <?= t('Simple Mode') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="posSimpleModeCheckbox" <?= $pos_simple_mode_value === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="posSimpleModeCheckbox"><?= t('Simple mode for a small shop (no accountant)') ?></label>
                </div>
                <div class="form-text mt-2"><?= t('Hides accounting-style menus and reports for everyone in this business. The Dashboard shows only what was bought vs what was sold, and Reports becomes a short list: Sales, Purchases, Stock, Expenses. Nothing about how sales are recorded changes — this only changes what is shown.') ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-primary" id="posSimpleModeSaveBtn"><i class="bi bi-check-circle me-1"></i> <?= t('Save') ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; // !$pos_simple_mode_locked ?>

<script>
$(document).ready(function () {
    $('#posSimpleModeSaveBtn').on('click', function () {
        const $btn = $(this);
        const orig = $btn.html();
        const enabled = $('#posSimpleModeCheckbox').is(':checked') ? 1 : 0;
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + <?= json_encode(t('Saving...')) ?>);
        $.post('<?= buildUrl('api/pos/save_simple_mode.php') ?>', { enabled: enabled, _csrf: CSRF_TOKEN }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: <?= json_encode(t('Saved')) ?>, text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire(<?= json_encode(t('Error')) ?>, res.message, 'error');
                $btn.prop('disabled', false).html(orig);
            }
        }, 'json').fail(function () {
            Swal.fire(<?= json_encode(t('Error')) ?>, <?= json_encode(t('Server error. Please try again.')) ?>, 'error');
            $btn.prop('disabled', false).html(orig);
        });
    });

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
