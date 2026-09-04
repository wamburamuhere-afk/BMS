<?php
/**
 * app/superadmin/profile.php — an operator managing their own account.
 *
 * Reads ONLY the control database. Before this page existed, changing a
 * superadmin password meant scripts/create_superadmin.php or raw SQL — the
 * operator who administers the platform could not rotate their own credential
 * from inside it.
 *
 * Both forms require the CURRENT password, including the name/email one: email
 * is a login credential here, so changing it is a credential change.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me = currentSuperadmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>My Account | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .panel-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .panel-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
</style>
</head>
<body>

<?php renderSuperadminHeader('profile', $me); ?>

<div class="container-fluid p-3">
    <h6 class="mb-3"><i class="bi bi-person-gear text-primary me-1"></i> My Account</h6>

    <div class="row g-3">

        <!-- Name & email -->
        <div class="col-12 col-lg-6">
            <div class="card panel-card h-100">
                <div class="card-header"><i class="bi bi-person text-primary me-1"></i> Your details</div>
                <div class="card-body">
                    <form id="profileForm" autocomplete="off">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required
                                   value="<?= safe_output($me['name'] ?? '', '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" required
                                   value="<?= safe_output($me['email'] ?? '', '') ?>">
                            <div class="form-text">This is the address you sign in with.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Current password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="current_password" required
                                   autocomplete="current-password">
                            <div class="form-text">Required — changing your email changes how you sign in.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Save details
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Password -->
        <div class="col-12 col-lg-6">
            <div class="card panel-card h-100">
                <div class="card-header"><i class="bi bi-key text-primary me-1"></i> Change password</div>
                <div class="card-body">
                    <form id="passwordForm" autocomplete="off">
                        <input type="hidden" name="action" value="change_password">
                        <div class="mb-3">
                            <label class="form-label">Current password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="current_password" required
                                   autocomplete="current-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="new_password" required
                                   autocomplete="new-password">
                            <div class="form-text">At least 8 characters, including a letter and a number.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm new password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" name="confirm_password" required
                                   autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Change password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                Account created <?= safe_output($me['created_at'] ?? '', '—') ?> ·
                Last sign-in <?= safe_output($me['last_login'] ?? '', 'never') ?>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Named SA_CSRF_TOKEN, not CSRF_TOKEN: header.php is the canonical declarer of
// the latter and tests/test_csrf_token_redeclaration_cli.php forbids any page
// under app/ from shadowing it.
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';
$.ajaxSetup({ headers: { 'X-CSRF-Token': SA_CSRF_TOKEN } });

function submitAccountForm(form, successTitle, onDone) {
    const $f   = $(form);
    const btn  = $f.find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    const data = $f.serializeArray();
    data.push({ name: '_csrf', value: SA_CSRF_TOKEN });

    $.ajax({
        url: '/actions/superadmin_profile_action.php',
        method: 'POST',
        dataType: 'json',
        data: $.param(data)
    }).done(function (res) {
        if (res && res.success) {
            Swal.fire({ icon: 'success', title: successTitle, text: res.message,
                        timer: 2200, showConfirmButton: false });
            if (onDone) onDone();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'The change could not be saved.' });
        }
    }).fail(function (xhr) {
        let msg = 'The change could not be saved.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Error', text: msg });
    }).always(function () {
        btn.prop('disabled', false).html(orig);
    });
}

$('#profileForm').on('submit', function (e) {
    e.preventDefault();
    // Reload so the header and the form redisplay the saved values.
    submitAccountForm(this, 'Details updated', function () {
        setTimeout(function () { window.location.reload(); }, 2200);
    });
});

$('#passwordForm').on('submit', function (e) {
    e.preventDefault();
    const form = this;
    // The session survives a password change by design, so there is nothing to
    // reload — just clear the fields so the old password is not left on screen.
    submitAccountForm(form, 'Password changed', function () { form.reset(); });
});
</script>
</body>
</html>
