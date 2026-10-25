<?php
/**
 * app/superadmin/ai.php — Platform AI (platform_ai_plan.md).
 * One BJP-held AI key every entitled company uses by default.
 * Tabs: Settings · Companies · Usage. Control database only; the key is never
 * shown back — only whether one is saved.
 */
require_once __DIR__ . '/../../core/tenant_admin.php';
require_once __DIR__ . '/../../core/superadmin_ui.php';
require_once __DIR__ . '/../../core/ai_service.php';
require_once __DIR__ . '/../../helpers.php';

requireSuperadmin();
$me  = currentSuperadmin();
$tab = in_array($_GET['tab'] ?? '', ['settings'], true) ? $_GET['tab'] : 'settings';
$h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$s = aiPlatformSettings();
$keyOk = $s['has_key'] && aiPlatformSettings(true)['api_key'] !== '';
$secretOk = aiAppSecretOnDisk();
$monthAll = aiPlatformMonthSpend(null);
$money = fn($v) => '$' . number_format((float)$v, 2);
$models = [
    'openai'     => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini'],
    'anthropic'  => ['claude-haiku-4-5', 'claude-sonnet-4-5'],
    'gemini'     => ['gemini-2.5-flash', 'gemini-2.5-pro'],
    'openrouter' => ['openai/gpt-4o-mini', 'anthropic/claude-3.5-haiku'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>AI | Platform Administration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #fff; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .panel-card { border: 1px solid #b6ccfe; border-radius: 8px; }
    .panel-card .card-header { background: #e7f0ff; border-bottom: 1px solid #b6ccfe; font-weight: 600; }
    .stat { background: #e7f0ff; border: 1px solid #b6ccfe; border-radius: 8px; padding: 10px; text-align: center; }
    .stat b { display: block; font-size: 1.3rem; color: #0d6efd; }
</style>
</head>
<body>

<?php renderSuperadminHeader('ai', $me); ?>

<div class="container-fluid p-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div>
            <h5 class="mb-0 fw-bold"><i class="bi bi-stars text-primary me-1"></i> AI</h5>
            <div class="text-muted small">One AI key from BJP that every company with the AI Assistant module uses, with monthly limits.</div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <?php foreach (['settings' => ['bi-sliders', 'Settings']] as $k => [$ic, $lbl]): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="<?= $h(saUrl('ai?tab=' . $k)) ?>"><i class="bi <?= $ic ?> me-1"></i><?= $lbl ?></a></li>
        <?php endforeach; ?>
    </ul>

<?php if ($tab === 'settings'): ?>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="stat"><b><?= $s['enabled'] && $keyOk ? 'On' : 'Off' ?></b><span class="small text-muted">Platform AI</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= $keyOk ? 'Saved' : ($s['has_key'] ? 'Unreadable' : 'Missing') ?></b><span class="small text-muted">API key</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= $monthAll === null ? '—' : $money($monthAll) ?></b><span class="small text-muted">Spent this month (all companies)</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= $s['global_cap'] > 0 ? $money($s['global_cap']) : 'None' ?></b><span class="small text-muted">Platform monthly limit</span></div></div>
    </div>
    <?php if (!$secretOk): ?>
    <div class="alert alert-danger small"><i class="bi bi-shield-exclamation me-1"></i> The server encryption secret file <code>includes/ai_app_secret.php</code> is missing or not writable. The API key cannot be saved until it exists.</div>
    <?php elseif ($s['has_key'] && !$keyOk): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i> A key is saved but cannot be read on this server (the encryption secret changed). Enter the key again.</div>
    <?php endif; ?>

    <form id="aiForm" autocomplete="off">
        <input type="hidden" name="action" value="save_settings">
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card panel-card h-100">
                    <div class="card-header"><i class="bi bi-cpu text-primary me-1"></i> Provider</div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="ai_enabled" name="enabled" value="1"<?= $s['enabled'] ? ' checked' : '' ?>>
                            <label class="form-check-label" for="ai_enabled">Give AI to companies on the BJP key</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ai_provider">Provider</label>
                            <select class="form-select" id="ai_provider" name="provider">
                                <?php foreach (aiPlatformProviders() as $k => $lbl): ?>
                                <option value="<?= $h($k) ?>"<?= $s['provider'] === $k ? ' selected' : '' ?>><?= $h($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ai_model">Model</label>
                            <input type="text" class="form-control" id="ai_model" name="model" maxlength="100" list="ai_model_list" value="<?= $h($s['model']) ?>" placeholder="gpt-4o-mini">
                            <datalist id="ai_model_list"></datalist>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ai_key">API key</label>
                            <input type="password" class="form-control" id="ai_key" name="api_key" maxlength="512" autocomplete="new-password"
                                   placeholder="<?= $s['has_key'] ? 'A key is saved — leave blank to keep it' : 'Paste the provider API key' ?>">
                            <div class="form-text">Stored encrypted. It is never shown again.</div>
                            <?php if ($s['has_key']): ?>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="ai_remove_key" name="remove_key" value="1">
                                <label class="form-check-label small" for="ai_remove_key">Remove the saved key (switch AI off first)</label>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="ai_base_url">Base URL <span class="text-muted small">(optional — OpenRouter or a gateway)</span></label>
                            <input type="url" class="form-control" id="ai_base_url" name="base_url" maxlength="300" value="<?= $h($s['base_url']) ?>" placeholder="https://openrouter.ai/api/v1">
                            <div class="form-text">Must be a public https address.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card panel-card h-100">
                    <div class="card-header"><i class="bi bi-speedometer text-primary me-1"></i> Limits</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="ai_tenant_cap">Monthly limit per company (USD)</label>
                            <input type="number" class="form-control" id="ai_tenant_cap" name="tenant_cap" min="0" max="100000" step="0.01" value="<?= $h($s['tenant_cap']) ?>">
                            <div class="form-text">Default for every company; change one company on the Companies tab. 0 = no limit.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ai_global_cap">Monthly limit for all companies together (USD)</label>
                            <input type="number" class="form-control" id="ai_global_cap" name="global_cap" min="0" max="1000000" step="0.01" value="<?= $h($s['global_cap']) ?>">
                            <div class="form-text">A safety ceiling on BJP's bill. 0 = no limit.</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="ai_temperature">Creativity (0 – 1)</label>
                            <input type="number" class="form-control" id="ai_temperature" name="temperature" min="0" max="1" step="0.05" value="<?= $h($s['temperature']) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save AI settings</button>
            <button type="button" class="btn btn-outline-primary" id="btnTest"<?= $keyOk ? '' : ' disabled' ?>><i class="bi bi-plug me-1"></i> Test connection</button>
        </div>
        <p class="text-muted small mt-2 mb-0">Companies only get AI when the AI Assistant module is on for them (Modules / Plans). A company that already uses its own key keeps it until you change it on the Companies tab.</p>
    </form>
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const SA_CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const AI_URL = '/actions/superadmin_ai.php';
const AI_MODELS = <?= json_encode($models) ?>;

function saPost(data, btn, busy) {
    const orig = btn ? btn.html() : null;
    if (btn) btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + (busy || 'Saving...'));
    data.push({ name: '_csrf', value: SA_CSRF_TOKEN });
    return $.ajax({ url: AI_URL, method: 'POST', dataType: 'json', data: $.param(data) })
        .fail(function (xhr) {
            let msg = 'The change could not be saved.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (err) {}
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
        })
        .always(function () { if (btn) btn.prop('disabled', false).html(orig); });
}
function done(res, reload) {
    if (res && res.success) {
        Swal.fire({ icon: 'success', title: 'Saved', text: res.message, timer: 1600, showConfirmButton: false });
        if (reload) setTimeout(function () { location.reload(); }, 900);
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'The change could not be saved.' });
    }
}
function fillModels() {
    const list = $('#ai_model_list').empty();
    (AI_MODELS[$('#ai_provider').val()] || []).forEach(function (m) { list.append($('<option>').attr('value', m)); });
}
$('#ai_provider').on('change', fillModels); fillModels();

$('#aiForm').on('submit', function (e) {
    e.preventDefault();
    saPost($(this).serializeArray(), $(this).find('[type="submit"]')).done(function (r) {
        $('#ai_key').val('');
        done(r, true);
    });
});
$('#btnTest').on('click', function () {
    saPost([{ name: 'action', value: 'test' }], $(this), 'Testing...').done(function (r) {
        Swal.fire({ icon: r && r.success ? 'success' : 'error', title: r && r.success ? 'Connected' : 'Test failed', text: (r && r.message) || '' });
    });
});
</script>
</body>
</html>
