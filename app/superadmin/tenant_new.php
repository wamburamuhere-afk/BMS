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
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me   = currentSuperadmin();
$base = function_exists('tenantBaseDomain') ? (tenantBaseDomain() ?? '') : '';
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
    .page-header { position: sticky; top: 0; z-index: 1020; background: #fff; border-bottom: 1px solid #e9ecef; }
    .panel-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .panel-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    .sub-hint { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
</style>
</head>
<body>

<div class="page-header px-3 py-2 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock-fill text-primary fs-4"></i>
        <div>
            <div class="fw-bold">Platform Administration</div>
            <small class="text-muted">Signed in as <?= safe_output($me['name'] ?? '', '') ?></small>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="tenants.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-building me-1"></i> Tenants</a>
        <a href="profile.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-person-gear me-1"></i> My Account</a>
        <a href="logout.php" class="btn btn-sm btn-secondary"><i class="bi bi-box-arrow-right me-1"></i> Sign out</a>
    </div>
</div>

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
                                <select class="form-select" name="status" required>
                                    <option value="active" selected>Active — can sign in immediately</option>
                                    <option value="trial">Trial</option>
                                </select>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label">Owner password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="owner_password" required
                                       autocomplete="new-password">
                                <div class="form-text">At least 8 characters, including a letter and a number.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Confirm password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" name="owner_password_confirm" required
                                       autocomplete="new-password">
                            </div>

                        </div>

                        <hr class="my-3">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Create company
                            </button>
                            <a href="tenants.php" class="btn btn-secondary">Cancel</a>
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
            Swal.fire({
                icon: 'success',
                title: 'Company created',
                html: 'Its database, database user and owner account are ready.'
                    + (url ? '<br><br><a href="' + $('<div>').text(url).html() + '" target="_blank" rel="noopener">'
                           + $('<div>').text(url).html() + '</a>' : ''),
                confirmButtonColor: '#0d6efd',
                confirmButtonText: 'Back to tenants'
            }).then(function () { window.location.href = 'tenants.php'; });
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
