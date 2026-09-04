<?php
/**
 * app/superadmin/login.php — platform operator sign-in.
 *
 * Deliberately standalone: it does NOT go through roots.php. That bootstrap
 * loads tenant permissions, i18n and check_auth, all of which assume a tenant
 * user and a tenant database. This page must work when no tenant is involved at
 * all, so it loads only what it needs.
 */
require_once __DIR__ . '/../../core/superadmin_auth.php';
require_once __DIR__ . '/../../helpers.php';

// A tenant subdomain gets a flat 404 — the panel does not advertise itself.
assertSuperadminHost();
superadminSessionReady();

if (isSuperadminLoggedIn()) {
    header('Location: ' . saUrl(''));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Platform Sign In</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background-color: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .login-container {
        max-width: 450px; margin: 5% auto; padding: 2rem; background: #fff;
        border: 1px solid #b6ccfe; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,.08);
    }
    .brand { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #eee; }
    .brand i { font-size: 2.5rem; }
    .scope-note { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; font-size: .875rem; }
</style>
</head>
<body>
<div class="login-container">
    <div class="brand">
        <i class="bi bi-shield-lock-fill text-primary"></i>
        <h4 class="mt-2 mb-0">Platform Administration</h4>
        <small class="text-muted">Tenant management</small>
    </div>

    <div class="scope-note p-3 mb-3">
        <i class="bi bi-info-circle text-primary me-1"></i>
        This sign-in is for platform operators only. It is separate from your
        company account and does not sign you in to any company's system.
    </div>

    <div id="loginError" class="alert alert-danger d-none" role="alert"></div>

    <form id="superadminLoginForm" autocomplete="off" novalidate>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope text-primary"></i></span>
                <input type="email" class="form-control" id="email" name="email" required autofocus>
            </div>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-key text-primary"></i></span>
                <input type="password" class="form-control" id="password" name="password" required>
                <button class="btn btn-outline-secondary" type="button" id="togglePw" title="Show password">
                    <i class="bi bi-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100" id="btnSubmit">
            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
        </button>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$('#togglePw').on('click', function () {
    const $p = $('#password');
    const show = $p.attr('type') === 'password';
    $p.attr('type', show ? 'text' : 'password');
    $(this).find('i').attr('class', show ? 'bi bi-eye-slash' : 'bi bi-eye');
});

$('#superadminLoginForm').on('submit', function (e) {
    e.preventDefault();
    $('#loginError').addClass('d-none').text('');
    const $btn = $('#btnSubmit').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1"></span> Signing in...');

    $.ajax({
        url: '/actions/superadmin_login.php',
        method: 'POST',
        dataType: 'json',
        data: $(this).serialize(),
        success: function (res) {
            if (res && res.success) {
                window.location.href = '<?= saUrl('') ?>';
            } else {
                $('#loginError').removeClass('d-none').text((res && res.message) || 'Sign in failed.');
                $('#password').val('').focus();
            }
        },
        error: function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach the server. Please try again.' });
        },
        complete: function () {
            $btn.prop('disabled', false).html('<i class="bi bi-box-arrow-in-right me-1"></i> Sign In');
        }
    });
});
</script>
</body>
</html>
