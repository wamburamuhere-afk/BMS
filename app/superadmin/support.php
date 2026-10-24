<?php
/**
 * app/superadmin/support.php — BJP's Help & Support setup (support_center_plan.md).
 * Control database only: tenants never read or edit these values.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/support.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();
$me = currentSuperadmin();
$s  = supportSettings();
$e  = fn(string $k) => safe_output($s[$k] ?? '', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Support | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .panel-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .panel-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
</style>
</head>
<body>

<?php renderSuperadminHeader('support', $me); ?>

<div class="container-fluid p-3">
    <h5 class="mb-1 fw-bold"><i class="bi bi-headset text-primary me-1"></i> Support</h5>
    <p class="text-muted small mb-3">How tenants reach BJP. Contact details are shown to tenant admins only.</p>

    <form id="supportForm" autocomplete="off">
        <input type="hidden" name="action" value="save_support">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card panel-card h-100">
                    <div class="card-header"><i class="bi bi-telephone text-primary me-1"></i> Contact details</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="support_email">Support email</label>
                            <input type="email" class="form-control" id="support_email" name="support_email" maxlength="191" value="<?= $e('support_email') ?>">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="support_phone">Phone</label>
                                <input type="text" class="form-control" id="support_phone" name="support_phone" maxlength="30" placeholder="+255 …" value="<?= $e('support_phone') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="support_whatsapp">WhatsApp</label>
                                <input type="text" class="form-control" id="support_whatsapp" name="support_whatsapp" maxlength="30" placeholder="+255 …" value="<?= $e('support_whatsapp') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="support_hours">Office hours</label>
                            <input type="text" class="form-control" id="support_hours" name="support_hours" maxlength="191" placeholder="Mon–Fri 08:00–17:00 EAT" value="<?= $e('support_hours') ?>">
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="support_login_help">Login page help text</label>
                            <textarea class="form-control" id="support_login_help" name="support_login_help" rows="2" maxlength="500"><?= $e('support_login_help') ?></textarea>
                            <div class="form-text">Shown under "Need help signing in?" on every tenant's login page.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card panel-card h-100">
                    <div class="card-header"><i class="bi bi-inbox text-primary me-1"></i> Requests</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="support_recipients">Who receives new requests</label>
                            <input type="text" class="form-control" id="support_recipients" name="support_recipients" placeholder="agent1@…, agent2@…" value="<?= $e('support_recipients') ?>">
                            <div class="form-text">Email addresses, separated by commas. Empty = every superadmin.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="support_autoreply">Auto-reply text</label>
                            <textarea class="form-control" id="support_autoreply" name="support_autoreply" rows="2" maxlength="1000"><?= $e('support_autoreply') ?></textarea>
                        </div>
                        <label class="form-label">First-reply targets (business hours)</label>
                        <div class="row g-2 mb-3">
                            <?php foreach (['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $k => $lbl): ?>
                            <div class="col-6 col-md-3">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><?= $lbl ?></span>
                                    <input type="number" min="1" class="form-control" name="support_sla_<?= $k ?>" aria-label="<?= $lbl ?> target in hours" value="<?= $e('support_sla_' . $k) ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label" for="support_autoclose_days">Close resolved requests after (days)</label>
                                <input type="number" min="1" max="60" class="form-control" id="support_autoclose_days" name="support_autoclose_days" value="<?= $e('support_autoclose_days') ?>">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="support_attach_max_mb">Attachment limit (MB)</label>
                                <input type="number" min="1" max="20" class="form-control" id="support_attach_max_mb" name="support_attach_max_mb" value="<?= $e('support_attach_max_mb') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save support settings</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const SA_CSRF_TOKEN = '<?= csrf_token() ?>';
$('#supportForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    const data = $(this).serializeArray();
    data.push({ name: '_csrf', value: SA_CSRF_TOKEN });
    $.ajax({ url: '/actions/superadmin_support_settings.php', method: 'POST', dataType: 'json', data: $.param(data) })
        .done(function (res) {
            if (res && res.success) {
                Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 2000, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'The settings could not be saved.' });
            }
        })
        .fail(function (xhr) {
            let msg = 'The settings could not be saved.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (err) {}
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
        })
        .always(function () { btn.prop('disabled', false).html(orig); });
});
</script>
</body>
</html>
