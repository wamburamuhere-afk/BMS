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
$tab = in_array($_GET['tab'] ?? '', ['settings', 'companies', 'usage'], true) ? $_GET['tab'] : 'settings';
$h   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fQ      = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$fMode   = in_array($_GET['mode'] ?? '', ['auto', 'platform', 'own', 'off'], true) ? (string)$_GET['mode'] : '';
$fModule = in_array($_GET['module'] ?? '', ['on', 'off'], true) ? (string)$_GET['module'] : '';
$companies = $tab === 'companies' ? aiPlatformCompanies(['q' => $fQ, 'mode' => $fMode, 'module' => $fModule]) : [];
$usage     = $tab === 'usage' ? aiPlatformUsageSummary() : [];
$modeLabels = ['auto' => 'Auto', 'platform' => 'BJP key', 'own' => 'Own key', 'off' => 'Off'];
$modeHelp = [
    'auto'     => 'Their own key if they have one, otherwise the BJP key.',
    'platform' => 'Always the BJP key, with the monthly limit.',
    'own'      => 'Only their own key (set in their AI Settings).',
    'off'      => 'No AI for this company.',
];

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
        <?php foreach (['settings' => ['bi-sliders', 'Settings'], 'companies' => ['bi-building', 'Companies'], 'usage' => ['bi-graph-up', 'Usage']] as $k => [$ic, $lbl]): ?>
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
<?php elseif ($tab === 'companies'): ?>
    <form class="row g-2 mb-3" method="get" action="<?= $h(saUrl('ai')) ?>">
        <input type="hidden" name="tab" value="companies">
        <div class="col-12 col-md-4"><input type="search" class="form-control" name="q" value="<?= $h($fQ) ?>" placeholder="Search company or subdomain" aria-label="Search"></div>
        <div class="col-6 col-md-3">
            <select class="form-select" name="mode" aria-label="Key source">
                <option value="">All key sources</option>
                <?php foreach ($modeLabels as $k => $lbl): ?><option value="<?= $k ?>"<?= $fMode === $k ? ' selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <select class="form-select" name="module" aria-label="AI module">
                <option value="">Module on or off</option>
                <option value="on"<?= $fModule === 'on' ? ' selected' : '' ?>>AI module on</option>
                <option value="off"<?= $fModule === 'off' ? ' selected' : '' ?>>AI module off</option>
            </select>
        </div>
        <div class="col-12 col-md-2 d-grid"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-funnel me-1"></i> Filter</button></div>
    </form>
    <div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-dark"><tr><th>Company</th><th>AI module</th><th>Key source</th><th class="text-end">Monthly limit</th><th class="text-end">Spent this month</th><th class="text-end">Calls</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php if (!$companies): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No companies match.</td></tr>
        <?php endif; ?>
        <?php foreach ($companies as $co):
            $capTxt = $co['effective_cap'] > 0 ? $money($co['effective_cap']) : 'No limit';
            $pct = $co['effective_cap'] > 0 ? min(100, (int)round(100 * $co['spent'] / $co['effective_cap'])) : 0; ?>
            <tr>
                <td><div class="fw-semibold"><?= $h($co['company_name']) ?></div><div class="small text-muted"><?= $h($co['subdomain']) ?> · <?= $h($co['status']) ?></div></td>
                <td><?= $co['ai_module'] ? '<span class="badge rounded-pill bg-primary">On</span>' : '<span class="badge rounded-pill bg-secondary">Off</span>' ?></td>
                <td><span title="<?= $h($modeHelp[$co['mode']] ?? '') ?>"><?= $h($modeLabels[$co['mode']] ?? $co['mode']) ?></span></td>
                <td class="text-end"><?= $h($capTxt) ?><?= $co['monthly_cap'] === null ? ' <span class="small text-muted">(default)</span>' : '' ?></td>
                <td class="text-end"><?= $h($money($co['spent'])) ?><?php if ($co['effective_cap'] > 0): ?><div class="progress mt-1" style="height:4px" role="progressbar" aria-label="Share of limit used" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar<?= $pct >= 100 ? ' bg-danger' : '' ?>" style="width:<?= $pct ?>%"></div></div><?php endif; ?></td>
                <td class="text-end"><?= (int)$co['calls'] ?></td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary js-policy" data-id="<?= (int)$co['id'] ?>" data-name="<?= $h($co['company_name']) ?>"
                            data-mode="<?= $h($co['mode']) ?>" data-cap="<?= $co['monthly_cap'] === null ? '' : $h($co['monthly_cap']) ?>"><i class="bi bi-pencil"></i> Edit</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="text-muted small">Spending counts only calls made on the BJP key. "Auto" companies with their own key pay their provider directly.</p>

    <div class="modal fade" id="policyModal" tabindex="-1" aria-labelledby="policyTitle" aria-hidden="true">
        <div class="modal-dialog"><div class="modal-content">
            <form id="policyForm">
                <div class="modal-header"><h6 class="modal-title fw-bold" id="policyTitle">AI for <span id="policyName"></span></h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="set_policy">
                    <input type="hidden" name="tenant_id" id="policy_tenant">
                    <div class="mb-3">
                        <label class="form-label" for="policy_mode">Key source</label>
                        <select class="form-select" id="policy_mode" name="mode">
                            <?php foreach ($modeLabels as $k => $lbl): ?><option value="<?= $k ?>"><?= $lbl ?> — <?= $h($modeHelp[$k]) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="policy_cap">Monthly limit on the BJP key (USD)</label>
                        <input type="number" class="form-control" id="policy_cap" name="monthly_cap" min="0" max="100000" step="0.01" placeholder="Default (<?= $h($s['tenant_cap'] > 0 ? $money($s['tenant_cap']) : 'no limit') ?>)">
                        <div class="form-text">Empty = the default limit. 0 = no limit.</div>
                    </div>
                    <p class="small text-muted mt-3 mb-0">The AI Assistant module itself is switched on or off on the company's Modules page.</p>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save</button></div>
            </form>
        </div></div>
    </div>
