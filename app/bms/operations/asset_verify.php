<?php
/**
 * app/bms/operations/asset_verify.php
 *
 * Physical-verification mode (Asset Register & PPE Schedule, Phase 8.4):
 * scan an asset's QR tag (or type its code) to confirm the asset is present.
 * A match logs a verification audit entry and links to the asset; an unknown
 * code is flagged as "found, not registered".
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('assets');
includeHeader();
?>

<div class="container py-4" style="max-width: 720px;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('assets') ?>">Assets</a></li>
            <li class="breadcrumb-item active">Verify</li>
        </ol>
    </nav>

    <h2 class="fw-bold text-primary mb-1"><i class="bi bi-qr-code-scan me-2"></i> Physical Verification</h2>
    <p class="text-muted">Scan a tag with your camera or type the asset code to confirm it is present.</p>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="input-group input-group-lg mb-3">
                <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                <input type="text" class="form-control" id="codeInput" placeholder="Asset code, e.g. COMP-0001" autofocus>
                <button class="btn btn-primary" onclick="verifyCode()"><i class="bi bi-search me-1"></i> Verify</button>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" id="scanToggle" onclick="toggleScanner()"><i class="bi bi-camera-video me-1"></i> Scan with camera</button>
            </div>
            <div id="reader" class="mt-3" style="display:none;"></div>
        </div>
    </div>

    <div id="result"></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
<script>
const VIEW_URL = '<?= getUrl('asset_view') ?>';
let html5Qr = null, scanning = false;

function renderResult(res) {
    const $r = $('#result');
    if (!res.success) { $r.html(`<div class="alert alert-danger">${res.message || 'Error'}</div>`); return; }
    if (!res.found) {
        $r.html(`<div class="alert alert-warning shadow-sm"><i class="bi bi-exclamation-triangle me-1"></i> <strong>Not registered.</strong> ${res.message}</div>`);
        return;
    }
    const a = res.asset;
    $r.html(`
        <div class="card border-0 shadow-sm border-start border-success border-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-check-circle text-success me-1"></i> ${a.asset_name}</h5>
                        <div class="text-muted"><span class="badge bg-dark-subtle text-dark border me-1">${a.asset_code}</span> ${a.category || ''}</div>
                        <div class="small mt-1">Status: <strong>${a.status}</strong> · Location: ${a.location || '—'} · Condition: ${a.condition || '—'}</div>
                    </div>
                    <a href="${VIEW_URL}?id=${a.asset_id}" class="btn btn-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i> Open</a>
                </div>
                <div class="small text-success mt-2"><i class="bi bi-clipboard-check me-1"></i> Presence confirmed and logged.</div>
            </div>
        </div>`);
}

function verifyCode() {
    const code = $('#codeInput').val().trim();
    if (!code) return;
    $.getJSON('<?= buildUrl('api/assets/verify_asset.php') ?>', { code: code }, renderResult)
     .fail(() => $('#result').html('<div class="alert alert-danger">Lookup failed.</div>'));
}

$('#codeInput').on('keypress', e => { if (e.which === 13) verifyCode(); });

function toggleScanner() {
    if (scanning) {
        if (html5Qr) html5Qr.stop().then(() => { $('#reader').hide(); });
        scanning = false; $('#scanToggle').html('<i class="bi bi-camera-video me-1"></i> Scan with camera');
        return;
    }
    $('#reader').show();
    html5Qr = new Html5Qrcode('reader');
    html5Qr.start({ facingMode: 'environment' }, { fps: 10, qrbox: 220 },
        (decoded) => {
            $('#codeInput').val(decoded);
            html5Qr.stop().then(() => { $('#reader').hide(); });
            scanning = false; $('#scanToggle').html('<i class="bi bi-camera-video me-1"></i> Scan with camera');
            verifyCode();
        },
        () => {}
    ).then(() => { scanning = true; $('#scanToggle').html('<i class="bi bi-stop-circle me-1"></i> Stop camera'); })
     .catch(() => { $('#reader').hide(); $('#result').html('<div class="alert alert-warning">Camera unavailable — type the code instead.</div>'); });
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
