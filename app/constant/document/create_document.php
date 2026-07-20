<?php
/**
 * Create Document — write a letter/memo in-app (Summernote editor) instead of
 * only uploading a file. Produces a real document row (source='created') that
 * behaves exactly like any other library document: previewable, downloadable,
 * and pickable from the existing e-signature wizard (select_document_add_esignature.php)
 * when the user later chooses to sign it via Docs > E-Sign.
 *
 * New letters normally arrive here via new_document.php (the category/
 * template chooser), which passes ?template_id and/or ?category_id to
 * pre-fill this editor — but a bare hit with neither is still a fully valid
 * blank-page start, so both stay optional.
 *
 * Only one signer is ever relevant here: the document's own creator — there is
 * no signer-selection step anywhere in this flow, by design.
 */
// The letter-paper below renders its own proper letterhead (logo + company
// name + Ref/date) — suppress the global print header (renderPrintHeader(),
// fired from includeHeader()) so the printed document doesn't show the
// company logo/name twice, same pattern as project_view.php.
define('BMS_SUPPRESS_PRINT_HEADER', true);
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/code_generator.php';
require_once __DIR__ . '/../../../core/project_scope.php';
require_once __DIR__ . '/../../includes/ai_generate.php';

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

// Pre-fill from the category/template chooser (new_document.php) — only
// meaningful for a brand new letter; an already-saved draft keeps its own
// stored content/category regardless of what's in the URL.
$prefill_template = null;
$prefill_template_id = 0;
if ($document_id === 0) {
    $prefill_template_id = !empty($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
    if ($prefill_template_id > 0) {
        $tpl_stmt = $pdo->prepare("SELECT id, content, subject, recipient, recipient_address, use_letterhead, signature_align FROM document_templates WHERE id = ? AND content IS NOT NULL AND is_active = 1");
        $tpl_stmt->execute([$prefill_template_id]);
        $prefill_template = $tpl_stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// One category list for everything now — the document's filing category, the
// template chooser, and the "Save as Template" picker all use document_categories.
$categories = $pdo->query("SELECT * FROM document_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$can_manage_templates = canCreate('document_templates');
$can_use_templates = canView('document_templates');

$company_name    = get_setting('company_name', 'Business Management System');
$company_logo    = get_setting('company_logo');
$company_address = get_setting('company_address', '');
$company_phone   = get_setting('company_phone', '');
$company_email   = get_setting('company_email', '');
$company_tin     = get_setting('company_tin', '');
$company_vrn     = get_setting('company_vrn', '');

// Preview-only reference — the real, final code is allocated by nextCode()
// on the server at first save so it's never burned by an abandoned draft.
$document_code = $existing['document_code'] ?? peekNextCode($pdo, 'LTR');

$subject     = $existing['document_name'] ?? ($prefill_template['subject'] ?? '');
$recipient   = '';
if (!empty($existing['description']) && strpos($existing['description'], 'To: ') === 0) {
    $recipient = substr($existing['description'], 4);
} elseif (!$existing && !empty($prefill_template['recipient'])) {
    $recipient = $prefill_template['recipient'];
}
$letter_date = $existing['issue_date'] ?? date('Y-m-d');
$content     = $existing['content'] ?? ($prefill_template['content'] ?? '');
// The wizard's category picker (new_document.php) and this filing field now
// share ONE taxonomy (document_categories). When starting fresh from a chosen
// category, pre-select it here so the user doesn't classify twice.
$wizard_category_id = (!$existing && !empty($_GET['category_id'])) ? (int)$_GET['category_id'] : null;
$category_id = $existing['category_id'] ?? $wizard_category_id;
$access_level = in_array(($existing['access_level'] ?? ''), ['private', 'restricted', 'public'], true)
    ? $existing['access_level'] : 'private';
// A saved draft keeps whatever it was last set to. A brand-new letter from a
// template honours the template's own letterhead choice if it stored one, else
// defaults ON (templates assume the professional letterhead look). A brand-new
// BLANK letter (no template, no existing record) defaults OFF — a truly blank
// canvas the user builds up from scratch, per feedback.
if (isset($existing['use_letterhead'])) {
    $use_letterhead = (int)$existing['use_letterhead'] === 1;
} elseif ($prefill_template && $prefill_template['use_letterhead'] !== null) {
    $use_letterhead = (int)$prefill_template['use_letterhead'] === 1;
} else {
    $use_letterhead = ($prefill_template_id > 0);
}
// Not every letter type needs a full recipient address block (an internal
// memo doesn't) — this stays empty unless an older draft/template set one.
$recipient_address = $existing['recipient_address'] ?? ($prefill_template['recipient_address'] ?? '');
// Signature style genuinely differs by letter format (full-block vs
// modified-block) — a per-letter choice; honours the template's if it stored one.
$signature_align = in_array(($existing['signature_align'] ?? ''), ['left', 'center', 'right'], true)
    ? $existing['signature_align']
    : (in_array(($prefill_template['signature_align'] ?? ''), ['left', 'center', 'right'], true)
        ? $prefill_template['signature_align'] : 'left');

// Backward-compat only: a draft/template saved before there was one shared
// writable canvas stored recipient/recipient_address separately from the
// body. There's no separate field to show them in any more, so fold them in
// as the opening lines of the body once, here, at load time — after that
// they simply become part of $content like everything else the user writes.
// Detect plain text (no tags, the old format) vs already-HTML the same way
// applyTemplate() does client-side.
if ($recipient !== '' || $recipient_address !== '') {
    $recipient_prepend = '';
    if ($recipient !== '') {
        $recipient_prepend .= (strip_tags($recipient) === $recipient)
            ? '<div>' . nl2br(htmlspecialchars($recipient)) . '</div>'
            : $recipient;
    }
    if ($recipient_address !== '') {
        $recipient_prepend .= '<div>' . nl2br(htmlspecialchars($recipient_address)) . '</div>';
    }
    $content = $recipient_prepend . $content;
}

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
$project_contract_number = null;
if ($project_id) {
    $pstmt = $pdo->prepare("SELECT project_name, contract_number FROM projects WHERE project_id = ?");
    $pstmt->execute([$project_id]);
    if ($prow = $pstmt->fetch(PDO::FETCH_ASSOC)) {
        $project_name = $prow['project_name'] ?: null;
        $project_contract_number = $prow['contract_number'] ?: null;
    }
}

// Merge-variable support — the token list + labels come from the shared
// resolver so the "Insert Variable" UI and the server safety-pass stay in sync.
require_once ROOT_DIR . '/core/document_merge.php';
$merge_variables = documentMergeVariables();

// A brand-new blank letter (no template, no existing draft) starts as a truly
// empty canvas — no "Dear Sir/Madam... Yours faithfully" boilerplate pre-filled
// for the user to delete first. A template's own content (see $content above)
// is unaffected by this — only the from-scratch case was ever auto-filled.
$default_body = '';

// Sender info block (top-right, under the date) — one line per field, only
// the ones actually filled in under Company Profile. Replaces the old
// separate footer strip so postal/physical address, phone, email, TIN and
// VRN appear exactly once, as part of the sender's own address block.
$sender_lines = [];
if ($company_address !== '') { $sender_lines[] = $company_address; }
if ($company_phone !== '')   { $sender_lines[] = 'Tel: ' . $company_phone; }
if ($company_email !== '')   { $sender_lines[] = $company_email; }
if ($company_tin !== '')     { $sender_lines[] = 'TIN: ' . $company_tin; }
if ($company_vrn !== '')     { $sender_lines[] = 'VRN: ' . $company_vrn; }
?>

<div class="container-fluid mt-4 mb-5" id="createDocumentPage">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 d-print-none">
        <div>
            <h4 class="mb-0"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Create Document</h4>
            <p class="text-muted mb-0 small">Write a letter or memo — save as a draft, print it, or send it straight into e-signing.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= $project_id ? getUrl('project_view') . '?id=' . (int)$project_id : getUrl('document_library') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <?php if ($can_use_templates || $can_manage_templates): ?>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-file-earmark-text me-1"></i> Templates
                </button>
                <ul class="dropdown-menu">
                    <?php if ($can_use_templates): ?>
                    <li><button type="button" class="dropdown-item" id="btnUseTemplate"><i class="bi bi-magic me-2"></i>Use Template...</button></li>
                    <?php endif; ?>
                    <?php if ($can_manage_templates): ?>
                    <li><button type="button" class="dropdown-item" id="btnSaveTemplate"><i class="bi bi-bookmark-plus me-2"></i>Save as Template...</button></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
            <!-- Insert Variable — drops a {{token}} at the cursor. On a template
                 these auto-fill from real data (company, recipient, date, etc.)
                 whenever the template is used to create a letter. -->
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Insert an auto-filling merge variable">
                    <i class="bi bi-braces me-1"></i> Insert Variable
                </button>
                <ul class="dropdown-menu" style="max-height:320px; overflow-y:auto;">
                    <li><h6 class="dropdown-header">Auto-fills when the letter is created</h6></li>
                    <?php foreach ($merge_variables as $token => $label): ?>
                    <li><button type="button" class="dropdown-item insert-var-btn" data-token="<?= htmlspecialchars($token) ?>">
                        <?= htmlspecialchars($label) ?> <code class="text-muted ms-1">{{<?= htmlspecialchars($token) ?>}}</code>
                    </button></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php if ($existing): ?>
            <button type="button" class="btn btn-outline-secondary" id="btnDuplicate">
                <i class="bi bi-files me-1"></i> Duplicate
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-primary" id="btnSaveDraft">
                <i class="bi bi-save me-1"></i> Save Draft
            </button>
            <button type="button" class="btn btn-primary" id="btnSavePrint">
                <i class="bi bi-printer me-1"></i> Save &amp; Print
            </button>
            <button type="button" class="btn btn-primary" id="btnSaveSign">
                <i class="bi bi-pen me-1"></i> Save &amp; Sign
            </button>
        </div>
    </div>

    <!-- Recipient is no longer a separate input here — write it directly
         into the letter itself, in the address block below (same always-
         editable pattern as the sender address). -->
    <div class="row g-3 mb-2 d-print-none">
        <div class="col-md-4">
            <label class="form-label small fw-bold">Date</label>
            <input type="date" class="form-control" id="f_letter_date" value="<?= htmlspecialchars($letter_date) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold">Category</label>
            <select class="form-select select2-static" id="f_category_id">
                <option value="">Select Category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= ((int)$category_id === (int)$cat['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold">Access</label>
            <select class="form-select" id="f_access_level">
                <option value="private" <?= $access_level === 'private' ? 'selected' : '' ?>>Private</option>
                <option value="restricted" <?= $access_level === 'restricted' ? 'selected' : '' ?>>Restricted</option>
                <option value="public" <?= $access_level === 'public' ? 'selected' : '' ?>>Public</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold">Reference No.</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($document_code) ?>" readonly>
        </div>
    </div>

    <div class="row g-3 mb-3 d-print-none align-items-start">
        <div class="col-md-3">
            <label class="form-label small fw-bold">Signature Position</label>
            <select class="form-select" id="f_signature_align">
                <option value="left" <?= $signature_align === 'left' ? 'selected' : '' ?>>Left</option>
                <option value="center" <?= $signature_align === 'center' ? 'selected' : '' ?>>Center</option>
                <option value="right" <?= $signature_align === 'right' ? 'selected' : '' ?>>Right</option>
            </select>
            <div class="form-text">Full-block letters sign left; modified-block often signs right.</div>
        </div>
        <div class="col-md-9">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="f_use_letterhead" <?= $use_letterhead ? 'checked' : '' ?>>
                <label class="form-check-label small fw-bold" for="f_use_letterhead">Include letterhead (logo &amp; sender address)</label>
                <div class="form-text">Turn off if printing onto physical pre-printed letterhead paper. The "Printed by" footer always stays &mdash; that's an audit line, not letterhead branding.</div>
            </div>
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

    <?php $ai_btn = aiButton('letterBody', 'document_letter', 'Generate letter with AI'); ?>
    <?php if ($ai_btn !== ''): ?>
    <div class="mb-2 d-print-none">
        <?= $ai_btn ?>
        <span class="text-muted small ms-1">Draft the letter body from a short instruction, then edit as needed.</span>
    </div>
    <?php endif; ?>

    <!-- The Summernote toolbar mounts here — kept OUTSIDE the letter-paper mockup
         (via the toolbarContainer option below) so it reads as an editor control
         bar, not as content printed inside the page. Sits OUTSIDE .letter-workspace
         (not nested in its 24px padding) and full width, same pattern as vikundi —
         same toolbar, same buttons, just no longer boxed to the A4 page width. -->
    <div class="letter-toolbar-bar d-print-none" id="letterToolbar"></div>

    <!-- Wide editing canvas (fills the desktop like the rest of BMS) — purely
         a screen backdrop. The A4-sized page inside it (#letterPaper) is what
         actually gets captured into the PDF, so its own size/padding must
         stay untouched here for print/PDF fidelity; only this outer canvas
         gets wider. -->
    <div class="letter-workspace">
        <!-- A4-styled letter preview. Only #letterBody is the Summernote-editable
             region — the letterhead/ref/date/signature block are rendered from
             structured fields so they can never be accidentally edited/broken by
             the rich-text editor. -->
        <div class="letter-paper-wrap d-print-none">
            <div class="letter-paper-label"><i class="bi bi-eye"></i> Live Preview — this is exactly how the saved PDF will look</div>
        </div>
        <div class="letter-paper mx-auto shadow-sm<?= $use_letterhead ? '' : ' no-letterhead' ?>" id="letterPaper">
        <div class="letter-head text-center">
            <?php if ($company_logo): ?>
                <img src="<?= getUrl($company_logo) ?>" alt="Logo" class="letter-logo">
            <?php endif; ?>
            <div class="letter-company"><?= htmlspecialchars($company_name) ?></div>
        </div>

        <!-- Sender info + Ref + Date — auto, read-only, follows Company Profile
             (toggled as one unit with the logo/name above via "Include
             letterhead"). No per-letter editing here and no recipient column
             either — the recipient's name/address is just written directly
             at the top of the one letter body below, same as everything
             else in it, with no separate field/toolbar to fight over. -->
        <div class="letter-addr-row">
            <div class="letter-addr-col letter-addr-sender">
                <div class="letter-sender-info">
                    <?php foreach ($sender_lines as $line): ?>
                        <div><?= nl2br(htmlspecialchars($line)) ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="letter-refno" id="letter-refno-display">Ref: <?= htmlspecialchars($document_code) ?></div>
                <div class="letter-date" id="letter-date-display"><?= htmlspecialchars(date('d M Y', strtotime($letter_date))) ?></div>
            </div>
        </div>

        <div class="letter-subject">
            <strong>RE: <span id="letter-subject-display"><?= htmlspecialchars($subject ?: '(Subject)') ?></span></strong>
        </div>

        <!-- The ONE writable canvas — recipient, salutation, body, everything.
             Write it all from the top, positioned/aligned however you want,
             using the one toolbar above. Starts genuinely blank; nothing is
             pre-filled here to delete first. -->
        <div id="letterBody"><?= $content !== '' ? $content : $default_body ?></div>

        <div class="letter-signoff align-<?= htmlspecialchars($signature_align) ?>" id="letterSignoff">
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
        </div>

        <!-- Shared BMS print footer (i_e_print.md §3) — same "Printed by /
             role / date" audit line + brand as every other print page.
             Always present regardless of the letterhead toggle: it's an
             audit trail, not company branding. -->
        <div class="letter-footer-wrap" id="letterFooterWrap">
            <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
        </div>
    </div>
    </div>
</div>

<?php if ($can_use_templates): ?>
<!-- Use Template Modal -->
<div class="modal fade" id="useTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-magic me-1"></i> Use a Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control mb-3" id="templatePickerSearch" placeholder="Search templates...">
                <div id="templatePickerList" class="list-group" style="max-height: 50vh; overflow-y: auto;">
                    <div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span> Loading templates...</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_manage_templates): ?>
<!-- Save as Template Modal -->
<div class="modal fade" id="saveTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bookmark-plus me-1"></i> Save as Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="saveTemplateForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Template Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="tpl_name" required placeholder="e.g. Standard Notice of Meeting">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select class="form-select select2-static" id="tpl_category_id">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $tc): ?>
                                <option value="<?= (int)$tc['id'] ?>"><?= htmlspecialchars($tc['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-text">Saves the current letter body only — not the recipient, subject, or reference number.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Template</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<link href="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-bs5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.9.1/dist/summernote-bs5.min.js"></script>

<style>
/* Canonical BMS print page margin (i_e_print.md §1) — the 16mm bottom band
   is what reserves room for the shared footer below so it never overlaps
   body text. Declared outside @media print so it applies globally. */
@page { margin: 10mm 8mm 16mm 8mm; }

/* Wide editing canvas — fills the desktop container like the rest of BMS
   instead of leaving the A4 page floating in blank space. Only this
   backdrop stretches; #letterPaper below keeps its own true A4 max-width so
   what gets captured into the PDF is unaffected. */
.letter-workspace {
    background: #eef0f2;
    border-radius: 12px;
    padding: 24px;
}
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

/* Editor ribbon — sits ABOVE the letter-paper page, like Word's ribbon above
   a blank document. Summernote's toolbarContainer option mounts the real
   toolbar into this box instead of inside #letterBody, so it never renders
   as if it were part of the printed page. Full width of the content area
   (not the A4 page width) and positioned outside .letter-workspace so its
   padding doesn't inset it — same full-bleed toolbar pattern as vikundi. */
.letter-toolbar-bar {
    width: 100%;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 16px;
}
.letter-toolbar-bar .note-toolbar {
    background: transparent !important;
    border: none !important;
}
.letter-head { margin-bottom: 14mm; }
.letter-logo { max-height: 70px; width: auto; display: block; margin: 0 auto 6px; }
.letter-company { font-size: 16pt; font-weight: 800; color: #0d6efd; text-transform: uppercase; letter-spacing: 1px; }

/* Sender + Ref + Date block — auto, read-only, right-aligned under the
   letterhead. No recipient column any more — the recipient is written
   directly at the top of the one letter body instead. */
.letter-addr-row { display: flex; justify-content: flex-end; margin-bottom: 8mm; }
.letter-addr-col { font-size: 10pt; color: #333; }
.letter-addr-sender { text-align: right; }
/* Sender info block — postal/physical address, phone, email, TIN, VRN, each
   its own line, matching how each is its own separate Company Profile
   setting. Only the fields actually filled in render (see $sender_lines). */
.letter-sender-info div { white-space: pre-line; margin-top: 1mm; }
.letter-refno { margin-top: 1mm; font-weight: 600; }
.letter-date { margin-top: 1mm; }

.letter-subject { font-size: 11pt; margin-bottom: 8mm; text-decoration: underline; }
#letterBody { font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.6; min-height: 60mm; }
/* Include letterhead = off: a truly blank canvas, per feedback — logo,
   company name, sender address, recipient block, date, and the subject line
   are ALL hidden (not just logo/sender), so the user builds everything from
   scratch straight into the body, top included. Only the body and the
   signature block remain — signature stays because it's still needed
   regardless of letterhead paper, and its alignment (left/center/right)
   stays freely adjustable exactly as before. */
.letter-paper.no-letterhead .letter-head,
.letter-paper.no-letterhead .letter-addr-row,
.letter-paper.no-letterhead .letter-subject { display: none !important; }

/* The editable region now lives directly inside the letter-paper mockup with
   no toolbar inside it (toolbarContainer moved that to #letterToolbar above),
   so it should blend into the page like blank space to write in, not look
   like a separate boxed widget. */
.note-editor.note-frame {
    border: none !important;
    box-shadow: none !important;
}
.note-editable { padding: 0 !important; }

/* Toolbar styling — targets the ribbon in #letterToolbar (see
   .letter-toolbar-bar above), since toolbarContainer mounts it there instead
   of inside .note-editor. */
.letter-toolbar-bar .note-toolbar {
    padding: 6px 8px !important;
    display: flex !important;
    flex-wrap: wrap;
    align-items: center;
    row-gap: 4px;
}
.letter-toolbar-bar .note-toolbar .note-btn-group {
    margin: 0 6px 0 0 !important;
    padding-right: 6px;
    border-right: 1px solid #dee2e6;
}
.letter-toolbar-bar .note-toolbar .note-btn-group:last-child { border-right: none; margin-right: 0 !important; }
.letter-toolbar-bar .note-toolbar .note-btn,
.letter-toolbar-bar .note-toolbar .note-dropdown-toggle {
    padding: 4px 7px !important;
    font-size: 0.82rem !important;
}

@media (max-width: 576px) {
    .letter-toolbar-bar .note-toolbar {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
    }
    .letter-toolbar-bar .note-toolbar .note-btn-group { flex: 0 0 auto; }
}

/* Signature position is a per-letter choice (see f_signature_align) — full-
   block letter style signs left, modified-block often signs right; never
   hardcode one. */
.letter-signoff { margin-top: 16mm; font-size: 11pt; }
.letter-signoff.align-left { text-align: left; }
.letter-signoff.align-center { text-align: center; }
.letter-signoff.align-right { text-align: right; }
.letter-signoff.align-left .letter-signature-box { margin: 0 auto 6px 0; }
.letter-signoff.align-center .letter-signature-box { margin: 0 auto 6px; }
.letter-signoff.align-right .letter-signature-box { margin: 0 0 6px auto; }
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

@media print {
    /* min-height:297mm gives a nice one-page WYSIWYG look on screen, but in
       print it forces every letter to reserve a full A4 page of height
       before any following content can continue — for a short letter this
       is a large dead blank area; for a long one it's still wrong (it should
       flow across exactly as many pages as the real content needs, not a
       fixed count). Let height come from content alone. */
    .letter-paper { border: none; max-width: 100%; box-shadow: none !important; border-radius: 0; min-height: 0; }
    /* The wide gray canvas is a screen-only editing affordance — strip it
       back to plain white for an actual browser print (window.print()). */
    .letter-workspace { background: none !important; padding: 0 !important; border-radius: 0 !important; }
    .letter-paper-label { display: none !important; }
    #createDocumentPage .btn, #createDocumentPage .form-label, #createDocumentPage input, #createDocumentPage select { display: none !important; }

    /* Reveal the shared footer only for the native print dialog — it stays
       hidden while editing. */
    .letter-footer-wrap { display: block !important; }

    /* The letter body wraps Summernote's own toolbar chrome — has zero place
       in a printed document. */
    .note-toolbar, .note-statusbar { display: none !important; }

    /* "Dear Sir/Madam..." was jumping whole onto page 2 with a large blank
       gap left on page 1 below the subject line. Two compounding causes:
       (1) Summernote's bs5 skin wraps the editable region in a Bootstrap
       `.card` (`.note-editor.note-frame.card`) — the shared responsive.css
       rule `.card { page-break-inside: avoid }` applies to every .card on
       every printed page, so the browser treats the WHOLE editor as one
       atomic, non-splittable block: if it doesn't fit the remaining space on
       page 1, the entire thing relocates to page 2 rather than just flowing
       across the boundary. (2) the editable area also carries an inline
       height:320px + overflow-y:auto (Summernote's own screen-editing
       scrollbox) — even with the page-break override below, a fixed/clipped
       height still doesn't let content reflow naturally across pages. Both
       need fixing together. */
    #createDocumentPage .note-editor.note-frame {
        page-break-inside: auto !important;
        break-inside: auto !important;
        border: none !important;
        box-shadow: none !important;
        height: auto !important;
        overflow: visible !important;
    }
    #createDocumentPage .note-editing-area,
    #createDocumentPage .note-editable {
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        overflow: visible !important;
        padding: 0 !important;
    }
}

/* Shared footer content — hidden while editing, shown only for the native
   browser print dialog (@media print rule below sets display:block). The
   saved PDF always includes it too — it's rendered server-side (TCPDF)
   unconditionally, with no "hidden while editing" concept to toggle. */
.letter-footer-wrap { display: none; }
</style>
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>

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
        toolbarContainer: '#letterToolbar',
        toolbar: [
            ['style', ['style']],
            ['fontname', ['fontname']],
            ['fontsize', ['fontsize']],
            ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph', 'height']],
            ['insert', ['table', 'link', 'picture', 'hr']],
            ['history', ['undo', 'redo']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        fontNames: ['Arial', 'Calibri', 'Georgia', 'Segoe UI', 'Times New Roman', 'Verdana'],
        fontSizes: ['8', '9', '10', '11', '12', '14', '16', '18', '24', '32']
    });

    // No separate sender or recipient editors — per feedback (and matching
    // the sister vikundi project's own editor), there is exactly ONE writable
    // canvas from the top of the letter to the bottom: recipient, sender
    // mention if the user wants one, salutation, body — all of it typed
    // directly into #letterBody, sharing the one #letterToolbar above.
    // Sender's own contact details (when letterhead is on) are auto-filled
    // from Company Profile and shown read-only, not part of any editor.

    $('#f_subject').on('input', function () {
        $('#letter-subject-display').text($(this).val() || '(Subject)');
    });
    $('#f_letter_date').on('change', function () {
        const d = new Date($(this).val() + 'T00:00:00');
        $('#letter-date-display').text(isNaN(d) ? '' : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }));
    });

    // Letterhead toggle — header, sender address block, and RE: subject line
    // all follow the same switch (the .no-letterhead CSS rule below already
    // hides .letter-head/.letter-addr-row/.letter-subject together), like
    // the equivalent control in the sister vikundi project.
    $('#f_use_letterhead').on('change', function () {
        $('#letterPaper').toggleClass('no-letterhead', !this.checked);
    });

    // Signature position — full-block vs modified-block letter styles
    // genuinely sign in different places, so this stays a live choice
    // instead of a fixed default.
    $('#f_signature_align').on('change', function () {
        $('#letterSignoff').removeClass('align-left align-center align-right').addClass('align-' + this.value);
    });

    $('#btnSaveDraft').on('click', function () { saveDocument('draft'); });
    $('#btnSavePrint').on('click', function () { saveDocument('print'); });
    $('#btnSaveSign').on('click', function () { saveDocument('sign'); });
    $('#btnDuplicate').on('click', duplicateDocument);

    // Insert Variable — drop a {{token}} at the cursor in the letter body.
    $('.insert-var-btn').on('click', function () {
        const token = '{{' + $(this).data('token') + '}}';
        $('#letterBody').summernote('focus');
        $('#letterBody').summernote('insertText', token);
    });

    // ── Use Template ──────────────────────────────────────────────
    $('#btnUseTemplate').on('click', function () {
        new bootstrap.Modal(document.getElementById('useTemplateModal')).show();
    });
    let templatesCache = null;
    $('#useTemplateModal').on('shown.bs.modal', function () {
        if (templatesCache) return; // already loaded this page session
        $.getJSON('<?= buildUrl('api/document/get_letter_templates.php') ?>', function (res) {
            if (!res.success) {
                $('#templatePickerList').html('<div class="text-center text-danger py-4">' + (res.message || 'Could not load templates.') + '</div>');
                return;
            }
            templatesCache = res.templates;
            renderTemplateList(templatesCache);
        }).fail(function () {
            $('#templatePickerList').html('<div class="text-center text-danger py-4">Server error loading templates.</div>');
        });
    });
    function renderTemplateList(templates) {
        if (!templates.length) {
            $('#templatePickerList').html('<div class="text-center text-muted py-4">No saved templates yet. Write a letter, then use "Save as Template" to create your first one.</div>');
            return;
        }
        let html = '';
        templates.forEach(function (t) {
            html += `<button type="button" class="list-group-item list-group-item-action tpl-pick" data-id="${t.id}">
                <div class="d-flex justify-content-between align-items-center">
                    <strong>${safeOutput(t.template_name)}</strong>
                    ${t.category_name ? `<span class="badge bg-primary-subtle text-primary">${safeOutput(t.category_name)}</span>` : ''}
                </div>
                <small class="text-muted">Used ${t.usage_count || 0} time(s)</small>
            </button>`;
        });
        $('#templatePickerList').html(html);
    }
    $('#templatePickerSearch').on('input', function () {
        if (!templatesCache) return;
        const q = $(this).val().toLowerCase();
        renderTemplateList(templatesCache.filter(t => t.template_name.toLowerCase().includes(q)));
    });
    // Restore a template's full structure — body (tokens kept intact so they
    // auto-fill afresh at save) plus subject, letterhead and signature
    // alignment — so reusing a template reproduces the whole letter, not
    // just its body. Fields the template didn't store (NULL) are left as-is.
    window.applyTemplate = function (tpl) {
        // Backward-compat: an OLDER template may still carry a recipient/
        // recipient_address it stored before there was one shared writable
        // canvas — there's no separate recipient field to put those into any
        // more, so fold them in as the opening lines of the body instead of
        // silently dropping them. Detect plain text (no tags) vs already-HTML
        // the same way the server does when reopening an old letter.
        const looksLikeHtml = (s) => /<[a-z][\s\S]*>/i.test(s);
        let recipientHtml = '';
        if (tpl.recipient != null && tpl.recipient !== '') {
            recipientHtml += looksLikeHtml(tpl.recipient) ? tpl.recipient
                : ('<div>' + $('<div>').text(tpl.recipient).html().replace(/\n/g, '<br>') + '</div>');
        }
        if (tpl.recipient_address != null && tpl.recipient_address !== '') {
            recipientHtml += '<div>' + $('<div>').text(tpl.recipient_address).html().replace(/\n/g, '<br>') + '</div>';
        }
        $('#letterBody').summernote('code', recipientHtml + (tpl.content || ''));
        if (tpl.subject != null && tpl.subject !== '')   { $('#f_subject').val(tpl.subject).trigger('input'); }
        if (tpl.use_letterhead != null && tpl.use_letterhead !== '') {
            $('#f_use_letterhead').prop('checked', String(tpl.use_letterhead) === '1').trigger('change');
        }
        if (tpl.signature_align != null && tpl.signature_align !== '') {
            $('#f_signature_align').val(tpl.signature_align).trigger('change');
        }
    };

    $('#templatePickerList').on('click', '.tpl-pick', function () {
        const id = $(this).data('id');
        const tpl = templatesCache.find(t => String(t.id) === String(id));
        if (!tpl) return;
        const apply = function () {
            applyTemplate(tpl);
            bootstrap.Modal.getInstance(document.getElementById('useTemplateModal')).hide();
        };
        if (!$('#letterBody').summernote('isEmpty')) {
            Swal.fire({
                title: 'Replace current letter body?',
                text: 'This will overwrite what you\'ve already written with the template.',
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, replace it'
            }).then(function (r) { if (r.isConfirmed) apply(); });
        } else {
            apply();
        }
    });

    // ── Save as Template ──────────────────────────────────────────
    $('#btnSaveTemplate').on('click', function () {
        if ($('#letterBody').summernote('isEmpty')) {
            Swal.fire({ icon: 'warning', title: 'Empty letter', text: 'Write the letter body first, then save it as a template.' });
            return;
        }
        new bootstrap.Modal(document.getElementById('saveTemplateModal')).show();
    });
    $('#saveTemplateModal').on('shown.bs.modal', function () {
        if (!$('#tpl_category_id').hasClass('select2-hidden-accessible')) {
            $('#tpl_category_id').select2({ theme: 'bootstrap-5', dropdownParent: $('#saveTemplateModal'), placeholder: 'Select...', allowClear: true, width: '100%' });
        }
    });
    $('#saveTemplateForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
        $.ajax({
            url: '<?= buildUrl('api/document/save_letter_template.php') ?>',
            type: 'POST',
            data: {
                template_name: $('#tpl_name').val(),
                category_id: $('#tpl_category_id').val(),
                // Body is stored WITH any {{tokens}} intact (not resolved) so
                // the template stays reusable; the structural fields are
                // captured too, so reusing it reproduces the whole letter.
                content: $('#letterBody').summernote('code'),
                subject: $('#f_subject').val().trim(),
                use_letterhead: $('#f_use_letterhead').is(':checked') ? '1' : '0',
                signature_align: $('#f_signature_align').val(),
                _csrf: CSRF_TOKEN
            },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    templatesCache = null; // force reload next time the picker opens
                    bootstrap.Modal.getInstance(document.getElementById('saveTemplateModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Template saved', timer: 1600, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not save the template.' });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error while saving the template.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });
});

function safeOutput(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}

function duplicateDocument() {
    Swal.fire({
        title: 'Duplicate this document?',
        text: 'Creates a new, separate draft with its own reference number — this one is left unchanged.',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, duplicate'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/document/duplicate_created_document.php') ?>',
            type: 'POST',
            data: { document_id: currentDocumentId, _csrf: CSRF_TOKEN },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    window.location.href = '<?= buildUrl('create_document') ?>?document_id=' + res.document_id;
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not duplicate the document.' });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error while duplicating.' }); }
        });
    });
}

// ── Merge-variable resolver (mirror of core/document_merge.php) ──────────────
// Company/static values come from PHP; recipient/subject/date read live from
// their fields at resolve time. A recognised token → its value (may be empty);
// an unrecognised {{x}} is left as typed. The server re-runs this at save as
// the authoritative safety pass, so the two must agree on token names.
function currentMergeValues() {
    const dateVal = $('#f_letter_date').val();
    let dateOut = '';
    if (dateVal) {
        const d = new Date(dateVal);
        if (!isNaN(d)) dateOut = d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    return {
        company_name:    <?= json_encode($company_name) ?>,
        company_address: <?= json_encode($company_address) ?>,
        company_phone:   <?= json_encode($company_phone) ?>,
        company_email:   <?= json_encode($company_email) ?>,
        company_tin:     <?= json_encode($company_tin) ?>,
        company_vrn:     <?= json_encode($company_vrn) ?>,
        document_code:   <?= json_encode($document_code) ?>,
        subject:            $('#f_subject').val().trim(),
        // No dedicated recipient field any more — the user writes the
        // recipient directly into the body itself, so these two tokens just
        // resolve to blank rather than anything circular.
        recipient:          '',
        recipient_address:  '',
        date:               dateOut,
        sender_name:     <?= json_encode($signer_name) ?>,
        sender_role:     <?= json_encode($signer_role) ?>,
        project_name:    <?= json_encode($project_name ?? '') ?>,
        contract_number: <?= json_encode($project_contract_number ?? '') ?>
    };
}
function resolveMergeTokens(html) {
    if (!html || html.indexOf('{{') === -1) return html;
    const v = currentMergeValues();
    return html.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, function (m, key) {
        return Object.prototype.hasOwnProperty.call(v, key) ? (v[key] || '') : m;
    });
}

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

    // Resolve any {{tokens}} into real values before rendering the PDF and
    // storing — idempotent, so running it on already-resolved text is a no-op.
    $('#letterBody').summernote('code', resolveMergeTokens($('#letterBody').summernote('code')));

    const $btn = mode === 'draft' ? $('#btnSaveDraft') : (mode === 'print' ? $('#btnSavePrint') : $('#btnSaveSign'));
    const orig = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');

    const useLetterhead = $('#f_use_letterhead').is(':checked');

    // The PDF is now generated server-side (TCPDF, see
    // core/document_letter_render.php) from these same structured fields —
    // the client just posts them, it no longer renders/uploads a PDF itself.
    // No separate recipient/sender fields — the one letter body (everything
    // the user wrote, from the top down) is the whole content; sender info
    // (when letterhead is on) is auto-filled from Company Profile server-side.
    const fd = new FormData();
    fd.append('document_id', currentDocumentId);
    fd.append('subject', subject);
    fd.append('letter_date', $('#f_letter_date').val());
    fd.append('category_id', $('#f_category_id').val() || '');
    fd.append('access_level', $('#f_access_level').val() || 'private');
    fd.append('use_letterhead', useLetterhead ? '1' : '0');
    fd.append('signature_align', $('#f_signature_align').val() || 'left');
    fd.append('project_id', '<?= (int)($project_id ?? 0) ?>');
    fd.append('content', $('#letterBody').summernote('code'));
    fd.append('_csrf', CSRF_TOKEN);

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
                // Save & Sign — hand straight into the e-signature wizard, same
                // destination the original Phase 1 "Save & Sign" shortcut used.
                window.location.href = '<?= buildUrl('select_document_add_esignature') ?>';
            }
        },
        error: function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error while saving.' });
            $btn.prop('disabled', false).html(orig);
        }
    });
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
