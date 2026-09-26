<?php
/**
 * app/superadmin/tenant_new.php — register a company from the panel.
 *
 * The operator-side counterpart to the public register.php. It reaches the same
 * provisioning engine through createTenantAsOperator(), which applies every
 * validation rule the public path applies while skipping the three anti-abuse
 * controls that are wrong for an authenticated operator (honeypot, IP throttle,
 * and the public self-registration master switch).
 *
 * Reads ONLY the control database.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/plans.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me   = currentSuperadmin();
$base = function_exists('tenantBaseDomain') ? (tenantBaseDomain() ?? '') : '';

// 'blank' is the reserved plan self-registration's provisioning-mode switch
// applies internally (tenant_module_control_plan.md §5.1) — it exists to
// represent "nothing", never as something an operator picks by hand here.
$startingPlans = planTablesReady()
    ? array_values(array_filter(listPlans(true), fn($p) => $p['plan_key'] !== 'blank'))
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>New Company | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .panel-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .panel-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    .sub-hint { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
</style>
</head>
<body>

<?php renderSuperadminHeader('tenants', $me); ?>

<div class="container-fluid p-3">
    <h6 class="mb-3"><i class="bi bi-plus-circle text-primary me-1"></i> Register a new company</h6>

    <div class="row">
        <div class="col-12 col-xl-8">
            <div class="card panel-card">
                <div class="card-header"><i class="bi bi-building text-primary me-1"></i> Company &amp; owner</div>
                <div class="card-body">

                    <div class="alert alert-light border small mb-3">
                        <i class="bi bi-info-circle text-primary me-1"></i>
                        This creates a <strong>separate database and MySQL user</strong> for the company and an owner
                        account that can sign in immediately. It takes up to a minute. If anything fails, nothing is
                        left behind.
                    </div>

                    <form id="newTenantForm" autocomplete="off">
                        <div class="row g-3">

                            <div class="col-12 col-md-6">
                                <label class="form-label">Company name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="company_name" required maxlength="191">
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Subdomain <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control sub-hint" name="subdomain" id="f-sub"
                                           required maxlength="32" placeholder="kampunia">
                                    <span class="input-group-text sub-hint"><?= $base !== '' ? '.' . safe_output($base, '') : '' ?></span>
                                </div>
                                <div class="form-text" id="subHint">
                                    Lowercase letters, numbers and hyphens. 3–32 characters.
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Owner first name</label>
                                <input type="text" class="form-control" name="owner_first_name" maxlength="100">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Owner last name</label>
                                <input type="text" class="form-control" name="owner_last_name" maxlength="100">
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Owner email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" name="owner_email" required maxlength="191">
                                <div class="form-text">They sign in with this address.</div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Account status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" required id="f-status" onchange="toggleTrialDate()">
                                    <option value="active" selected>Active — can sign in immediately</option>
                                    <option value="trial">Trial</option>
                                </select>
                            </div>

                            <div class="col-12 col-md-6" id="trialDateRow" style="display:none">
                                <label class="form-label">Trial ends <span class="text-muted fw-normal" style="font-size:.85rem">(default: +14 days)</span></label>
                                <input type="date" class="form-control" name="trial_ends_at" id="f-trial-ends">
                                <div class="form-text">Leave blank to use the default 14-day trial.</div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Starting plan</label>
                                <?php if (!$startingPlans): ?>
                                <select class="form-select" name="plan_id" disabled>
                                    <option value="">Everything on (no plans created yet)</option>
                                </select>
                                <div class="form-text">
                                    <a href="<?= saUrl('plans') ?>">Create a plan</a> to offer a curated starting
                                    module set here instead.
                                </div>
                                <?php else: ?>
                                <select class="form-select" name="plan_id">
                                    <option value="">Everything on (apply a plan later if needed)</option>
                                    <?php foreach ($startingPlans as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>"><?= safe_output($p['name'], '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Applied immediately — the company never briefly has every module before this
                                    takes effect. Leave blank for today's default (everything on).
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Owner password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="f-pw" name="owner_password" required
                                           autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('f-pw',this)" tabindex="-1" title="Show/hide password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">At least 8 characters, including a letter and a number.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Confirm password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="f-pw2" name="owner_password_confirm" required
                                           autocomplete="new-password">
                                    <button type="button" class="btn btn-outline-secondary" onclick="togglePw('f-pw2',this)" tabindex="-1" title="Show/hide password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                        </div>

                        <hr class="my-3">
                        <h6 class="mb-1">Company profile <span class="text-muted fw-normal" style="font-size:.85rem">(optional)</span></h6>
                        <p class="text-muted small mb-3">Pre-fills the company's profile. Every field can be left blank and filled in by the company later.</p>

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Owner Phone</label>
                                <input type="text" class="form-control" name="owner_phone" maxlength="20" placeholder="+255…">
                                <div class="form-text">Stored in the platform registry for quick access.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Country</label>
                                <select class="form-select" name="country">
                                    <option value="">— Select —</option>
                                    <option value="Tanzania">Tanzania</option>
                                    <option value="Kenya">Kenya</option>
                                    <option value="Uganda">Uganda</option>
                                    <option value="Rwanda">Rwanda</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Industry</label>
                                <select class="form-select" name="industry">
                                    <option value="">— Select —</option>
                                    <option value="retail">Retail / Shop</option>
                                    <option value="restaurant">Restaurant / Café</option>
                                    <option value="services">Services / Consulting</option>
                                    <option value="manufacturing">Manufacturing</option>
                                    <option value="healthcare">Healthcare</option>
                                    <option value="transport">Transport / Logistics</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Company size</label>
                                <select class="form-select" name="company_size">
                                    <option value="">— Select —</option>
                                    <option value="1-5">1–5 staff</option>
                                    <option value="6-20">6–20 staff</option>
                                    <option value="21-100">21–100 staff</option>
                                    <option value="100+">100+ staff</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Company Phone</label>
                                <input type="text" class="form-control" name="phone" maxlength="50">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Company Email</label>
                                <input type="email" class="form-control" name="email" maxlength="191">
                                <div class="form-text">The company's contact email, not necessarily the owner's sign-in address.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" name="address" rows="2" maxlength="500"></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Website</label>
                                <input type="url" class="form-control" name="website" maxlength="255" placeholder="https://">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">TIN</label>
                                <input type="text" class="form-control" name="tin" maxlength="50">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label">VRN</label>
                                <input type="text" class="form-control" name="vrn" maxlength="50">
                            </div>
                        </div>

                        <!-- P7 — Billing (optional) -->
                        <hr class="my-3">
                        <h6 class="mb-1">Billing <span class="text-muted fw-normal" style="font-size:.85rem">(optional)</span></h6>
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Billing Cycle</label>
                                <select class="form-select" name="billing_cycle">
                                    <option value="">— not set —</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="annual">Annual</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Amount (TZS)</label>
                                <input type="number" class="form-control" name="billing_amount_tzs" min="0" step="1000" placeholder="0">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Payment Status</label>
                                <select class="form-select" name="payment_status">
                                    <option value="none">Not set</option>
                                    <option value="current">Current</option>
                                    <option value="pending">Pending</option>
                                    <option value="overdue">Overdue</option>
                                </select>
                            </div>
                        </div>

                        <hr class="my-3">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Create company
                            </button>
                            <a href="<?= saUrl('tenants') ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';

function toggleTrialDate() {
    const status = document.getElementById('f-status').value;
    document.getElementById('trialDateRow').style.display = status === 'trial' ? '' : 'none';
}
$.ajaxSetup({ headers: { 'X-CSRF-Token': SA_CSRF_TOKEN } });

// Live availability check. Advisory only — the server re-checks on submit, so a
// name taken between typing and submitting is still refused properly.
let subTimer = null;
$('#f-sub').on('input', function () {
    const v = $(this).val().trim().toLowerCase();
    $(this).val(v);
    clearTimeout(subTimer);
    if (v.length < 3) {
        $('#subHint').removeClass('text-danger text-success')
                     .text('Lowercase letters, numbers and hyphens. 3–32 characters.');
        return;
    }
    subTimer = setTimeout(function () {
        $.getJSON('/ajax/check_subdomain_availability.php', { subdomain: v })
            .done(function (res) {
                const free = !!(res && res.available);
                $('#subHint').toggleClass('text-success', free)
                             .toggleClass('text-danger', !free)
                             .text((res && res.message) || (free ? 'Available.' : 'Not available.'));
            })
            .fail(function () {
                $('#subHint').removeClass('text-success text-danger').text('Could not check availability.');
            });
    }, 350);
});

function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const showing = inp.type === 'text';
    inp.type = showing ? 'password' : 'text';
    btn.querySelector('i').className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
}

$('#newTenantForm').on('submit', function (e) {
    e.preventDefault();
    const $f   = $(this);
    const btn  = $f.find('[type="submit"]');
    const orig = btn.html();

    const data = $f.serializeArray();
    data.push({ name: '_csrf', value: SA_CSRF_TOKEN });

    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Creating...');
    Swal.fire({
        title: 'Creating the company…',
        html: 'Building its database and seeding defaults. This can take up to a minute.',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    $.ajax({
        url: '/actions/superadmin_create_tenant.php',
        method: 'POST',
        dataType: 'json',
        timeout: 180000,
        data: $.param(data)
    }).done(function (res) {
        if (res && res.success) {
            const url = res.login_url || '';
            if (url) { window.open(url, '_blank', 'noopener,noreferrer'); }
            Swal.fire({
                icon: 'success',
                title: 'Company created',
                html: 'Its database, database user and owner account are ready.'
                    + (url ? '<br><small class="text-muted">Login page opened in a new tab.</small>'
                           + '<br><a href="' + $('<div>').text(url).html() + '" target="_blank" rel="noopener">'
                           + $('<div>').text(url).html() + '</a>' : ''),
                confirmButtonColor: '#0d6efd',
                confirmButtonText: 'Back to tenants'
            }).then(function () { window.location.href = '<?= saUrl('tenants') ?>'; });
        } else {
            Swal.fire({ icon: 'error', title: 'Could not create the company',
                        text: (res && res.message) || 'Something went wrong.' });
        }
    }).fail(function (xhr) {
        let msg = 'Something went wrong.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Could not create the company', text: msg });
    }).always(function () {
        btn.prop('disabled', false).html(orig);
    });
});
</script>
</body>
</html>
