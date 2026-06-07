<?php
/**
 * app/constant/settings/ai_settings.php
 * Admin configuration for the AI Assistant (plan: ai_assistant.md, Phase 1).
 * Admin-only. The API key is stored ENCRYPTED — this page never shows it back,
 * only whether one is set, with a "Replace key" action.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/ai_service.php';

autoEnforcePermission('ai_assistant');
if (!isAdmin()) { header('Location: ' . getUrl('unauthorized')); exit; }

require_once __DIR__ . '/../../../header.php';

$enabled   = getSetting('ai_enabled', '0') === '1';
$provider  = getSetting('ai_provider', 'openai');
$model     = getSetting('ai_model', 'gpt-4o-mini');
$baseUrl   = getSetting('ai_base_url', '');
$costCap   = getSetting('ai_monthly_cost_cap', '0');
$temp      = getSetting('ai_temperature', '0.4');
$hasKey    = getSetting('ai_api_key_enc', '') !== '';
$cap       = aiCapInfo();

$providers = [
    'openai'     => 'OpenAI  (gpt-4o-mini, gpt-4o …)',
    'anthropic'  => 'Anthropic  (claude-haiku-4-5, claude-sonnet …)',
    'gemini'     => 'Google Gemini  (gemini-2.0-flash …)',
    'openrouter' => 'OpenRouter  (any model via base URL)',
];
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-stars text-primary me-2"></i>AI Assistant</h4>
            <p class="text-muted mb-0">Connect an AI provider to power "Generate with AI" and "Ask BMS".</p>
        </div>
        <span class="badge rounded-pill bg-<?= $enabled ? 'primary' : 'secondary' ?>">
            <?= $enabled ? 'Enabled' : 'Disabled' ?>
        </span>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-gear me-1"></i> Provider Configuration
                </div>
                <div class="card-body">
                    <form id="aiSettingsForm" autocomplete="off">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="ai_enabled" name="ai_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="ai_enabled">Enable the AI Assistant</label>
                            <div class="form-text">When off, every AI button/menu is hidden and no calls are made.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Provider</label>
                                <select class="form-select select2-static" id="ai_provider" name="ai_provider">
                                    <?php foreach ($providers as $val => $label): ?>
                                    <option value="<?= $val ?>" <?= $provider === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Model</label>
                                <input type="text" class="form-control" id="ai_model" name="ai_model" value="<?= htmlspecialchars($model) ?>" placeholder="e.g. gpt-4o-mini">
                                <div class="form-text">The model id for your provider.</div>
                            </div>
                        </div>

                        <div class="mb-3 mt-3">
                            <label class="form-label">API Key</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="ai_api_key" name="ai_api_key"
                                       placeholder="<?= $hasKey ? '•••••••• (key is set — leave blank to keep)' : 'Paste your provider API key' ?>">
                                <button type="button" class="btn btn-outline-secondary" id="btnToggleKey" title="Show/Hide"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="form-text">
                                <i class="bi bi-shield-lock"></i> Stored encrypted on this server. We never display it again.
                                <?= $hasKey ? '<span class="text-success">A key is currently set.</span>' : '<span class="text-warning">No key set yet.</span>' ?>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Base URL <span class="text-muted small">(optional)</span></label>
                                <input type="text" class="form-control" id="ai_base_url" name="ai_base_url" value="<?= htmlspecialchars($baseUrl) ?>" placeholder="https://openrouter.ai/api/v1">
                                <div class="form-text">For OpenRouter / self-hosted gateways (OpenAI-compatible).</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Monthly cost cap (USD)</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="ai_monthly_cost_cap" name="ai_monthly_cost_cap" value="<?= htmlspecialchars($costCap) ?>">
                                <div class="form-text">0 = unlimited.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Creativity</label>
                                <input type="number" step="0.1" min="0" max="1" class="form-control" id="ai_temperature" name="ai_temperature" value="<?= htmlspecialchars($temp) ?>">
                                <div class="form-text">0 = precise, 1 = creative.</div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save Settings</button>
                            <button type="button" class="btn btn-outline-primary" id="btnTestAi"><i class="bi bi-plug me-1"></i> Test Connection</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-graph-up text-primary me-1"></i> This Month's Usage</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between"><span class="text-muted">Spent (est.)</span><span class="fw-bold">$<?= number_format($cap['spent'], 4) ?></span></div>
                    <div class="d-flex justify-content-between"><span class="text-muted">Cap</span><span class="fw-bold"><?= $cap['cap'] > 0 ? '$' . number_format($cap['cap'], 2) : 'Unlimited' ?></span></div>
                    <?php if ($cap['cap'] > 0): ?>
                    <div class="progress mt-2" style="height:6px;">
                        <div class="progress-bar <?= $cap['exceeded'] ? 'bg-danger' : '' ?>" style="width: <?= min(100, $cap['cap'] ? ($cap['spent'] / $cap['cap'] * 100) : 0) ?>%"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> How it works</div>
                <div class="card-body small text-muted">
                    <p>BMS uses <strong>your own</strong> provider account, so you control the model and the bill.</p>
                    <p class="mb-0">The assistant only reads <strong>summary figures</strong> from your data to answer questions — it never sees raw records and can never change anything.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {
    if ($.fn.select2) $('#ai_provider').select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: Infinity });

    $('#btnToggleKey').on('click', function () {
        const f = document.getElementById('ai_api_key');
        f.type = f.type === 'password' ? 'text' : 'password';
        $(this).find('i').toggleClass('bi-eye bi-eye-slash');
    });

    $('#aiSettingsForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving…');
        $.ajax({
            url: '<?= buildUrl('api/ai/save_ai_settings.php') ?>', type: 'POST',
            data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: r => r.success
                ? Swal.fire({ icon: 'success', title: 'Saved!', text: r.message, timer: 1800, showConfirmButton: false }).then(() => location.reload())
                : Swal.fire({ icon: 'error', title: 'Error', text: r.message || 'Could not save.' }),
            error: () => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }),
            complete: () => btn.prop('disabled', false).html(orig)
        });
    });

    $('#btnTestAi').on('click', function () {
        const btn = $(this); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Testing…');
        Swal.fire({ title: 'Contacting provider…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: '<?= buildUrl('api/ai/test_ai_config.php') ?>', type: 'POST',
            data: { _csrf: CSRF_TOKEN }, dataType: 'json',
            success: r => r.success
                ? Swal.fire({ icon: 'success', title: 'Connected ✓', text: r.message })
                : Swal.fire({ icon: 'error', title: 'Not connected', text: r.message || 'Test failed.' }),
            error: () => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }),
            complete: () => btn.prop('disabled', false).html(orig)
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../../footer.php'; ?>
