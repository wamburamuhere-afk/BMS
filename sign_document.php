<?php
/**
 * sign_document.php — public, unauthenticated page an EXTERNAL signer
 * (a client/supplier with no BMS login) reaches via the emailed link from
 * request_external_signature.php. Requires no session; access is entirely
 * governed by the single-use, expiring token in the URL.
 *
 * Deliberately does NOT call includeHeader() (that's what forces a login
 * redirect on every other page) — mirrors login.php's standalone-page
 * pattern instead, just via the full roots.php bootstrap so getUrl()/
 * buildUrl()/get_setting() etc. are available (roots.php itself enforces no
 * authentication — that only happens inside includeHeader()/header.php).
 */
require_once __DIR__ . '/roots.php';

$token = trim((string)($_GET['token'] ?? ''));
$state = 'invalid'; // invalid | already_signed | ready
$signature = null;
$document = null;

if ($token !== '') {
    $token_hash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT t.id AS token_id, t.expires_at, t.used_at,
               s.id AS signature_id, s.document_id, s.signer_name, s.signer_email, s.status,
               d.document_name, d.file_path
        FROM document_signature_tokens t
        JOIN document_signatures s ON s.id = t.signature_id
        JOIN documents d ON d.id = s.document_id
        WHERE t.token_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$token_hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['used_at'] === null && strtotime($row['expires_at']) > time() && $row['status'] === 'pending') {
        $state = 'ready';
        $signature = $row;
        $document = $row;
    } elseif ($row && $row['status'] === 'signed') {
        $state = 'already_signed';
        $document = $row;
    }
}