<?php elseif ($tab === 'usage'): ?>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="stat"><b><?= $h($money($usage['month']['cost'])) ?></b><span class="small text-muted">This month</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= (int)$usage['month']['calls'] ?></b><span class="small text-muted">Answers this month</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= $h($money($usage['last_month']['cost'])) ?></b><span class="small text-muted">Last month (<?= (int)$usage['last_month']['calls'] ?> answers)</span></div></div>
        <div class="col-6 col-md-3"><div class="stat"><b><?= (int)$usage['month']['blocked'] ?> / <?= (int)$usage['month']['errors'] ?></b><span class="small text-muted">Blocked / failed this month</span></div></div>
    </div>
    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card panel-card">
                <div class="card-header"><i class="bi bi-building text-primary me-1"></i> Top companies this month</div>
                <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Company</th><th class="text-end">Answers</th><th class="text-end">Tokens</th><th class="text-end">Cost</th></tr></thead>
                    <tbody>
                    <?php if (!$usage['by_company']): ?><tr><td colspan="4" class="text-center text-muted py-3">No usage yet this month.</td></tr><?php endif; ?>
                    <?php foreach ($usage['by_company'] as $r): ?>
                        <tr><td><?= $h($r['company_name'] ?? ('#' . $r['tenant_id'])) ?> <span class="small text-muted"><?= $h($r['subdomain'] ?? '') ?></span></td>
                            <td class="text-end"><?= (int)$r['calls'] ?></td><td class="text-end"><?= number_format((int)$r['tokens']) ?></td><td class="text-end"><?= $h($money($r['cost'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card panel-card">
                <div class="card-header"><i class="bi bi-ui-checks-grid text-primary me-1"></i> By feature this month</div>
                <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>Feature</th><th class="text-end">Answers</th><th class="text-end">Cost</th></tr></thead>
                    <tbody>
                    <?php if (!$usage['by_feature']): ?><tr><td colspan="3" class="text-center text-muted py-3">No usage yet this month.</td></tr><?php endif; ?>
                    <?php foreach ($usage['by_feature'] as $r): ?>
                        <tr><td><?= $h($r['feature']) ?></td><td class="text-end"><?= (int)$r['calls'] ?></td><td class="text-end"><?= $h($money($r['cost'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card panel-card">
                <div class="card-header"><i class="bi bi-exclamation-triangle text-primary me-1"></i> Recent blocked or failed calls</div>
                <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>When</th><th>Company</th><th>Feature</th><th>Status</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php if (!$usage['problems']): ?><tr><td colspan="5" class="text-center text-muted py-3">Nothing to report.</td></tr><?php endif; ?>
                    <?php foreach ($usage['problems'] as $r): ?>
                        <tr><td class="small text-nowrap"><?= $h($r['created_at']) ?></td><td><?= $r['tenant_id'] === null ? '<span class="text-muted">Connection test</span>' : $h($r['company_name'] ?? ('#' . $r['tenant_id'])) ?></td>
                            <td><?= $h($r['feature']) ?></td><td><?= $r['status'] === 'blocked' ? '<span class="badge bg-secondary">Blocked</span>' : '<span class="badge bg-danger">Failed</span>' ?></td>
                            <td class="small"><?= $h($r['error']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
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
const policyModalEl = document.getElementById('policyModal');
const policyModal = policyModalEl ? new bootstrap.Modal(policyModalEl) : null;
$('.js-policy').on('click', function () {
    const d = $(this).data();
    $('#policy_tenant').val(d.id); $('#policyName').text(d.name);
    $('#policy_mode').val(d.mode); $('#policy_cap').val(d.cap === undefined ? '' : d.cap);
    policyModal.show();
});
$('#policyForm').on('submit', function (e) {
    e.preventDefault();
    saPost($(this).serializeArray(), $(this).find('[type="submit"]')).done(function (r) {
        if (r && r.success) policyModal.hide();
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
