<?php
/**
 * Create Document — write a letter/memo in-app (Summernote editor) instead of
 * only uploading a file. Produces a real document row (source='created') that
 * behaves exactly like any other library document: previewable, downloadable,
 * and pickable from the existing e-signature wizard (select_document_add_esignature.php)
 * for the "Save & Sign" hand-off.
 *
 * Only one signer is ever relevant here: the document's own creator — there is
 * no signer-selection step anywhere in this flow, by design.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/code_generator.php';
require_once __DIR__ . '/../../../core/project_scope.php';

includeHeader();

if (!canCreate('documents')) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

$document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
$project_id  = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;
if ($project_id !== null && !userCan('project', $project_id)) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

// Reopen an existing draft/created letter for further editing.
$existing = null;
if ($document_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND source = 'created'");
    $stmt->execute([$document_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing || ((int)$existing['uploaded_by'] !== (int)($_SESSION['user_id'] ?? 0) && !isAdmin())) {
        header("Location: " . getUrl('document_library'));
        exit();
    }
    if ($existing['project_id']) {
        $project_id = (int)$existing['project_id'];
    }
}

$categories = $pdo->query("SELECT * FROM document_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$company_name = get_setting('company_name', 'Business Management System');
$company_logo = get_setting('company_logo');

// Preview-only reference — the real, final code is allocated by nextCode()
// on the server at first save so it's never burned by an abandoned draft.
$document_code = $existing['document_code'] ?? peekNextCode($pdo, 'LTR');

$subject     = $existing['document_name'] ?? '';
$recipient   = '';
if (!empty($existing['description']) && strpos($existing['description'], 'To: ') === 0) {
    $recipient = substr($existing['description'], 4);
}
$letter_date = $existing['issue_date'] ?? date('Y-m-d');
$content     = $existing['content'] ?? '';
$category_id = $existing['category_id'] ?? null;

$signer_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$signer_role = $_SESSION['user_role'] ?? '';

// The creator's own on-file signature (drawn/uploaded/typed via e_signatures.php)
// — shown here as a WATERMARKED PREVIEW ONLY, so the letter never looks blank
// while it's still a draft. This is never the legally-applied signature: that
// only happens through select_document_add_esignature.php, which is the one
// place that records consent, IP, hash and the audit event log. Rendering it
// here too would let a "signed-looking" PDF leave the building without ever
// going through that audit trail.
$sig_stmt = $pdo->prepare("
    SELECT thumbnail_path, file_path FROM user_signatures
    WHERE user_id = ? AND status = 'active'
    ORDER BY created_at DESC LIMIT 1
");
$sig_stmt->execute([$_SESSION['user_id'] ?? 0]);
$my_signature = $sig_stmt->fetch(PDO::FETCH_ASSOC);
$signature_preview_path = $my_signature ? ($my_signature['thumbnail_path'] ?: $my_signature['file_path']) : null;

$project_name = null;
if ($project_id) {
    $pstmt = $pdo->prepare("SELECT project_name FROM projects WHERE project_id = ?");
    $pstmt->execute([$project_id]);
    $project_name = $pstmt->fetchColumn() ?: null;
}

$default_body = '<p>Dear ' . ($recipient !== '' ? htmlspecialchars($recipient) : 'Sir/Madam') . ',</p>'
    . '<p>&nbsp;</p>'
    . '<p>&nbsp;</p>'
    . '<p>Yours faithfully,</p>';
?>

<div class="container-fluid mt-4 mb-5" id="createDocumentPage">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Create Document</h4>
            <p class="text-muted mb-0 small">Write a letter or memo — save as a draft, print it, or send it straight into e-signing.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= $project_id ? getUrl('project_view') . '?id=' . (int)$project_id : getUrl('document_library') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <button type="button" class="btn btn-outline-primary" id="btnSaveDraft">
                <i class="bi bi-save me-1"></i> Save Draft
            </button>
            <button type="button" class="btn btn-outline-primary" id="btnSavePrint">
                <i class="bi bi-printer me-1"></i> Save &amp; Print
            </button>
            <button type="button" class="btn btn-primary" id="btnSaveSign">
                <i class="bi bi-pen me-1"></i> Save &amp; Sign
            </button>
        </div>
    </div>

    <div class="row g-3 mb-3 d-print-none">
        <div class="col-md-4">
            <label class="form-label small fw-bold">Recipient</label>
            <input type="text" class="form-control" id="f_recipient" value="<?= htmlspecialchars($recipient) ?>" placeholder="e.g. The Manager, ABC Ltd">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Date</label>
            <input type="date" class="form-control" id="f_letter_date" value="<?= htmlspecialchars($letter_date) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Category</label>
            <select class="form-select select2-static" id="f_category_id">
                <option value="">Select Category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= ((int)$category_id === (int)$cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold">Reference No.</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($document_code) ?>" readonly>
        </div>
    </div>

    <?php if ($project_id): ?>
        <div class="alert alert-info py-2 px-3 mb-3 d-print-none" style="font-size: 0.85rem;">
            <i class="bi bi-diagram-3 me-1"></i> This document will be linked to project: <strong><?= htmlspecialchars($project_name ?? ('#' . $project_id)) ?></strong>
        </div>
    <?php endif; ?>

    <div class="col-12 mb-3 d-print-none">
        <label class="form-label small fw-bold">Subject <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="f_subject" value="<?= htmlspecialchars($subject) ?>" placeholder="e.g. Notice of Meeting" required>
    </div>

    <!-- A4-styled letter preview. Only #letterBody is the Summernote-editable
         region — the letterhead/ref/date/signature block are rendered from
         structured fields so they can never be accidentally edited/broken by
         the rich-text editor. -->
    <div class="letter-paper-wrap d-print-none">
        <div class="letter-paper-label"><i class="bi bi-eye"></i> Live Preview — this is exactly how the saved PDF will look</div>
    </div>
    <div class="letter-paper mx-auto shadow-sm" id="letterPaper">
        <div class="letter-head text-center">
            <?php if ($company_logo): ?>
                <img src="<?= getUrl($company_logo) ?>" alt="Logo" class="letter-logo">
            <?php endif; ?>
            <div class="letter-company"><?= htmlspecialchars($company_name) ?></div>
        </div>
        <div class="letter-meta">
            <span>Ref: <strong id="letter-ref-display"><?= htmlspecialchars($document_code) ?></strong></span>
            <span id="letter-date-display"><?= htmlspecialchars(date('d M Y', strtotime($letter_date))) ?></span>
        </div>
        <div class="letter-recipient" id="letter-recipient-display">
            <?= $recipient !== '' ? nl2br(htmlspecialchars($recipient)) : '' ?>
        </div>
        <div class="letter-subject">
            <strong>RE: <span id="letter-subject-display"><?= htmlspecialchars($subject ?: '(Subject)') ?></span></strong>
        </div>

        <div id="letterBody"><?= $content !== '' ? $content : $default_body ?></div>

        <div class="letter-signoff">
            <div class="letter-signature-box">
                <?php if ($signature_preview_path): ?>
                    <img src="<?= htmlspecialchars(getUrl($signature_preview_path)) ?>" alt="Signature preview" class="letter-signature-img">
                    <div class="letter-signature-watermark">PREVIEW</div>
                <?php else: ?>
                    <a href="<?= getUrl('e_signatures') ?>" class="letter-signature-cta d-print-none" target="_blank">
                        <i class="bi bi-pen"></i> Set up your e-signature
                    </a>
                <?php endif; ?>
            </div>
            <div class="letter-signoff-name"><?= htmlspecialchars($signer_name ?: 'Signed by') ?></div>
            <?php if ($signer_role): ?><div class="letter-signoff-role"><?= htmlspecialchars($signer_role) ?></div><?php endif; ?>
            <div class="letter-signoff-note text-muted d-print-none">
                <i class="bi bi-info-circle me-1"></i>
                <?= $signature_preview_path
                    ? 'Preview only — your signature is legally applied (with audit trail) in the next step, Save &amp; Sign.'
                    : 'No signature on file yet — add one to enable Save &amp; Sign.' ?>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-bs5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-bs5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
.letter-paper {
    background: #fff;
    max-width: 210mm;
    min-height: 297mm;
    padding: 20mm 18mm;
    border: 1px solid #dee2e6;
    border-radius: 0 0 12px 12px;
}
.letter-paper-wrap { max-width: 210mm; margin: 0 auto; }
.letter-paper-label {
    background: #0d6efd;
    color: #fff;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 8px 16px;
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
}
.letter-head { margin-bottom: 14mm; }
.letter-logo { max-height: 70px; width: auto; display: block; margin: 0 auto 6px; }
.letter-company { font-size: 16pt; font-weight: 800; color: #0d6efd; text-transform: uppercase; letter-spacing: 1px; }
.letter-meta { display: flex; justify-content: space-between; font-size: 10pt; color: #333; margin-bottom: 10mm; }
.letter-recipient { font-size: 11pt; white-space: pre-line; margin-bottom: 6mm; min-height: 1em; }
.letter-subject { font-size: 11pt; margin-bottom: 8mm; text-decoration: underline; }
#letterBody { font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.6; min-height: 60mm; }

/* Summernote ships its own generic skin — restyle it to match BMS's rounded,
   shadow-sm card language instead of looking like a bare stock install. */
