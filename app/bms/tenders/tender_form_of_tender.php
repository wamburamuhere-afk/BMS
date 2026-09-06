<?php
// File: app/bms/tenders/tender_form_of_tender.php
// scope-audit: skip — tender Form of Tender view; tenders reference customers (no direct project_id); deferred to Phase G-2
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/tender_documents.php';

autoEnforcePermission('tenders');
$can_edit = canEdit('tenders') || canCreate('tenders');

includeHeader();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT * FROM tenders WHERE tender_id = ?");
$stmt->execute([$id]);
$tender = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tender) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Tender not found.</div></div>";
    includeFooter();
    exit;
}

// First visit: draft on the fly for display, but don't persist until the
// user explicitly saves or re-drafts — matches Facile's "drafted
// automatically ... edit freely, your edits are kept until you press
// Re-draft" behaviour.
$bodyHtml = $tender['form_of_tender_html'] ?: draftFormOfTenderBodyHtml($tender);
$letterDate = $tender['form_of_tender_date'] ?: date('Y-m-d');

$tenderNavActive = 'fot';
?>

<link href="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-bs5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-bs5.min.js"></script>

<div class="container-fluid px-4 mt-4 mb-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
        <div class="mb-3 mb-md-0 text-center text-md-start">
            <h2 class="fw-bold text-primary"><i class="bi bi-envelope-paper me-2"></i>Form of Tender / Covering Letter</h2>
            <p class="text-muted small mb-0">Tender No: <span class="fw-bold text-dark"><?= safe_output($tender['tender_no']) ?></span></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= buildUrl('api/tender_form_of_tender.php') ?>?action=PRINT&tender_id=<?= (int)$id ?>" target="_blank" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-printer"></i> Print / Save PDF</a>
            <a href="<?= getUrl('tenders') ?>" class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-arrow-left"></i> Back to List</a>
        </div>
    </div>

    <?php require __DIR__ . '/_tender_nav.php'; ?>

    <div id="fot-message" class="mb-3"></div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="mb-3" style="max-width:220px;">
                <label class="form-label fw-bold">Letter Date</label>
                <input type="date" class="form-control" id="letterDate" value="<?= safe_output($letterDate) ?>" <?= $can_edit ? '' : 'disabled' ?>>
            </div>

            <p class="text-muted small">
                Addressed to <strong><?= safe_output($tender['procuring_entity_name'] ?: '[Procuring Entity — set it in Details]') ?></strong>.
                The letter below is drafted automatically from the tender details and BOQ grand total. Edit freely — your edits are kept until you press "Re-draft From Details".
            </p>

            <textarea id="letterBody" <?= $can_edit ? '' : 'disabled' ?>><?= $bodyHtml ?></textarea>

            <?php if ($can_edit): ?>
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-primary" onclick="saveLetter()"><i class="bi bi-check-circle"></i> Save Letter</button>
                <button type="button" class="btn btn-outline-secondary" onclick="redraftLetter()"><i class="bi bi-arrow-repeat"></i> Re-draft From Details</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const FOT_API = '<?= buildUrl('api/tender_form_of_tender.php') ?>';
const TENDER_ID = <?= (int)$id ?>;

function fotMessage(type, text) {
    $('#fot-message').html(`<div class="alert alert-${type} py-2">${text}</div>`);
}

$(document).ready(function () {
    $('#letterBody').summernote({
        height: 320,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['history', ['undo', 'redo']],
            ['view', ['codeview']]
        ]
    });
    <?php if (!$can_edit): ?>
    $('#letterBody').summernote('disable');
    <?php endif; ?>
});

function saveLetter() {
    const bodyHtml = $('#letterBody').summernote('code');
    const letterDate = $('#letterDate').val();
    $.post(FOT_API, { action: 'SAVE_LETTER', tender_id: TENDER_ID, body_html: bodyHtml, letter_date: letterDate, _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { fotMessage('success', res.message); } else { fotMessage('danger', res.message); }
    }, 'json');
}

function redraftLetter() {
    Swal.fire({ title: 'Re-draft from details?', text: 'This replaces the current letter text with a fresh draft from the Tender Details and BOQ. Your current edits will be lost.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Re-draft' })
        .then(r => {
            if (!r.isConfirmed) return;
            $.post(FOT_API, { action: 'REDRAFT', tender_id: TENDER_ID, _csrf: '<?= csrf_token() ?>' }, function (res) {
                if (res.success) {
                    $('#letterBody').summernote('code', res.body_html);
                    fotMessage('success', res.message);
                } else {
                    fotMessage('danger', res.message);
                }
            }, 'json');
        });
}
</script>

<?php includeFooter(); ?>