$company_name = get_setting('company_name', 'Business Management System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Document | <?= htmlspecialchars($company_name) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
    body { background: #f4f6f9; font-family: 'Segoe UI', Arial, sans-serif; }
    .sign-wrap { max-width: 900px; margin: 0 auto; padding: 24px 16px 60px; }
    .sign-header { background: #0d6efd; color: #fff; padding: 18px 24px; border-radius: 10px 10px 0 0; font-weight: 700; font-size: 18px; }
    .sign-card { background: #fff; border-radius: 0 0 10px 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 24px; }
    .doc-frame { width: 100%; height: 60vh; border: 1px solid #dee2e6; border-radius: 6px; }
    #sigCanvas { border: 1.5px dashed #adb5bd; border-radius: 6px; background: #fff; touch-action: none; cursor: crosshair; width: 100%; height: 160px; }
    .status-box { text-align: center; padding: 60px 24px; }
</style>
</head>
<body>
<div class="sign-wrap">
    <div class="sign-header"><?= htmlspecialchars($company_name) ?> &mdash; Document Signing</div>
    <div class="sign-card">

<?php if ($state === 'invalid'): ?>
        <div class="status-box">
            <i class="bi bi-x-circle text-danger" style="font-size:3rem;"></i>
            <h4 class="mt-3">This link is invalid or has expired</h4>
            <p class="text-muted">Signing links are single-use and expire after 7 days. Please contact the sender for a new link.</p>
        </div>

<?php elseif ($state === 'already_signed'): ?>
        <div class="status-box">
            <i class="bi bi-check-circle text-success" style="font-size:3rem;"></i>
            <h4 class="mt-3">This document has already been signed</h4>
            <p class="text-muted"><?= htmlspecialchars($document['document_name']) ?></p>
        </div>

<?php else: ?>
        <h5 class="mb-1"><?= htmlspecialchars($document['document_name']) ?></h5>
        <p class="text-muted small mb-3">Requested for the signature of <?= htmlspecialchars($signature['signer_name']) ?> (<?= htmlspecialchars($signature['signer_email']) ?>)</p>

        <embed id="docFrame" class="doc-frame mb-4" type="application/pdf" src="<?= htmlspecialchars(getUrl($document['file_path'])) ?>">

        <div id="signSection">
            <h6 class="fw-bold mb-2">Draw your signature</h6>
            <canvas id="sigCanvas"></canvas>
            <div class="d-flex justify-content-end mt-2 mb-4">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearSig"><i class="bi bi-eraser"></i> Clear</button>
            </div>

            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="identityCheck">
                <label class="form-check-label small" for="identityCheck">
                    I confirm that I am <strong><?= htmlspecialchars($signature['signer_name']) ?></strong>
                    (<?= htmlspecialchars($signature['signer_email']) ?>) and am authorised to sign this document.
                    <span class="text-muted">(If this link was forwarded to you and you are not this person, please close this
                    page and contact the sender.)</span>
                </label>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="consentCheck">
                <label class="form-check-label small" for="consentCheck">
                    I, <?= htmlspecialchars($signature['signer_name']) ?>, agree that clicking "Sign Document" constitutes my
                    electronic signature on this document, and is legally binding to the same extent as a handwritten signature.
                </label>
            </div>

            <div id="signMessage"></div>

            <button type="button" class="btn btn-primary" id="btnSign">
                <i class="bi bi-pen"></i> Sign Document
            </button>
        </div>

        <div id="doneSection" class="status-box" style="display:none;">
            <i class="bi bi-check-circle text-success" style="font-size:3rem;"></i>
            <h4 class="mt-3">Signed successfully</h4>
            <p class="text-muted">Thank you — a copy has been recorded. You may close this page.</p>
        </div>
<?php endif; ?>

    </div>
</div>

<?php if ($state === 'ready'): ?>
<script src="<?= getUrl('assets/js/pdf-lib.min.js') ?>"></script>
<script>
(function () {
    const canvas = document.getElementById('sigCanvas');
    const ctx = canvas.getContext('2d');
    function sizeCanvas() {
        const ratio = window.devicePixelRatio || 1;
        canvas.width = canvas.clientWidth * ratio;
        canvas.height = canvas.clientHeight * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#1a1a2e';
    }
    sizeCanvas();

    let drawing = false, hasDrawn = false;
    function pos(e) {
        const r = canvas.getBoundingClientRect();
        const p = e.touches ? e.touches[0] : e;
        return { x: p.clientX - r.left, y: p.clientY - r.top };
    }
    function start(e) { drawing = true; hasDrawn = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
    function move(e) { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
    function end() { drawing = false; }
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);

    document.getElementById('btnClearSig').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasDrawn = false;
    });

    document.getElementById('btnSign').addEventListener('click', async function () {
        const $msg = document.getElementById('signMessage');
        $msg.innerHTML = '';
        if (!hasDrawn) {
            $msg.innerHTML = '<div class="alert alert-warning py-2">Please draw your signature first.</div>';
            return;
        }
        if (!document.getElementById('identityCheck').checked) {
            $msg.innerHTML = '<div class="alert alert-warning py-2">Please confirm your identity to continue.</div>';
            return;
        }
        if (!document.getElementById('consentCheck').checked) {
            $msg.innerHTML = '<div class="alert alert-warning py-2">Please confirm the consent statement to continue.</div>';
            return;
        }

        const $btn = this;
        const orig = $btn.innerHTML;
        $btn.disabled = true;
        $btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Signing...';

        try {
            const sigPngDataUrl = canvas.toDataURL('image/png');

            // Stamp the signature onto the original PDF client-side — same
            // technique (pdf-lib) already proven by the internal signing
            // wizard, just with a fixed bottom-right position instead of
            // drag-to-position (kept simple for this external flow).
            const origBytes = await fetch(document.getElementById('docFrame').src).then(r => r.arrayBuffer());
            const pdfDoc = await PDFLib.PDFDocument.load(origBytes);
            const pngImage = await pdfDoc.embedPng(sigPngDataUrl);
            const pages = pdfDoc.getPages();
            const lastPage = pages[pages.length - 1];
            const { width } = lastPage.getSize();
            const imgW = 150, imgH = 150 * (pngImage.height / pngImage.width);
            const x = width - imgW - 40, y = 40;
            lastPage.drawImage(pngImage, { x: x, y: y + 14, width: imgW, height: imgH });
            const font = await pdfDoc.embedFont(PDFLib.StandardFonts.Helvetica);
            lastPage.drawText('Digitally signed by <?= addslashes(htmlspecialchars($signature['signer_name'])) ?>', {
                x: x, y: y, size: 8, font: font
            });

            const signedBytes = await pdfDoc.save();
            const blob = new Blob([signedBytes], { type: 'application/pdf' });

            const identityStatement = document.querySelector('label[for="identityCheck"]').textContent.trim();
            const consentStatement = document.querySelector('label[for="consentCheck"]').textContent.trim();

            const fd = new FormData();
            fd.append('token', <?= json_encode($token) ?>);
            fd.append('consent_text', identityStatement + ' | ' + consentStatement);
            fd.append('signed_pdf_file', blob, 'signed.pdf');

            const res = await fetch('<?= buildUrl('api/document/submit_external_signature.php') ?>', { method: 'POST', body: fd }).then(r => r.json());

            if (!res.success) {
                $msg.innerHTML = '<div class="alert alert-danger py-2">' + (res.message || 'Could not save your signature.') + '</div>';
                $btn.disabled = false; $btn.innerHTML = orig;
                return;
            }

            document.getElementById('signSection').style.display = 'none';
            document.getElementById('doneSection').style.display = 'block';
        } catch (err) {
            console.error(err);
            $msg.innerHTML = '<div class="alert alert-danger py-2">Something went wrong while preparing your signature. Please try again.</div>';
            $btn.disabled = false; $btn.innerHTML = orig;
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
