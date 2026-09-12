<?php
/**
 * app/constant/settings/zoom_settings.php
 * Configuration for the Zoom integration (plan: zoom.md, Phase 1).
 * Strictly admin-only by design — not delegable via Roles & Permissions no
 * matter what is granted (the 'zoom_settings' permission row is hidden from
 * that UI entirely). Reached only via Settings > Admin. The Client Secret
 * is stored ENCRYPTED — this page never shows it back, only whether one is
 * set, with a "leave blank to keep" pattern.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';

autoEnforcePermission('zoom_settings');
if (!isAdmin()) { header('Location: ' . getUrl('unauthorized')); exit; }

logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed Zoom Settings');
require_once __DIR__ . '/../../../header.php';

$enabled     = getSetting('zoom_enabled', '0') === '1';
$accountId   = getSetting('zoom_account_id', '');
$clientId    = getSetting('zoom_client_id', '');
$hasSecret   = getSetting('zoom_client_secret_enc', '') !== '';
$hostEmail   = getSetting('zoom_host_email', '');
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-camera-video text-primary me-2"></i><?= t('Zoom Integration') ?></h4>
            <p class="text-muted mb-0"><?= t('Connect a Zoom Server-to-Server OAuth app so staff can schedule real Zoom meetings from Meetings.') ?></p>
        </div>
        <span class="badge rounded-pill bg-<?= $enabled ? 'primary' : 'secondary' ?>">
            <?= $enabled ? t('Enabled') : t('Disabled') ?>
        </span>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-gear me-1"></i> <?= t('Server-to-Server OAuth Credentials') ?>
                </div>
                <div class="card-body">
                    <form id="zoomSettingsForm" autocomplete="off">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="zoom_enabled" name="zoom_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="zoom_enabled"><?= t('Enable Zoom meetings') ?></label>
                            <div class="form-text"><?= t('When off, the "Zoom" option is hidden in Meetings and no calls are made to Zoom.') ?></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?= t('Account ID') ?></label>
                            <input type="text" class="form-control" name="zoom_account_id" value="<?= htmlspecialchars($accountId) ?>" placeholder="<?= t('Zoom Account ID') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('Client ID') ?></label>
                            <input type="text" class="form-control" name="zoom_client_id" value="<?= htmlspecialchars($clientId) ?>" placeholder="<?= t('Client ID') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('Client Secret') ?></label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="zoom_client_secret" name="zoom_client_secret"
                                       placeholder="<?= $hasSecret ? t('•••••••• (secret is set — leave blank to keep)') : t('Paste the Client Secret') ?>">
                                <button type="button" class="btn btn-outline-secondary" id="btnToggleSecret" title="<?= t('Show/Hide') ?>"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="form-text">
                                <i class="bi bi-shield-lock"></i> <?= t('Stored encrypted on this server. We never display it again.') ?>
                                <?= $hasSecret ? '<span class="text-success">' . t('A secret is currently set.') . '</span>' : '<span class="text-warning">' . t('No secret set yet.') . '</span>' ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?= t('Zoom Host Email') ?></label>
                            <input type="email" class="form-control" name="zoom_host_email" value="<?= htmlspecialchars($hostEmail) ?>" placeholder="<?= t('e.g. meetings@yourcompany.co.tz') ?>">
                            <div class="form-text">
                                <?= t('The one Zoom account BMS creates every meeting under. It must be a real user in this Zoom account (the one tied to the Account ID above) — usually the email you used to set up the Zoom app.') ?>
                                <?= t('Staff scheduling meetings in BMS do') ?> <strong><?= t('not') ?></strong> <?= t('need their own Zoom login.') ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> <?= t('Save Settings') ?></button>
                            <button type="button" class="btn btn-outline-primary" id="btnTestZoom"><i class="bi bi-plug me-1"></i> <?= t('Test Connection') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> <?= t('How it works') ?></div>
                <div class="card-body small text-muted">
                    <p><?= t('BMS uses a') ?> <strong><?= t('Server-to-Server OAuth') ?></strong> <?= t('app on your own Zoom account — the mechanism Zoom recommends for a single-company internal system (no per-user Zoom login needed).') ?></p>
                    <p class="mb-0"><?= t('Create one under Zoom App Marketplace → Build App → Server-to-Server OAuth, then paste the Account ID / Client ID / Client Secret here.') ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {
    $('#btnToggleSecret').on('click', function () {
        const f = document.getElementById('zoom_client_secret');
        f.type = f.type === 'password' ? 'text' : 'password';
        $(this).find('i').toggleClass('bi-eye bi-eye-slash');
    });

    $('#zoomSettingsForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + <?= json_encode(t('Saving…')) ?>);
        $.ajax({
            url: '<?= buildUrl('api/zoom/save_zoom_settings.php') ?>', type: 'POST',
            data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: r => r.success
                ? Swal.fire({ icon: 'success', title: <?= json_encode(t('Saved!')) ?>, text: r.message, timer: 1800, showConfirmButton: false }).then(() => location.reload())
                : Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: r.message || <?= json_encode(t('Could not save.')) ?> }),
            error: () => Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: <?= json_encode(t('Server error.')) ?> }),
            complete: () => btn.prop('disabled', false).html(orig)
        });
    });

    $('#btnTestZoom').on('click', function () {
        const btn = $(this); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + <?= json_encode(t('Testing…')) ?>);
        Swal.fire({ title: <?= json_encode(t('Contacting Zoom…')) ?>, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '<?= buildUrl('api/zoom/test_zoom_config.php') ?>', type: 'POST',
            data: { _csrf: CSRF_TOKEN }, dataType: 'json',
            success: r => r.success
                ? Swal.fire({ icon: 'success', title: <?= json_encode(t('Connected ✓')) ?>, text: r.message })
                : Swal.fire({ icon: 'error', title: <?= json_encode(t('Not connected')) ?>, text: r.message || <?= json_encode(t('Test failed.')) ?> }),
            error: () => Swal.fire({ icon: 'error', title: <?= json_encode(t('Error')) ?>, text: <?= json_encode(t('Server error.')) ?> }),
            complete: () => btn.prop('disabled', false).html(orig)
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../../footer.php'; ?>
