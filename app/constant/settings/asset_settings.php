<?php
/**
 * app/constant/settings/asset_settings.php
 *
 * Admin page to manage the global Asset / PPE configuration (Phase 0 of the
 * Asset Register & PPE Schedule build): financial year, global take-on date,
 * and depreciation policy. These values drive the depreciation engine and the
 * PPE schedule in later phases.
 *
 * Single-row config — loaded server-side, edited in place via the form.
 * Permission: canEdit('assets'). Admin always.
 */
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/asset_settings.php';
autoEnforcePermission('assets');

$can_edit = canEdit('assets');

// View-page activity log (security-coverage audit requires this).
if (function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed Asset Settings',
        ($_SESSION['username'] ?? 'User') . ' opened the Asset Settings page.');
}

$settings = getAssetSettings($pdo);

includeHeader();
?>

<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('settings') ?>">Settings</a></li>
            <li class="breadcrumb-item active">Asset Settings</li>
        </ol>
    </nav>

    <div class="row mb-3 align-items-center">
        <div class="col-md-8">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-sliders me-2"></i> Asset Settings</h2>
            <p class="text-muted small mb-0">Global configuration for the Asset Register &amp; PPE Schedule — financial year, take-on date, and depreciation policy.</p>
        </div>
        <div class="col-md-4 text-end">
            <a href="<?= getUrl('asset_categories') ?>" class="btn btn-outline-secondary shadow-sm">
                <i class="bi bi-tags me-1"></i> Asset Categories
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form id="assetSettingsForm">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                <h6 class="text-uppercase small fw-bold text-muted mb-3"><i class="bi bi-calendar-range me-1"></i> Financial Year</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Financial Year Start <span class="text-danger">*</span></label>
                        <input type="date" name="financial_year_start" id="as_fy_start" class="form-control"
                               value="<?= safe_output($settings['financial_year_start'], '') ?>" required <?= $can_edit ? '' : 'disabled' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Financial Year End <span class="text-danger">*</span></label>
                        <input type="date" name="financial_year_end" id="as_fy_end" class="form-control"
                               value="<?= safe_output($settings['financial_year_end'], '') ?>" required <?= $can_edit ? '' : 'disabled' ?>>
                        <small class="text-muted">Must be after the start date.</small>
                    </div>
                </div>

                <h6 class="text-uppercase small fw-bold text-muted mb-3"><i class="bi bi-flag me-1"></i> Migration / Take-on</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Global Take-on Date</label>
                        <input type="date" name="global_take_on_date" id="as_take_on" class="form-control"
                               value="<?= safe_output($settings['global_take_on_date'], '') ?>" <?= $can_edit ? '' : 'disabled' ?>>
                        <small class="text-muted">Go-live cut-off for assets already owned before the system started. Leave blank if not migrating opening balances.</small>
                    </div>
                </div>

                <h6 class="text-uppercase small fw-bold text-muted mb-3"><i class="bi bi-calculator me-1"></i> Depreciation Policy</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Depreciation Frequency</label>
                        <select name="depreciation_frequency" id="as_frequency" class="form-select" <?= $can_edit ? '' : 'disabled' ?>>
                            <option value="annual"  <?= $settings['depreciation_frequency'] === 'annual'  ? 'selected' : '' ?>>Annual</option>
                            <option value="monthly" <?= $settings['depreciation_frequency'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        </select>
                        <small class="text-muted">How often depreciation is posted.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Mid-year Acquisition Rule</label>
                        <select name="depreciation_timing" id="as_timing" class="form-select" <?= $can_edit ? '' : 'disabled' ?>>
                            <option value="full_year" <?= $settings['depreciation_timing'] === 'full_year' ? 'selected' : '' ?>>Full year in year of acquisition</option>
                            <option value="pro_rata"  <?= $settings['depreciation_timing'] === 'pro_rata'  ? 'selected' : '' ?>>Pro-rata from capitalization date</option>
                        </select>
                        <small class="text-muted">Whether a mid-year purchase gets a full year's charge or a proportional one.</small>
                    </div>
                </div>

                <h6 class="text-uppercase small fw-bold text-muted mb-3"><i class="bi bi-diagram-3 me-1"></i> GL Integration (offset accounts)</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Clearing / Cash Account</label>
                        <input type="text" name="gl_clearing_account" id="as_gl_clearing" class="form-control"
                               value="<?= safe_output($settings['gl_clearing_account'] ?? '', '') ?>" placeholder="e.g. 1000" <?= $can_edit ? '' : 'disabled' ?>>
                        <small class="text-muted">Offset leg for acquisition &amp; disposal proceeds (an <code>accounts.account_code</code>).</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Gain / (Loss) on Disposal Account</label>
                        <input type="text" name="gl_gain_loss_account" id="as_gl_gainloss" class="form-control"
                               value="<?= safe_output($settings['gl_gain_loss_account'] ?? '', '') ?>" placeholder="e.g. 8000" <?= $can_edit ? '' : 'disabled' ?>>
                        <small class="text-muted">P&amp;L account for disposal gain/loss. Category accounts (asset / accum / expense) are set per category.</small>
                    </div>
                </div>

                <?php if ($can_edit): ?>
                <div class="text-end border-top pt-3">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Settings
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#assetSettingsForm').on('submit', function(e) {
        e.preventDefault();
        const btn  = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

        $.ajax({
            url: '<?= buildUrl('api/assets/save_asset_settings.php') ?>',
            type: 'POST',
            data: new FormData(this),
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
                }
            },
            error: function(xhr) {
                let msg = 'Server error. Please try again.';
                try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch(e) {}
                Swal.fire({ icon: 'error', title: 'Error', text: msg });
            },
            complete: function() { btn.prop('disabled', false).html(orig); }
        });
    });
});
</script>

<?php includeFooter(); ?>
