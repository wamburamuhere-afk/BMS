<?php
/**
 * app/superadmin/settings.php — platform-wide branding + email (SMTP) settings.
 *
 * Control database only. The SMTP credentials configured here are used for
 * platform-originated mail ONLY — tenant welcome emails, broadcasts — never a
 * tenant's own transactional email, which continues to use that tenant's own
 * system_settings exactly as it does today. See core/platform_settings.php's
 * docblock for why this is a separate store rather than reusing any tenant's.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/platform_settings.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();

$me = currentSuperadmin();

$platformName  = getPlatformSetting('platform_name', 'BMS Platform');
$smtpHost      = getPlatformSetting('smtp_host');
$smtpPort      = getPlatformSetting('smtp_port', '587');
$smtpUsername  = getPlatformSetting('smtp_username');
$smtpEncryption = getPlatformSetting('smtp_encryption', 'tls');
$fromEmail     = getPlatformSetting('from_email');
$fromName      = getPlatformSetting('from_name');
$hasSavedPassword = getPlatformSetting('smtp_password_enc') !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Platform Settings | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .panel-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .panel-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
</style>
</head>
<body>

<?php renderSuperadminHeader('settings', $me); ?>

<div class="container-fluid p-3">
    <h5 class="mb-3 fw-bold"><i class="bi bi-gear-wide-connected text-primary me-1"></i> Platform Settings</h5>

    <div class="row g-3">

        <!-- Branding -->
        <div class="col-12 col-lg-5">
            <div class="card panel-card h-100">
                <div class="card-header"><i class="bi bi-badge-tm text-primary me-1"></i> Branding</div>
                <div class="card-body">
                    <form id="brandingForm" autocomplete="off">
                        <input type="hidden" name="action" value="save_branding">
                        <div class="mb-3">
                            <label class="form-label">Platform name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="platform_name" required maxlength="191"
                                   value="<?= safe_output($platformName, '') ?>">
                            <div class="form-text">Shown as the default sender name on platform-originated email (welcome messages, announcements) when no From Name is set below.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Save branding
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Email / SMTP -->
        <div class="col-12 col-lg-7">
            <div class="card panel-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-envelope-at text-primary me-1"></i> Email (SMTP)</span>
                    <span class="badge <?= $smtpHost !== '' && $smtpUsername !== '' ? 'bg-primary' : 'bg-secondary' ?>">
                        <?= $smtpHost !== '' && $smtpUsername !== '' ? 'Configured' : 'Not configured' ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        Used only for mail the <em>platform</em> sends — tenant welcome emails, broadcasts to
                        tenant owners. A tenant's own outgoing email (invoices, notifications to their
                        customers) is unaffected and keeps using that company's own Settings &gt; Email.
                    </p>
                    <form id="emailForm" autocomplete="off">
                        <input type="hidden" name="action" value="save_email">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">SMTP Host <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="smtp_host" id="f_host" required
                                       value="<?= safe_output($smtpHost, '') ?>" placeholder="smtp.gmail.com">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Port <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="smtp_port" id="f_port" required
                                       value="<?= safe_output($smtpPort, '587') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="smtp_username" id="f_user" required
                                       value="<?= safe_output($smtpUsername, '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password<?= $hasSavedPassword ? '' : ' <span class="text-danger">*</span>' ?></label>
                                <input type="password" class="form-control" name="smtp_password" id="f_pass" autocomplete="new-password"
                                       <?= $hasSavedPassword ? '' : 'required' ?>>
                                <div class="form-text"><?= $hasSavedPassword ? 'Leave blank to keep the saved password.' : 'Required on first setup.' ?></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Encryption</label>
                                <select class="form-select" name="smtp_encryption" id="f_enc">
                                    <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                    <option value="" <?= $smtpEncryption === '' ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">From email</label>
                                <input type="email" class="form-control" name="from_email" id="f_from_email"
                                       value="<?= safe_output($fromEmail, '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">From name</label>
                                <input type="text" class="form-control" name="from_name" id="f_from_name"
                                       value="<?= safe_output($fromName, '') ?>" placeholder="<?= safe_output($platformName, 'BMS Platform') ?>">
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Save email settings
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="btnTestEmail">
                                <i class="bi bi-send me-1"></i> Send test email
                            </button>
                            <input type="email" class="form-control w-auto" id="f_test_to" placeholder="Send test to (optional)">
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

function submitSettingsForm(form, url, successTitle, onDone) {
    const $f   = $(form);
    const btn  = $f.find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    const data = $f.serializeArray();
    data.push({ name: '_csrf', value: SA_CSRF_TOKEN });

    $.ajax({ url: url, method: 'POST', dataType: 'json', data: $.param(data) })
        .done(function (res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: successTitle, text: res.message, timer: 2200, showConfirmButton: false });
                if (onDone) onDone();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'The change could not be saved.' });
            }
        })
        .fail(function (xhr) {
            let msg = 'The change could not be saved.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
        })
        .always(function () { btn.prop('disabled', false).html(orig); });
}

$('#brandingForm').on('submit', function (e) {
    e.preventDefault();
    submitSettingsForm(this, '/actions/superadmin_platform_settings.php', 'Branding updated', function () {
        setTimeout(function () { window.location.reload(); }, 2200);
    });
});

$('#emailForm').on('submit', function (e) {
    e.preventDefault();
    submitSettingsForm(this, '/actions/superadmin_platform_settings.php', 'Email settings updated', function () {
        setTimeout(function () { window.location.reload(); }, 2200);
    });
});

$('#btnTestEmail').on('click', function () {
    const btn = $(this);
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Sending...');

    $.ajax({
        url: '/actions/superadmin_test_platform_email.php',
        method: 'POST',
        dataType: 'json',
        data: {
            _csrf: SA_CSRF_TOKEN,
            smtp_host: $('#f_host').val(),
            smtp_port: $('#f_port').val(),
            smtp_username: $('#f_user').val(),
            smtp_password: $('#f_pass').val(),
            smtp_encryption: $('#f_enc').val(),
            from_email: $('#f_from_email').val(),
            from_name: $('#f_from_name').val(),
            send_to: $('#f_test_to').val()
        }
    }).done(function (res) {
        if (res && res.success) {
            Swal.fire({ icon: 'success', title: 'Sent', text: res.message });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Send failed.' });
        }
    }).fail(function (xhr) {
        let msg = 'Send failed.';
        try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
        Swal.fire({ icon: 'error', title: 'Error', text: msg });
    }).always(function () { btn.prop('disabled', false).html(orig); });
});
</script>
</body>
</html>
