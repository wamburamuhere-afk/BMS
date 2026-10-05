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
require_once __DIR__ . '/../../core/superadmin_2fa.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me = currentSuperadmin();

// Read fresh rather than trusting the session copy: currentSuperadmin() may
// predate an enrolment finished in another tab, and showing "Off" next to a
// working second factor is how someone ends up turning it on twice.
$meRow        = saTotpRow((int)$me['id']) ?? $me;
$twoFactorOn  = saTotpEnabled($meRow);
$recoveryLeft = saTotpRecoveryRemaining($meRow);
$me           = $meRow + $me;
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

        <!-- Two-step sign-in -->
        <div class="col-12 col-lg-6">
            <div class="card panel-card h-100">
                <div class="card-header">
                    <i class="bi bi-shield-lock text-primary me-1"></i> Two-step sign-in
                    <?php if ($twoFactorOn): ?>
                        <span class="badge ms-1" style="background:#0d6efd;color:#fff">On</span>
                    <?php else: ?>
                        <span class="badge ms-1" style="background:#6c757d;color:#fff">Off</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($twoFactorOn): ?>
                        <p class="text-muted small">
                            After your password you are asked for a code from your authenticator app.
                            On since <?= safe_output($me['totp_confirmed_at'] ?? '', '—') ?>.
                        </p>
                        <div class="alert <?= $recoveryLeft <= 2 ? 'alert-warning' : 'alert-secondary' ?> py-2 small">
                            <i class="bi bi-key me-1"></i>
                            <strong><?= (int)$recoveryLeft ?></strong> recovery code<?= $recoveryLeft === 1 ? '' : 's' ?> left.
                            <?php if ($recoveryLeft <= 2): ?>
                                Turn two-step off and on again to get a fresh set before you run out —
                                without one you would be locked out if you lost your phone.
                            <?php endif; ?>
                        </div>
                        <button class="btn btn-outline-danger" onclick="disable2fa()">
                            <i class="bi bi-shield-slash me-1"></i> Turn off two-step sign-in
                        </button>
                    <?php else: ?>
                        <p class="text-muted small">
                            This account can reach every company on the platform, and one password is
                            currently the whole of its protection. Two-step sign-in adds a 6-digit code
                            from an app on your phone.
                        </p>
                        <button class="btn btn-primary" onclick="begin2fa()">
                            <i class="bi bi-shield-plus me-1"></i> Set up two-step sign-in
                        </button>
                    <?php endif; ?>
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

// ── Two-step sign-in ───────────────────────────────────────────────────────
function begin2fa() {
    $.post('/actions/superadmin_2fa_action.php', { _csrf: SA_CSRF_TOKEN, action: 'begin' }, null, 'json')
        .done(function (res) {
            if (!res || !res.success) {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not start setup.' });
                return;
            }
            // QR drawn in the browser from the otpauth URI. The secret is not
            // sent to any third party to render it — an image service would
            // receive the one value that is supposed to stay between this
            // server and the operator's phone.
            const qr = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
            $.getScript(qr).always(function () {
                Swal.fire({
                    title: 'Scan this with your authenticator app',
                    html: '<div id="qrBox" class="d-flex justify-content-center my-3"></div>'
                        + '<p class="small text-muted mb-1">Can\'t scan? Type this key in instead:</p>'
                        + '<code style="font-size:1rem;letter-spacing:1px;">'
                        + $('<div>').text(res.formatted).html() + '</code>'
                        + '<hr><p class="small mb-1">Then enter the 6-digit code it shows:</p>'
                        + '<input id="confirmCode" class="form-control text-center" inputmode="numeric" '
                        + 'maxlength="6" placeholder="123456" style="letter-spacing:4px;font-size:1.2rem;">',
                    showCancelButton: true,
                    confirmButtonText: 'Turn it on',
                    didOpen: function () {
                        if (window.QRCode) {
                            new QRCode(document.getElementById('qrBox'), {
                                text: res.uri, width: 180, height: 180
                            });
                        } else {
                            $('#qrBox').html('<span class="text-muted small">'
                                + 'QR image unavailable — use the key below.</span>');
                        }
                        document.getElementById('confirmCode').focus();
                    },
                    preConfirm: function () {
                        const v = (document.getElementById('confirmCode').value || '').trim();
                        if (!/^\d{6}$/.test(v)) {
                            Swal.showValidationMessage('Enter the 6 digits shown in your app.');
                            return false;
                        }
                        return v;
                    }
                }).then(function (r) {
                    if (r.isConfirmed) confirm2fa(r.value);
                });
            });
        })
        .fail(function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach the server.' });
        });
}

function confirm2fa(code) {
    $.post('/actions/superadmin_2fa_action.php',
           { _csrf: SA_CSRF_TOKEN, action: 'confirm', code: code }, null, 'json')
        .done(function (res) {
            if (!res || !res.success) {
                Swal.fire({ icon: 'error', title: 'Not turned on', text: (res && res.message) || 'That code was not accepted.' });
                return;
            }
            // Shown once and never again — they exist in plain text nowhere else.
            const list = (res.recovery_codes || []).map(function (c) {
                return '<div style="font-family:monospace;font-size:1.05rem;letter-spacing:1px;">'
                     + $('<div>').text(c).html() + '</div>';
            }).join('');
            Swal.fire({
                icon: 'success',
                title: 'Two-step sign-in is on',
                html: '<p class="small">Save these <strong>recovery codes</strong> somewhere safe. '
                    + 'Each works once, and they are the only way back in if you lose your phone.</p>'
                    + '<div class="border rounded p-3 my-2" style="background:#f8f9fa;">' + list + '</div>'
                    + '<p class="small text-danger mb-0">They will not be shown again.</p>',
                confirmButtonText: 'I have saved them',
                allowOutsideClick: false
            }).then(function () { window.location.reload(); });
        })
        .fail(function (xhr) {
            let msg = 'That code was not accepted.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
            Swal.fire({ icon: 'error', title: 'Not turned on', text: msg });
        });
}

function disable2fa() {
    Swal.fire({
        title: 'Turn off two-step sign-in?',
        html: 'Your account will be protected by its password alone — and it can reach '
            + '<strong>every company on the platform</strong>.<br><br>'
            + 'Enter your current password to confirm:',
        input: 'password',
        inputAttributes: { autocomplete: 'current-password' },
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Turn it off',
        inputValidator: function (v) { return v ? undefined : 'Enter your current password.'; }
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.post('/actions/superadmin_2fa_action.php',
               { _csrf: SA_CSRF_TOKEN, action: 'disable', current_password: r.value }, null, 'json')
            .done(function (res) {
                if (res && res.success) {
                    Swal.fire({ icon: 'success', title: 'Turned off', text: res.message, timer: 1800, showConfirmButton: false });
                    setTimeout(function () { window.location.reload(); }, 1800);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Could not turn it off.' });
                }
            })
            .fail(function (xhr) {
                let msg = 'Could not turn it off.';
                try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
                Swal.fire({ icon: 'error', title: 'Error', text: msg });
            });
    });
}
</script>
</body>
</html>
