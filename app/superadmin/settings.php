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
$provisioningMode = getPlatformSetting('tenant_default_provisioning', 'all');

// Email provider
$emailProvider  = getPlatformSetting('email_provider', 'own');
$sesRegion      = getPlatformSetting('ses_region', 'us-east-1');
$isManaged      = in_array($emailProvider, ['ses', 'mailgun', 'sendgrid'], true);
$providerNames  = ['ses' => 'Amazon SES', 'mailgun' => 'Mailgun', 'sendgrid' => 'SendGrid', 'own' => 'Own Server'];
$emailConfigured = $isManaged ? $smtpUsername !== '' : ($smtpHost !== '' && $smtpUsername !== '');

$userLabels  = ['ses' => 'SMTP Access Key ID', 'mailgun' => 'SMTP Username', 'sendgrid' => 'Username (always "apikey")', 'own' => 'Username'];
$passLabels  = ['ses' => 'SMTP Password',      'mailgun' => 'SMTP Password', 'sendgrid' => 'API Key',                    'own' => 'Password'];
$userHints   = [
    'ses'      => 'Generate SMTP credentials in AWS Console → SES → SMTP settings for the selected region.',
    'mailgun'  => 'Found in Mailgun → Sending → Domain settings → SMTP credentials.',
    'sendgrid' => 'Username must be the literal text <code>apikey</code> — it is auto-filled.',
    'own'      => '',
];
$curUserLabel = $userLabels[$emailProvider] ?? 'Username';
$curPassLabel = $passLabels[$emailProvider] ?? 'Password';
$curUserHint  = $userHints[$emailProvider]  ?? '';
$isAutoUser   = ($emailProvider === 'sendgrid');   // auto-fill username = "apikey"
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
                    <span class="badge <?= $emailConfigured ? 'bg-primary' : 'bg-secondary' ?>">
                        <?= $emailConfigured ? 'Configured' : 'Not configured' ?>
                    </span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Platform-wide relay — tenant welcome emails, broadcasts. Tenants with
                        <em>Use platform email</em> on (the default) also route their own mail here.
                    </p>
                    <form id="emailForm" autocomplete="off">
                        <input type="hidden" name="action" value="save_email">

                        <!-- ── Provider selector ─────────────────────────────── -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Provider</label>
                            <div class="d-flex flex-wrap gap-2">
                                <div>
                                    <input class="btn-check" type="radio" name="email_provider"
                                           id="ep_own" value="own"
                                           <?= $emailProvider === 'own' ? 'checked' : '' ?>>
                                    <label class="btn btn-sm btn-outline-secondary" for="ep_own">
                                        <i class="bi bi-server me-1"></i> Own Server
                                    </label>
                                </div>
                                <div>
                                    <input class="btn-check" type="radio" name="email_provider"
                                           id="ep_ses" value="ses"
                                           <?= $emailProvider === 'ses' ? 'checked' : '' ?>>
                                    <label class="btn btn-sm btn-outline-warning" for="ep_ses">
                                        <i class="bi bi-cloud me-1"></i> Amazon SES
                                    </label>
                                </div>
                                <div>
                                    <input class="btn-check" type="radio" name="email_provider"
                                           id="ep_mailgun" value="mailgun"
                                           <?= $emailProvider === 'mailgun' ? 'checked' : '' ?>>
                                    <label class="btn btn-sm btn-outline-danger" for="ep_mailgun">
                                        <i class="bi bi-mailbox2 me-1"></i> Mailgun
                                    </label>
                                </div>
                                <div>
                                    <input class="btn-check" type="radio" name="email_provider"
                                           id="ep_sendgrid" value="sendgrid"
                                           <?= $emailProvider === 'sendgrid' ? 'checked' : '' ?>>
                                    <label class="btn btn-sm btn-outline-success" for="ep_sendgrid">
                                        <i class="bi bi-grid-3x3-gap me-1"></i> SendGrid
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- ── SES Region (only shown for Amazon SES) ─────────── -->
                        <div id="ses_region_row" class="mb-3 <?= $emailProvider === 'ses' ? '' : 'd-none' ?>">
                            <label class="form-label">AWS Region <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" name="ses_region" id="f_ses_region">
                                <?php
                                $sesRegions = [
                                    'us-east-1'      => 'US East — N. Virginia (us-east-1)',
                                    'us-east-2'      => 'US East — Ohio (us-east-2)',
                                    'us-west-2'      => 'US West — Oregon (us-west-2)',
                                    'eu-west-1'      => 'Europe — Ireland (eu-west-1)',
                                    'eu-central-1'   => 'Europe — Frankfurt (eu-central-1)',
                                    'eu-west-2'      => 'Europe — London (eu-west-2)',
                                    'ap-south-1'     => 'Asia Pacific — Mumbai (ap-south-1)',
                                    'ap-southeast-1' => 'Asia Pacific — Singapore (ap-southeast-1)',
                                    'ap-southeast-2' => 'Asia Pacific — Sydney (ap-southeast-2)',
                                    'ap-northeast-1' => 'Asia Pacific — Tokyo (ap-northeast-1)',
                                    'ca-central-1'   => 'Canada — Central (ca-central-1)',
                                    'sa-east-1'      => 'South America — São Paulo (sa-east-1)',
                                    'af-south-1'     => 'Africa — Cape Town (af-south-1)',
                                    'me-south-1'     => 'Middle East — Bahrain (me-south-1)',
                                ];
                                foreach ($sesRegions as $rv => $rl): ?>
                                <option value="<?= $rv ?>" <?= $sesRegion === $rv ? 'selected' : '' ?>><?= $rl ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Generate SMTP credentials in <strong>AWS Console → SES → SMTP settings</strong> for this region.</div>
                        </div>

                        <!-- ── Managed provider info box (hidden for own server) ── -->
                        <div id="managed_info_row" class="mb-3 <?= $isManaged ? '' : 'd-none' ?>">
                            <div class="p-2 rounded border border-primary-subtle bg-primary-subtle text-primary-emphasis small d-flex align-items-start gap-2">
                                <i class="bi bi-plug-fill fs-5 mt-1 flex-shrink-0"></i>
                                <div>
                                    Connecting via <strong id="managed_provider_name"><?= safe_output($providerNames[$emailProvider] ?? '') ?></strong>
                                    — <code id="managed_host"><?= safe_output($smtpHost) ?></code>
                                    port <code id="managed_port"><?= safe_output($smtpPort, '587') ?></code>
                                    (<span id="managed_enc"><?= strtoupper(safe_output($smtpEncryption, 'TLS')) ?></span>).
                                    Host, port and encryption are fixed — no manual entry needed.
                                </div>
                            </div>
                        </div>

                        <!-- ── Own Server: host + port + encryption (hidden for managed) ── -->
                        <div id="own_server_row" class="row g-3 mb-3 <?= $isManaged ? 'd-none' : '' ?>">
                            <div class="col-md-8">
                                <label class="form-label">SMTP Host <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="smtp_host" id="f_host"
                                       value="<?= !$isManaged ? safe_output($smtpHost, '') : '' ?>"
                                       placeholder="mail.example.com"
                                       <?= !$isManaged ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Port <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="smtp_port" id="f_port"
                                       value="<?= !$isManaged ? safe_output($smtpPort, '587') : '587' ?>"
                                       min="1" max="65535"
                                       <?= !$isManaged ? 'required' : 'disabled' ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Encryption</label>
                                <select class="form-select form-select-sm" name="smtp_encryption" id="f_enc"
                                        <?= $isManaged ? 'disabled' : '' ?>>
                                    <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>TLS — port 587 (recommended)</option>
                                    <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL — port 465 (SMTPS)</option>
                                    <option value=""    <?= $smtpEncryption === ''    ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                        </div>
                        <!-- Hidden fields carry host/port/enc for managed providers (disabled ↔ own server) -->
                        <input type="hidden" id="f_host_h" name="smtp_host"       value="<?= $isManaged ? safe_output($smtpHost) : '' ?>"         <?= !$isManaged ? 'disabled' : '' ?>>
                        <input type="hidden" id="f_port_h" name="smtp_port"       value="<?= $isManaged ? safe_output($smtpPort, '587') : '' ?>"   <?= !$isManaged ? 'disabled' : '' ?>>
                        <input type="hidden" id="f_enc_h"  name="smtp_encryption" value="<?= $isManaged ? safe_output($smtpEncryption, 'tls') : '' ?>" <?= !$isManaged ? 'disabled' : '' ?>>

                        <!-- ── Credentials ────────────────────────────────────── -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" id="lbl_user"><?= safe_output($curUserLabel) ?> <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="smtp_username" id="f_user"
                                       value="<?= safe_output($smtpUsername, '') ?>"
                                       autocomplete="off" required
                                       <?= $isAutoUser ? 'readonly' : '' ?>>
                                <?php if ($curUserHint): ?>
                                <div class="form-text" id="user_hint"><?= $curUserHint ?></div>
                                <?php else: ?>
                                <div class="form-text d-none" id="user_hint"></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" id="lbl_pass"><?= safe_output($curPassLabel) ?><?= $hasSavedPassword ? '' : ' <span class="text-danger">*</span>' ?></label>
                                <input type="password" class="form-control" name="smtp_password" id="f_pass"
                                       autocomplete="new-password"
                                       <?= $hasSavedPassword ? '' : 'required' ?>>
                                <div class="form-text"><?= $hasSavedPassword ? 'Leave blank to keep the saved credential.' : 'Required on first setup.' ?></div>
                            </div>
                        </div>

                        <!-- ── Sender identity ────────────────────────────────── -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">From email</label>
                                <input type="email" class="form-control" name="from_email" id="f_from_email"
                                       value="<?= safe_output($fromEmail, '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">From name</label>
                                <input type="text" class="form-control" name="from_name" id="f_from_name"
                                       value="<?= safe_output($fromName, '') ?>"
                                       placeholder="<?= safe_output($platformName, 'BMS Platform') ?>">
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-1">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Save email settings
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="btnTestEmail">
                                <i class="bi bi-send me-1"></i> Send test email
                            </button>
                            <input type="email" class="form-control w-auto" id="f_test_to" placeholder="Recipient (optional)">
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Self-registration starting modules -->
        <div class="col-12 col-lg-6">
            <div class="card panel-card h-100">
                <div class="card-header"><i class="bi bi-signpost-split text-primary me-1"></i> Self-Registration Starting Modules</div>
                <div class="card-body">
                    <p class="text-muted small">
                        Governs ONLY a company that signs itself up through the public registration form —
                        nobody at the platform involved yet. Creating a company by hand from
                        <a href="<?= saUrl('tenants/new') ?>">New Company</a> always lets you pick a plan
                        explicitly there, regardless of this setting.
                    </p>
                    <form id="provisioningForm" autocomplete="off">
                        <input type="hidden" name="action" value="save_provisioning">
                        <div class="mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="provisioning_mode" id="pm_all"
                                       value="all" <?= $provisioningMode === 'all' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pm_all">
                                    <strong>Everything on</strong> — reduce afterward if needed (today's behaviour)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="provisioning_mode" id="pm_none"
                                       value="none" <?= $provisioningMode === 'none' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pm_none">
                                    <strong>Nothing but the base essentials</strong> — grant modules one at a time
                                    as the company asks or as agreed
                                </label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i> Save
                        </button>
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

// ── Email provider presets ────────────────────────────────────────────────────
const EMAIL_PROVIDERS = {
    ses:      { name: 'Amazon SES',  port: '587', enc: 'tls', userLabel: 'SMTP Access Key ID',          passLabel: 'SMTP Password', userHint: 'Generate SMTP credentials in AWS Console → SES → SMTP settings.', autoUser: '' },
    mailgun:  { name: 'Mailgun',     port: '587', enc: 'tls', userLabel: 'SMTP Username',               passLabel: 'SMTP Password', userHint: 'Found in Mailgun → Sending → Domain settings → SMTP credentials.',  autoUser: '' },
    sendgrid: { name: 'SendGrid',    port: '587', enc: 'tls', userLabel: 'Username',                    passLabel: 'API Key',       userHint: 'Username is always the literal text <code>apikey<\/code> — auto-filled.',   autoUser: 'apikey' },
};

function getSmtpValue(name) {
    // Returns the active (non-disabled) value for a named form field
    return $('[name="' + name + '"]:not([disabled])').val() || '';
}

function applyEmailProvider(provider) {
    const preset = EMAIL_PROVIDERS[provider];
    const isManaged = !!preset;
    const sesRegion = $('#f_ses_region').val() || 'us-east-1';

    if (isManaged) {
        let host = provider === 'ses' ? 'email-smtp.' + sesRegion + '.amazonaws.com' : '';
        if (provider === 'mailgun')  host = 'smtp.mailgun.org';
        if (provider === 'sendgrid') host = 'smtp.sendgrid.net';

        // Populate hidden managed fields, disable own-server fields
        $('#f_host_h').val(host).prop('disabled', false);
        $('#f_port_h').val(preset.port).prop('disabled', false);
        $('#f_enc_h').val(preset.enc).prop('disabled', false);
        $('#f_host').val('').prop('disabled', true).prop('required', false);
        $('#f_port').val('587').prop('disabled', true).prop('required', false);
        $('#f_enc').val(preset.enc).prop('disabled', true);

        // Show/hide sections
        $('#own_server_row').addClass('d-none');
        $('#managed_info_row').removeClass('d-none');
        $('#ses_region_row').toggleClass('d-none', provider !== 'ses');

        // Update info box
        $('#managed_provider_name').text(preset.name);
        $('#managed_host').text(host);
        $('#managed_port').text(preset.port);
        $('#managed_enc').text(preset.enc.toUpperCase());

        // Update labels
        $('#lbl_user').html(preset.userLabel + ' <span class="text-danger">*</span>');
        $('#lbl_pass').html(preset.passLabel + (<?= json_encode($hasSavedPassword) ?> ? '' : ' <span class="text-danger">*</span>'));
        $('#user_hint').html(preset.userHint).removeClass('d-none');

        // Auto-fill username for SendGrid
        if (preset.autoUser) {
            $('#f_user').val(preset.autoUser).prop('readonly', true);
        } else {
            $('#f_user').prop('readonly', false);
        }
    } else {
        // Own server: enable own fields, disable hidden managed
        $('#f_host').prop('disabled', false).prop('required', true);
        $('#f_port').prop('disabled', false).prop('required', true);
        $('#f_enc').prop('disabled', false);
        $('#f_host_h').val('').prop('disabled', true);
        $('#f_port_h').val('').prop('disabled', true);
        $('#f_enc_h').val('').prop('disabled', true);

        $('#own_server_row').removeClass('d-none');
        $('#managed_info_row').addClass('d-none');
        $('#ses_region_row').addClass('d-none');

        $('#lbl_user').html('Username <span class="text-danger">*</span>');
        $('#lbl_pass').html('Password' + (<?= json_encode($hasSavedPassword) ?> ? '' : ' <span class="text-danger">*</span>'));
        $('#user_hint').text('').addClass('d-none');
        $('#f_user').prop('readonly', false);
    }
}

// SES region change → update the displayed host
$('#f_ses_region').on('change', function () {
    const region = $(this).val();
    const host = 'email-smtp.' + region + '.amazonaws.com';
    $('#f_host_h').val(host);
    $('#managed_host').text(host);
});

// Provider radio change
$('[name="email_provider"]').on('change', function () {
    applyEmailProvider(this.value);
});

// ─────────────────────────────────────────────────────────────────────────────

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

$('#provisioningForm').on('submit', function (e) {
    e.preventDefault();
    submitSettingsForm(this, '/actions/superadmin_platform_settings.php', 'Self-registration setting updated');
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
            _csrf:           SA_CSRF_TOKEN,
            smtp_host:       getSmtpValue('smtp_host'),
            smtp_port:       getSmtpValue('smtp_port'),
            smtp_username:   $('#f_user').val(),
            smtp_password:   $('#f_pass').val(),
            smtp_encryption: getSmtpValue('smtp_encryption'),
            from_email:      $('#f_from_email').val(),
            from_name:       $('#f_from_name').val(),
            send_to:         $('#f_test_to').val()
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