.note-editor.note-frame {
    border: 1px solid #dee2e6 !important;
    border-radius: 8px !important;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.note-editor .note-toolbar {
    background: #f8f9fa !important;
    border-bottom: 1px solid #dee2e6 !important;
    padding: 6px !important;
}
.note-editable { padding: 12px !important; }

.letter-signoff { margin-top: 16mm; font-size: 11pt; }
.letter-signature-box {
    position: relative;
    width: 220px;
    height: 80px;
    border: 1px dashed #adb5bd;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
    overflow: hidden;
    background: #fbfbfb;
}
.letter-signature-img { max-height: 90%; max-width: 90%; object-fit: contain; opacity: 0.75; }
.letter-signature-watermark {
    position: absolute;
    bottom: 4px;
    right: 8px;
    font-size: 7pt;
    font-weight: 700;
    letter-spacing: 1px;
    color: #dc3545;
    opacity: 0.6;
    transform: rotate(-8deg);
}
.letter-signature-cta {
    font-size: 0.8rem;
    color: #0d6efd;
    text-decoration: none;
    font-weight: 600;
}
.letter-signature-cta:hover { text-decoration: underline; }
.letter-signoff-name { font-weight: 700; border-top: 1px solid #333; padding-top: 4px; display: inline-block; }
.letter-signoff-role { color: #555; }
.letter-signoff-note { font-size: 8pt; margin-top: 4mm; }

@media print {
    .letter-paper { border: none; max-width: 100%; box-shadow: none !important; border-radius: 0; }
    .letter-paper-label { display: none !important; }
    #createDocumentPage .btn, #createDocumentPage .form-label, #createDocumentPage input, #createDocumentPage select { display: none !important; }
}
</style>

<script>
// Mutable — starts from the page-load value but is updated after the first
// successful save. saveDocument() must read THIS, not re-embed the PHP value,
// otherwise every save after the first (no full page reload happens — only
// history.replaceState) would still send the original 0 and the server would
// create a brand new row instead of updating the one just saved.
let currentDocumentId = <?= (int)($existing['id'] ?? 0) ?>;

$(document).ready(function () {
    $('#f_category_id').select2({ theme: 'bootstrap-5', placeholder: 'Select...', allowClear: true, width: '100%' });

    $('#letterBody').summernote({
        height: 320,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
            ['fontname', ['fontname']],
            ['fontsize', ['fontsize']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph', 'height']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'hr']],
            ['history', ['undo', 'redo']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        fontNames: ['Arial', 'Calibri', 'Georgia', 'Segoe UI', 'Times New Roman', 'Verdana'],
        fontSizes: ['8', '9', '10', '11', '12', '14', '16', '18', '24', '32']
    });

    $('#f_recipient').on('input', function () {
        $('#letter-recipient-display').html($('<div>').text($(this).val()).html().replace(/\n/g, '<br>'));
    });
    $('#f_subject').on('input', function () {
        $('#letter-subject-display').text($(this).val() || '(Subject)');
    });
    $('#f_letter_date').on('change', function () {
        const d = new Date($(this).val() + 'T00:00:00');
        $('#letter-date-display').text(isNaN(d) ? '' : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }));
    });

    $('#btnSaveDraft').on('click', function () { saveDocument('draft'); });
    $('#btnSavePrint').on('click', function () { saveDocument('print'); });
    $('#btnSaveSign').on('click', function () { saveDocument('sign'); });
});

function saveDocument(mode) {
    const subject = $('#f_subject').val().trim();
    if (!subject) {
        Swal.fire({ icon: 'warning', title: 'Subject required', text: 'Please enter a subject before saving.' });
        return;
    }
    if ($('#letterBody').summernote('isEmpty')) {
        Swal.fire({ icon: 'warning', title: 'Empty letter', text: 'Please write the letter body before saving.' });
        return;
    }

    const $btn = mode === 'draft' ? $('#btnSaveDraft') : (mode === 'print' ? $('#btnSavePrint') : $('#btnSaveSign'));
    const orig = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');

    // Render the letter-paper (letterhead + body) to a real PDF client-side —
    // every save keeps a downloadable/previewable file in step with the
    // editable content, matching how every other document in the library
    // already behaves.
    const opt = {
        margin: 0,
        filename: subject + '.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opt).from(document.getElementById('letterPaper')).outputPdf('blob').then(function (blob) {
        const fd = new FormData();
        fd.append('document_id', currentDocumentId);
        fd.append('subject', subject);
        fd.append('recipient', $('#f_recipient').val().trim());
        fd.append('letter_date', $('#f_letter_date').val());
        fd.append('category_id', $('#f_category_id').val() || '');
        fd.append('project_id', '<?= (int)($project_id ?? 0) ?>');
        fd.append('content', $('#letterBody').summernote('code'));
        fd.append('_csrf', CSRF_TOKEN);
        fd.append('pdf_file', blob, subject + '.pdf');

        $.ajax({
            url: '<?= buildUrl('api/document/save_created_document.php') ?>',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (!res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not save the document.' });
                    $btn.prop('disabled', false).html(orig);
                    return;
                }
                currentDocumentId = res.document_id;
                if (mode === 'draft') {
                    Swal.fire({ icon: 'success', title: 'Draft saved', text: 'Reference: ' + res.document_code, timer: 1800, showConfirmButton: false })
                        .then(function () {
                            const url = new URL(window.location.href);
                            url.searchParams.set('document_id', res.document_id);
                            window.history.replaceState({}, '', url);
                            $btn.prop('disabled', false).html(orig);
                        });
                } else if (mode === 'print') {
                    $btn.prop('disabled', false).html(orig);
                    window.print();
                } else {
                    window.location.href = '<?= buildUrl('select_document_add_esignature') ?>';
                }
            },
            error: function () {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Server error while saving.' });
                $btn.prop('disabled', false).html(orig);
            }
        });
    }).catch(function () {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Could not generate the PDF.' });
        $btn.prop('disabled', false).html(orig);
    });
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
