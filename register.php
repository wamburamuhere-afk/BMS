<?php
/**
 * register.php — public company self-registration.
 *
 * Standalone by design: it never boots roots.php, because there is no tenant and
 * no signed-in user to load. Reachable only on the platform's root domain — a
 * tenant's own subdomain returns 404, since a visitor there is that company's
 * customer, not a prospect.
 */
require_once __DIR__ . '/core/tenant_registration.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

$closed  = selfRegistrationClosedReason();
$baseDom = tenantBaseDomain();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create your company account</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .signup-card {
        max-width: 560px; margin: 4% auto; padding: 2rem;
        border: 1px solid #b6ccfe; border-radius: 10px; box-shadow: 0 5px 15px rgba(0,0,0,.08);
    }
    .brand { text-align: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #eee; }
    .brand i { font-size: 2.5rem; }
    /* Honeypot: hidden from people, visible to naive bots. Not type=hidden,
       which bots skip — it must look like a real field in the DOM. */
    .hp-field { position: absolute; left: -9999px; top: -9999px; height: 0; overflow: hidden; }
    .subdomain-hint { font-size: .875rem; min-height: 1.25rem; }
    .progress-note { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; }
</style>
</head>
<body>
<div class="signup-card">
    <div class="brand">
        <i class="bi bi-building-add text-primary"></i>
        <h4 class="mt-2 mb-0">Create your company account</h4>
        <small class="text-muted">Your own private system, ready in a moment</small>
    </div>

<?php if ($closed): ?>
    <div class="alert alert-secondary text-center mb-0">
        <i class="bi bi-info-circle me-1"></i><?= safe_output($closed, '') ?>
    </div>
<?php else: ?>

    <div id="formError" class="alert alert-danger d-none" role="alert"></div>

    <form id="registerForm" autocomplete="off" novalidate>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <!-- Honeypot. Left blank by humans; bots fill it and are refused. -->
        <div class="hp-field" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="mb-3">
            <label for="company_name" class="form-label">Company name</label>
            <input type="text" class="form-control" id="company_name" name="company_name" maxlength="191" required autofocus>
        </div>

        <div class="mb-1">
            <label for="subdomain" class="form-label">Choose your web address</label>
            <div class="input-group">
                <input type="text" class="form-control" id="subdomain" name="subdomain"
                       maxlength="32" pattern="[a-z0-9-]+" placeholder="yourcompany" required>
                <span class="input-group-text">.<?= safe_output($baseDom ?? 'example.com', '') ?></span>
            </div>
        </div>
        <div class="subdomain-hint text-muted mb-3" id="subHint">
            Lowercase letters, numbers and hyphens. 3–32 characters.
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <label for="owner_first_name" class="form-label">First name</label>
                <input type="text" class="form-control" id="owner_first_name" name="owner_first_name" maxlength="100">
            </div>
            <div class="col-6">
                <label for="owner_last_name" class="form-label">Last name</label>
                <input type="text" class="form-control" id="owner_last_name" name="owner_last_name" maxlength="100">
            </div>
        </div>

        <div class="mb-3">
            <label for="owner_email" class="form-label">Your email</label>
            <input type="email" class="form-control" id="owner_email" name="owner_email" maxlength="191" required>
            <div class="form-text">You will sign in with this address.</div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label for="owner_password" class="form-label">Password</label>
                <input type="password" class="form-control" id="owner_password" name="owner_password" required>
                <div class="form-text">At least 8 characters, with a letter and a number.</div>
            </div>
            <div class="col-md-6">
                <label for="owner_password_confirm" class="form-label">Confirm password</label>
                <input type="password" class="form-control" id="owner_password_confirm" name="owner_password_confirm" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100" id="btnSubmit">
            <i class="bi bi-check-circle me-1"></i> Create my account
        </button>
    </form>

    <div id="settingUp" class="progress-note p-4 text-center d-none">
        <div class="spinner-border text-primary mb-2" role="status"></div>
        <div class="fw-bold">Setting up your account…</div>
        <small class="text-muted">Creating your private database. This takes a few seconds — please don't close this page.</small>
    </div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Keep the field itself canonical — the server lowercases anyway, but showing
// the user what will actually be registered avoids a surprising correction.
$('#subdomain').on('input', function () {
    const clean = $(this).val().toLowerCase().replace(/[^a-z0-9-]/g, '');
    if (clean !== $(this).val()) $(this).val(clean);
    scheduleCheck();
});

let checkTimer = null;
function scheduleCheck() {
    clearTimeout(checkTimer);
    checkTimer = setTimeout(checkSubdomain, 350);   // debounce keystrokes
}

function checkSubdomain() {
    const sub = $('#subdomain').val();
    const $hint = $('#subHint');
    if (!sub) {
        $hint.removeClass('text-danger text-primary').addClass('text-muted')
             .text('Lowercase letters, numbers and hyphens. 3–32 characters.');
        return;
    }
    $.getJSON('/ajax/check_subdomain_availability.php', { subdomain: sub })
        .done(function (res) {
            $hint.removeClass('text-muted text-danger text-primary');
            if (res.available) {
                $hint.addClass('text-primary').html('<i class="bi bi-check-circle me-1"></i>' + res.message);
            } else {
                $hint.addClass('text-danger').html('<i class="bi bi-x-circle me-1"></i>' + (res.message || 'Not available.'));
            }
        })
        .fail(function () {
            $hint.removeClass('text-primary').addClass('text-muted').text('Could not check availability.');
        });
}

$('#registerForm').on('submit', function (e) {
    e.preventDefault();
    $('#formError').addClass('d-none').text('');

    if ($('#owner_password').val() !== $('#owner_password_confirm').val()) {
        $('#formError').removeClass('d-none').text('The two passwords do not match.');
        return;
    }

    $('#registerForm').addClass('d-none');
    $('#settingUp').removeClass('d-none');

    $.ajax({
        url: '/actions/register_tenant.php',
        method: 'POST',
        dataType: 'json',
        data: $(this).serialize(),
        timeout: 120000,                 // provisioning builds ~300 tables
        success: function (res) {
            if (res && res.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Your account is ready',
                    text: 'Taking you to your sign-in page…',
                    timer: 2200,
                    showConfirmButton: false
                });
                setTimeout(function () { window.location.href = res.login_url; }, 2200);
            } else {
                showFormError((res && res.message) || 'Registration failed.');
            }
        },
        error: function (xhr) {
            let msg = 'Registration failed. Please try again.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
            showFormError(msg);
        }
    });
});

function showFormError(msg) {
    $('#settingUp').addClass('d-none');
    $('#registerForm').removeClass('d-none');
    $('#formError').removeClass('d-none').text(msg);
    $('html, body').animate({ scrollTop: 0 }, 200);
}
</script>
</body>
</html>
