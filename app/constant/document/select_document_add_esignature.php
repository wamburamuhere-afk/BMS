<?php
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

// Paths are relative to root directory
includeHeader();

// Enforce permission
if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('documents');
}

// Fetch categories for the quick upload
$categories = $pdo->query("SELECT * FROM document_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Current user — printed on the Certificate of Completion
$signerStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE user_id = ?");
$signerStmt->execute([$_SESSION['user_id']]);
$signer      = $signerStmt->fetch(PDO::FETCH_ASSOC) ?: ['first_name' => '', 'last_name' => '', 'email' => ''];
$signerName  = trim(($signer['first_name'] ?? '') . ' ' . ($signer['last_name'] ?? ''));
$signerEmail = $signer['email'] ?? '';
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-file-earmark-check"></i> Document Signing Wizard</h2>
                    <p class="text-muted mb-0">Select a document and apply your signature with precision</p>
                </div>
                <a href="e_signatures.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Signatures
                </a>
            </div>
        </div>
    </div>

    <!-- Wizard Steps -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="wizard-steps d-flex justify-content-between mb-4">
                <div class="wizard-step active" data-step="1">
                    <div class="step-icon">1</div>
                    <div class="step-text">Choose Document</div>
                </div>
                <div class="wizard-step" data-step="2">
                    <div class="step-icon">2</div>
                    <div class="step-text">Select Signature</div>
                </div>
                <div class="wizard-step" data-step="3">
                    <div class="step-icon">3</div>
                    <div class="step-text">Position & Sign</div>
                </div>
                <div class="wizard-step" data-step="4">
                    <div class="step-icon">4</div>
                    <div class="step-text">Finish</div>
                </div>
            </div>

            <!-- Step 1: Select/Upload Document -->
            <div class="step-content" id="step-1">
                <ul class="nav nav-pills mb-3" id="docTypeTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="library-tab" data-bs-toggle="pill" data-bs-target="#library-pane" type="button">
                            <i class="bi bi-folder"></i> From Library
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="upload-tab" data-bs-toggle="pill" data-bs-target="#upload-pane" type="button">
                            <i class="bi bi-cloud-upload"></i> Upload New
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="docTypeTabContent">
                    <!-- Library Pane -->
                    <div class="tab-pane fade show active" id="library-pane">
                        <div class="table-responsive">
                            <table id="wizardDocumentsTable" class="table table-hover align-middle w-100">
                                <thead class="bg-light">
                                    <tr>
                                        <th width="50"></th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Size</th>
                                        <th>Uploaded</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="mt-2 small text-muted">
                            <i class="bi bi-info-circle"></i> All document types including PDFs, Word documents, and images are supported for signing.
                        </div>
                    </div>

                    <!-- Upload Pane -->
                    <div class="tab-pane fade" id="upload-pane">
                        <form id="wizardQuickUploadForm">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Document Name *</label>
                                    <input type="text" class="form-control" name="document_name" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">File Selection *</label>
                                    <input type="file" class="form-control" name="document_file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.bmp">
                                    <div class="form-text">Supported formats: PDF, Word documents, and images (JPG, PNG, GIF, BMP). Max size: 50MB.</div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary" id="btnWizardUpload">
                                        <i class="bi bi-cloud-upload"></i> Upload and Select
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Step 2: Select Signature -->
            <div class="step-content d-none" id="step-2">
                <div class="alert alert-info py-2 m-0 mb-3">
                    <i class="bi bi-file-earmark-check"></i> Selected Document: <strong id="selected-doc-name">-</strong>
                </div>

                <!-- Who signs this? Internal (default, unchanged flow below) vs
                     external (a client/supplier with no BMS login — emailed a
                     single-use link to sign_document.php instead). -->
                <div class="mb-4">
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="signerType" id="signerTypeInternal" checked>
                        <label class="btn btn-outline-primary" for="signerTypeInternal"><i class="bi bi-person-check"></i> I will sign this document</label>
                        <input type="radio" class="btn-check" name="signerType" id="signerTypeExternal">
                        <label class="btn btn-outline-primary" for="signerTypeExternal"><i class="bi bi-send"></i> Send to someone else to sign</label>
                    </div>
                </div>

                <div id="internalSignerPanel">
                    <h5 class="mb-3">Choose Your Signature</h5>
                    <div class="row g-3" id="wizardSignatureGrid">
                        <!-- Loaded via AJAX -->
                    </div>
                </div>

                <div id="externalSignerPanel" class="d-none">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Signer Name</label>
                            <input type="text" class="form-control" id="extSignerName" placeholder="e.g. Jane Doe, ABC Suppliers Ltd">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Signer Email</label>
                            <input type="email" class="form-control" id="extSignerEmail" placeholder="jane@abcsuppliers.com">
                        </div>
                    </div>
                    <p class="small text-muted mt-2 mb-3">
                        They'll receive an emailed link, valid for 7 days and usable once, to view and sign this
                        document — no BMS account required.
                    </p>
                    <div id="extSignerMessage"></div>
                    <div id="extSignerSendRow">
                        <button type="button" class="btn btn-primary" id="btnSendExternalRequest">
                            <i class="bi bi-send"></i> Send Signature Request
                        </button>
                    </div>
                    <div id="extSignerSentRow" class="d-none text-center py-4">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
                        <h5 class="mt-3">Request sent</h5>
                        <p class="text-muted" id="extSignerSentMessage"></p>
                        <a href="e_signatures.php" class="btn btn-outline-secondary mt-2"><i class="bi bi-house"></i> Done</a>
                    </div>
                </div>
            </div>

            <!-- Step 3: Position Signature -->
            <div class="step-content d-none" id="step-3">
                <div class="row">
                    <div class="col-lg-8">
                        <div class="document-preview-container bg-white rounded border p-2 mb-3 text-center" style="min-height: 600px; position: relative; overflow: auto; background-color: #525659 !important;">
                            <!-- PDF Canvas will be inserted here -->
                            <div id="sign-placement-area" style="position: relative; display: inline-block; margin: 20px auto; background: white; box-shadow: 0 0 10px rgba(0,0,0,0.5);">
                                <canvas id="pdf-render-canvas"></canvas>
                                <div id="draggable-signature" style="position: absolute; cursor: move; display: none; border: 2px dashed #0d6efd; padding: 0; background: rgba(13, 110, 253, 0.05); z-index: 1000;">
                                    <img src="" id="sig-overlay-img" style="max-height: 80px; pointer-events: none;">
                                    <div class="sig-handle text-primary bg-white rounded-circle shadow-sm" style="position: absolute; top: -10px; right: -10px; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 14px; border: 1px solid #0d6efd;"><i class="bi bi-arrows-move"></i></div>
                                </div>
                            </div>
                            <div id="preview-loading" class="py-5 text-white">
                                <div class="spinner-border" role="status"></div>
                                <p class="mt-2">Rendering PDF Preview...</p>
                            </div>
                        </div>
                        <div class="d-flex justify-content-center mb-3">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-secondary" onclick="changePage(-1)"><i class="bi bi-chevron-left"></i> Previous Page</button>
                                <span class="btn btn-light disabled">Page <span id="page-num">0</span> of <span id="page-count">0</span></span>
                                <button class="btn btn-secondary" onclick="changePage(1)">Next Page <i class="bi bi-chevron-right"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card bg-light border-0">
                            <div class="card-body">
                                <h6>Positioning Controls</h6>
                                <p class="small text-muted mb-3">Drag the signature on the document or use presets below.</p>
                                
                                <div class="mb-3">
                                    <label class="form-label d-block">Quick Presets</label>
                                    <div class="btn-group w-100" role="group">
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setPresetPosition('bottom_left')">Bottom Left</button>
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setPresetPosition('bottom_center')">Center</button>
                                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="setPresetPosition('bottom_right')">Bottom Right</button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Signature Scale</label>
                                    <input type="range" class="form-range" id="sig-scale" min="50" max="200" value="100">
                                    <div class="d-flex justify-content-between small text-muted">
                                        <span>50%</span>
                                        <span>100%</span>
                                        <span>200%</span>
                                    </div>
                                </div>

                                <div class="alert alert-warning small">
                                    <i class="bi bi-info-circle"></i> This signature will be applied to the <strong>bottom</strong> of the document.
                                </div>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="wizardLegalCheck">
                                    <label class="form-check-label small" for="wizardLegalCheck">
                                        I agree that my electronic signature applied to this document is
                                        legally binding and the electronic equivalent of my handwritten signature.
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 4: Finish -->
            <div class="step-content d-none" id="step-4">
                <div class="text-center py-5">
                    <div id="signing-progress">
                        <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;"></div>
                        <h5>Applying Signature...</h5>
                        <p class="text-muted">Generating your signed document, please wait.</p>
                    </div>
                    <div id="signing-success" class="d-none">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                        <h4 class="mt-3">Document Signed!</h4>
                        <p class="text-muted">Your document has been signed, sealed with a tamper-evident
                            certificate, and saved in the history.</p>
                        <p class="small text-muted mb-3">
                            Signing reference: <code id="signed-ref">—</code>
                        </p>
                        <div class="mt-4 d-flex flex-wrap gap-2 justify-content-center">
                            <button class="btn btn-success btn-lg px-4" id="btnDownloadSigned">
                                <i class="bi bi-download"></i> Download PDF
                            </button>
                            <button class="btn btn-outline-primary btn-lg px-4" id="btnVerifySigned">
                                <i class="bi bi-shield-check"></i> Verify Integrity
                            </button>
                            <a href="e_signatures.php" class="btn btn-outline-secondary btn-lg px-4">
                                <i class="bi bi-house"></i> Done
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between py-3">
            <button class="btn btn-outline-secondary" id="btnBack" onclick="changeStep(-1)" disabled>
                <i class="bi bi-arrow-left"></i> Previous
            </button>
            <button class="btn btn-primary" id="btnNext" onclick="changeStep(1)" disabled>
                Next Step <i class="bi bi-arrow-right"></i>
            </button>
            <button class="btn btn-success d-none" id="btnFinalSign" onclick="processFinalSign()">
                <i class="bi bi-pen"></i> Apply Signature
            </button>
        </div>
    </div>
</div>

<style>
.wizard-steps {
    display: flex;
    position: relative;
    padding-bottom: 30px;
    margin-top: 10px;
}
.wizard-steps::before {
    content: '';
    position: absolute;
    top: 25px;
    left: 10%;
    right: 10%;
    height: 3px;
    background: #f1f3f5;
    z-index: 0;
}
.wizard-step {
    position: relative;
    z-index: 1;
    text-align: center;
    flex: 1;
    transition: all 0.4s ease;
}
.step-icon {
    width: 50px;
    height: 50px;
    background: #fff;
    border: 3px solid #f1f3f5;
    border-radius: 50%;
    margin: 0 auto 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.2rem;
    color: #ced4da;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.wizard-step.active .step-icon {
    background: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
    transform: scale(1.1);
    box-shadow: 0 0 20px rgba(13, 110, 253, 0.3);
}
.wizard-step.completed .step-icon {
    background: #198754;
    border-color: #198754;
    color: #fff;
}
.step-text {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 600;
    letter-spacing: 0.5px;
}
.wizard-step.active .step-text { color: #0d6efd; }
.wizard-step.completed .step-text { color: #198754; }

.signature-card {
    border: 2px solid transparent;
    cursor: pointer;
    transition: all 0.3s ease;
    border-radius: 12px;
    overflow: hidden;
}
.signature-card:hover { 
    border-color: #0d6efd; 
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.08);
}
.signature-card.active { 
    border-color: #0d6efd; 
    background-color: #f0f7ff;
    box-shadow: 0 5px 15px rgba(13, 110, 253, 0.1);
}

.document-preview-container {
    background-image: radial-gradient(#dee2e6 1px, transparent 1px);
    background-size: 20px 20px;
    border-radius: 15px !important;
    box-shadow: inset 0 0 50px rgba(0,0,0,0.02);
}

#draggable-signature {
    user-select: none;
    touch-action: none;
    transition: box-shadow 0.2s;
    z-index: 100;
}
#draggable-signature:active {
    box-shadow: 0 0 0 1000px rgba(0,0,0,0.1);
}

.sig-handle {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

#wizardDocumentsTable_wrapper .dataTables_filter input {
    border-radius: 20px;
    padding-left: 15px;
    border: 1px solid #dee2e6;
}

.nav-pills .nav-link {
    border-radius: 20px;
    padding: 8px 20px;
    font-weight: 500;
}
</style>

<!-- Interact and PDF.js are kept as they are not in footer -->
<script src="<?= getUrl('assets/js/interact.min.js') ?>"></script>
<script src="<?= getUrl('assets/js/pdf.min.js') ?>"></script>
<script src="<?= getUrl('assets/js/pdf-lib.min.js') ?>"></script>
<!-- DataTables JS is handled by footer.php -->

<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = '<?= getUrl("assets/js/pdf.worker.min.js") ?>';

let currentStep = 1;
let selectedDocId = null;
let selectedDocName = '';
let selectedDocPath = '';
let selectedSigId = null;
let selectedSigPath = '';
let posX = 0, posY = 0;
let pageNum = 1;
let pdfDoc = null;
let pageRendering = false;
let pageNumPending = null;
let scale = 1.5;
let canvas = document.getElementById('pdf-render-canvas');
let ctx = canvas.getContext('2d');

// ── E-signature audit state ──────────────────────────────────────────────
const CONSENT_TEXT = 'I agree that my electronic signature applied to this document is legally binding and the electronic equivalent of my handwritten signature.';
const SIGNER_NAME  = <?= json_encode($signerName) ?>;
const SIGNER_EMAIL = <?= json_encode($signerEmail) ?>;
let viewedAt = null;          // ISO time the signer first previewed the document (step 3)
let consentAt = null;         // ISO time the signer accepted the consent statement
let signingReference = null;  // unique reference printed on the certificate

$(document).ready(function() {
    initDataTable();
    
    // Interact.js for dragging
    interact('#draggable-signature').draggable({
        inertia: true,
        modifiers: [
            interact.modifiers.restrictRect({
                restriction: 'parent',
                endOnly: true
            })
        ],
        autoScroll: true,
        listeners: {
            move (event) {
                const target = event.target;
                const x = (parseFloat(target.getAttribute('data-x')) || 0) + event.dx;
                const y = (parseFloat(target.getAttribute('data-y')) || 0) + event.dy;

                target.style.transform = `translate(${x}px, ${y}px)`;
                target.setAttribute('data-x', x);
                target.setAttribute('data-y', y);
                
                // Keep track of internal coordinates
                posX = x;
                posY = y;
            }
        }
    });

    // Signature Scale
    $('#sig-scale').on('input', function() {
        const scale = $(this).val() / 100;
        $('#sig-overlay-img').css('transform', `scale(${scale})`);
    });

    // Legal Check — capture the exact moment consent is accepted
    $('#wizardLegalCheck').on('change', function() {
        $('#btnFinalSign').prop('disabled', !this.checked);
        if (this.checked && !consentAt) {
            consentAt = new Date().toISOString();
        }
    });
});

function initDataTable() {
    $('#wizardDocumentsTable').DataTable({
        responsive: true,
        serverSide: true,
        ajax: {
            url: '<?= buildUrl("api/get_documents.php") ?>'
        },
        columns: [
            { 
                data: 'id',
                render: function(data, type, row) {
                    return `<input type="radio" class="form-check-input" name="doc_select" value="${data}" data-name="${escapeHtml(row.document_name)}" data-path="${row.file_path}">`;
                }
            },
            { data: 'document_name', render: (d) => `<strong>${escapeHtml(d)}</strong>` },
            { data: 'category_name', render: (d) => d || 'General' },
            { data: 'file_size', render: (d) => formatFileSize(d) },
            { data: 'uploaded_at', render: (d) => new Date(d).toLocaleDateString() }
        ],
        order: [[4, 'desc']],
        pageLength: 5,
        lengthMenu: [5, 10, 25]
    });

    // Handle selection
    $('#wizardDocumentsTable').on('change', 'input[name="doc_select"]', function() {
        selectedDocId = $(this).val();
        selectedDocName = $(this).data('name');
        selectedDocPath = $(this).data('path');
        $('#selected-doc-name').text(selectedDocName);
        validateStep();
    });
}

function changeStep(dir) {
    const next = currentStep + dir;
    if (next < 1 || next > 4) return;

    $(`#step-${currentStep}`).addClass('d-none');
    $(`#step-${next}`).removeClass('d-none');

    const $cur  = $(`.wizard-step[data-step="${currentStep}"]`);
    const $next = $(`.wizard-step[data-step="${next}"]`);

    $cur.removeClass('active');
    if (dir > 0) {
        $cur.addClass('completed');
    } else {
        $cur.removeClass('completed');
    }
    $next.removeClass('completed').addClass('active');

    currentStep = next;

    if (currentStep === 2) loadSignatures();
    if (currentStep === 3) initPlacement();

    updateButtons();
}

function updateButtons() {
    const onLast = currentStep === 4;
    const onSign = currentStep === 3;

    // On step 2, external-signer mode has its own Send Request action inside
    // the panel — the normal Previous/Next footer doesn't apply there.
    const onExternalStep2 = currentStep === 2 && isExternalMode();

    // Back button — hidden only on step 4 or external-mode step 2, disabled only on step 1
    $('#btnBack').toggleClass('d-none', onLast || onExternalStep2).prop('disabled', currentStep === 1);

    // Next / Sign / hidden on step 4
    if (onLast || onExternalStep2) {
        $('#btnNext').addClass('d-none');
        $('#btnFinalSign').addClass('d-none');
    } else if (onSign) {
        $('#btnNext').addClass('d-none').removeClass('d-inline-block');
        $('#btnFinalSign').removeClass('d-none').addClass('d-inline-block');
    } else {
        $('#btnNext').removeClass('d-none').addClass('d-inline-block');
        $('#btnFinalSign').addClass('d-none').removeClass('d-inline-block');
    }

    validateStep();
}

function validateStep() {
    let valid = false;
    if (currentStep === 1) valid = selectedDocId !== null;
    if (currentStep === 2) valid = isExternalMode() ? false : selectedSigId !== null;
    if (currentStep === 3) valid = $('#wizardLegalCheck').is(':checked');

    $('#btnNext').prop('disabled', !valid);
}

// ── External signer (send-to-client) ──────────────────────────────────────
// Self-contained within step 2: sending the request happens right here via
// AJAX, so the wizard never advances into steps 3/4 (positioning/embedding
// a LOCAL signature image) for a document someone else is going to sign.
function isExternalMode() {
    return $('#signerTypeExternal').is(':checked');
}

$(document).on('change', 'input[name="signerType"]', function () {
    const external = isExternalMode();
    $('#internalSignerPanel').toggleClass('d-none', external);
    $('#externalSignerPanel').toggleClass('d-none', !external);
    // The normal step footer (Previous/Next Step) doesn't apply once sending
    // an external request — that action lives entirely inside the panel.
    $('#btnBack').toggleClass('d-none', external);
    $('#btnNext').toggleClass('d-none', external);
    validateStep();
});

$(document).on('click', '#btnSendExternalRequest', function () {
    const $btn = $(this);
    const name = $('#extSignerName').val().trim();
    const email = $('#extSignerEmail').val().trim();
    const $msg = $('#extSignerMessage');
    $msg.html('');

    if (!name) { $msg.html('<div class="alert alert-warning py-2">Please enter the signer\'s name.</div>'); return; }
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { $msg.html('<div class="alert alert-warning py-2">Please enter a valid email address.</div>'); return; }
    if (!selectedDocId) { $msg.html('<div class="alert alert-warning py-2">Please go back and select a document first.</div>'); return; }

    const orig = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Sending...');

    $.ajax({
        url: '<?= buildUrl("api/document/request_external_signature.php") ?>',
        type: 'POST',
        data: {
            document_id: selectedDocId,
            signer_name: name,
            signer_email: email,
            _csrf: CSRF_TOKEN,
        },
        dataType: 'json',
        success: function (res) {
            if (!res.success) {
                $msg.html('<div class="alert alert-danger py-2">' + (res.message || 'Could not send the request.') + '</div>');
                $btn.prop('disabled', false).html(orig);
                return;
            }
            $('#extSignerSendRow').addClass('d-none');
            $('#extSignerSentMessage').text(res.message);
            $('#extSignerSentRow').removeClass('d-none');
        },
        error: function () {
            $msg.html('<div class="alert alert-danger py-2">Server error while sending the request.</div>');
            $btn.prop('disabled', false).html(orig);
        }
    });
});

function loadSignatures() {
    const grid = $('#wizardSignatureGrid');
    grid.html('<div class="col-12 text-center p-5"><div class="spinner-border text-primary"></div></div>');
    
    $.get('<?= buildUrl("api/document/get_user_signatures_list.php") ?>', function(data) {
        if (!data || data.length === 0) {
            grid.html('<div class="col-12 text-center p-4">No signatures found. <a href="e_signatures.php">Create one</a></div>');
            return;
        }

        let html = '';
        data.forEach(sig => {
            const sigUrl = sigImageUrl(sig);
            html += `
                <div class="col-md-4">
                    <div class="card h-100 signature-card ${selectedSigId == sig.id ? 'active' : ''}"
                         onclick="selectSignature(${sig.id}, '${sigUrl}', this)">
                        <div class="card-body text-center p-3">
                            <img src="${sigUrl}" class="img-fluid" style="max-height: 80px;">
                            <div class="mt-2 small font-weight-bold text-uppercase">${sig.signature_type}</div>
                        </div>
                    </div>
                </div>
            `;
        });
        grid.html(html);
    });
}

function selectSignature(id, path, el) {
    selectedSigId = id;
    selectedSigPath = path;
    $('.signature-card').removeClass('active');
    $(el).addClass('active');
    validateStep();
}

function initPlacement() {
    if (!viewedAt) viewedAt = new Date().toISOString();
    $('#sig-overlay-img').attr('src', selectedSigPath);
    $('#draggable-signature').show();
    
    if (selectedDocPath && selectedDocPath.toLowerCase().endsWith('.pdf')) {
        $('#preview-loading').show().html('<div class="spinner-border" role="status"></div><p class="mt-2">Rendering PDF Preview...</p>');
        $('#sign-placement-area').css('visibility', 'hidden');
        
        // Use the direct file URL for pdf.js rendering.
        // The download endpoint sends Content-Disposition:attachment which some
        // pdf.js versions reject; the direct path avoids that entirely.
        const url = '<?= rtrim(getUrl(""), "/") ?>/' + selectedDocPath;

        const loadingTask = pdfjsLib.getDocument({ url: url, withCredentials: true });

        loadingTask.promise.then(function(pdfDoc_) {
            console.log('PDF loaded successfully');
            pdfDoc = pdfDoc_;
            $('#page-count').text(pdfDoc.numPages);
            pageNum = 1; // Reset to page 1
            renderPage(pageNum);
        }).catch(function(error) {
            console.error('PDF Load Error Details:', error);
            $('#preview-loading').html(`
                <div class="text-danger">
                    <i class="bi bi-exclamation-octagon fs-1"></i>
                    <p class="mt-2">Failed to load PDF preview.</p>
                    <small class="d-block">${error.message || 'Unknown error'}</small>
                    <button class="btn btn-sm btn-outline-light mt-3" onclick="initPlacement()">Retry</button>
                </div>
            `);
        });
    } else {
        $('#preview-loading').hide();
        $('#sign-placement-area').css('visibility', 'visible');
    }
}

function renderPage(num) {
    pageRendering = true;
    pdfDoc.getPage(num).then(function(page) {
        var viewport = page.getViewport({scale: scale});
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        var renderContext = {
            canvasContext: ctx,
            viewport: viewport
        };
        var renderTask = page.render(renderContext);

        renderTask.promise.then(function() {
            pageRendering = false;
            $('#preview-loading').hide();
            $('#sign-placement-area').css('visibility', 'visible');
            $('#page-num').text(num);
            if (pageNumPending !== null) {
                renderPage(pageNumPending);
                pageNumPending = null;
            }
        });
    });
}

function queueRenderPage(num) {
    if (pageRendering) {
        pageNumPending = num;
    } else {
        renderPage(num);
    }
}

function changePage(dir) {
    if (pageNum + dir <= 0 || pageNum + dir > pdfDoc.numPages) return;
    pageNum += dir;
    queueRenderPage(pageNum);
}

function setPresetPosition(pos) {
    const $parent = $('#sign-placement-area');
    const $sig    = $('#draggable-signature');

    const pW = $parent.width();
    const pH = $parent.height();
    const sW = $sig.outerWidth();
    const sH = $sig.outerHeight();
    const margin = 10;

    let x = 0, y = 0;
    if (pos === 'bottom_left') {
        x = margin;
        y = pH - sH - margin;
    } else if (pos === 'bottom_center') {
        x = (pW - sW) / 2;
        y = pH - sH - margin;
    } else if (pos === 'bottom_right') {
        x = pW - sW - margin;
        y = pH - sH - margin;
    }

    $sig.css('transform', `translate(${x}px, ${y}px)`)
        .attr('data-x', x)
        .attr('data-y', y);

    posX = x;
    posY = y;
}

async function processFinalSign() {
    if (!$('#wizardLegalCheck').is(':checked')) {
        Swal.fire('Consent required', 'Please accept the consent statement before signing.', 'warning');
        return;
    }

    // Only PDFs can be sealed with a verifiable Certificate of Completion.
    if (!selectedDocPath || !selectedDocPath.toLowerCase().endsWith('.pdf')) {
        Swal.fire({
            icon: 'info',
            title: 'PDF documents only',
            html: 'Only <strong>PDF documents</strong> can be signed with a tamper-evident ' +
                  'certificate.<br>Please convert this file to PDF and upload it again.'
        });
        return;
    }

    if (!consentAt) consentAt = new Date().toISOString();
    signingReference = 'SIG-' + (
        (window.crypto && crypto.randomUUID)
            ? crypto.randomUUID().split('-')[0].toUpperCase()
            : Math.random().toString(16).slice(2, 10).toUpperCase()
    );

    changeStep(1); // advance to step 4 (spinner)

    try {
        const result = await embedSignatureIntoPdf();

        $('#signing-progress').addClass('d-none');
        $('#signing-success').removeClass('d-none');
        $('#signed-ref').text(result.signing_reference || signingReference);

        $('#btnDownloadSigned').off('click').on('click', function () {
            window.location.href = '<?= buildUrl("document_library") ?>?action=download&document_id=' + result.new_document_id;
        });
        $('#btnVerifySigned').off('click').on('click', function () {
            verifySignedDocument(result.new_document_id);
        });

    } catch (err) {
        Swal.fire('Signing failed', err.message || 'Signing failed. Please try again.', 'error');
        changeStep(-1);
    }
}

// SHA-256 of an ArrayBuffer -> lowercase hex. Returns null if Web Crypto is unavailable.
async function sha256Hex(buffer) {
    if (!window.crypto || !crypto.subtle) return null;
    try {
        const digest = await crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(digest))
            .map(b => b.toString(16).padStart(2, '0')).join('');
    } catch (e) {
        return null;
    }
}

// Append a Certificate of Completion page to the signed PDF (pure pdf-lib).
async function appendCertificatePage(pdfLibDoc, cert) {
    const { StandardFonts, rgb } = PDFLib;
    const font     = await pdfLibDoc.embedFont(StandardFonts.Helvetica);
    const fontBold = await pdfLibDoc.embedFont(StandardFonts.HelveticaBold);

    const page = pdfLibDoc.addPage([595.28, 841.89]); // A4 portrait
    const W = 595.28, H = 841.89, M = 56;
    const ink   = rgb(0.13, 0.13, 0.13);
    const muted = rgb(0.42, 0.42, 0.42);
    const brand = rgb(0.05, 0.43, 0.99);

    let y = H - M;
    page.drawText('CERTIFICATE OF COMPLETION', { x: M, y, size: 18, font: fontBold, color: brand });
    y -= 20;
    page.drawText('Electronic Signature Record', { x: M, y, size: 10, font, color: muted });
    y -= 16;
    page.drawLine({ start: { x: M, y }, end: { x: W - M, y }, thickness: 1, color: brand });
    y -= 34;

    // Word-wrap helper — strips characters the standard PDF font cannot encode
    const wrap = (text, size, f, maxW) => {
        const safe  = String(text).replace(/[^\x20-\x7E\xA0-\xFF–—]/g, '?');
        const words = safe.split(/\s+/);
        const lines = [];
        let line = '';
        words.forEach(w => {
            const test = line ? line + ' ' + w : w;
            if (f.widthOfTextAtSize(test, size) > maxW && line) {
                lines.push(line); line = w;
            } else { line = test; }
        });
        if (line) lines.push(line);
        return lines;
    };

    const row = (label, value) => {
        page.drawText(label.toUpperCase(), { x: M, y, size: 8, font: fontBold, color: muted });
        y -= 14;
        wrap(value || '—', 11, font, W - 2 * M).forEach(ln => {
            page.drawText(ln, { x: M, y, size: 11, font, color: ink });
            y -= 15;
        });
        y -= 10;
    };

    row('Document', cert.documentName);
    row('Digitally signed by', cert.signerName + (cert.signerEmail ? '  <' + cert.signerEmail + '>' : ''));
    row('Date & time', cert.signedAt + '  (server-recorded, tamper-evident)');
    row('Signing reference', cert.signingReference);
    row('Original document fingerprint (SHA-256)',
        cert.originalHash || 'Recorded in the BMS signature register');
    row('Consent statement accepted', cert.consentText);

    y -= 6;
    page.drawLine({ start: { x: M, y }, end: { x: W - M, y }, thickness: 0.5, color: muted });
    y -= 18;
    wrap('This certificate page is part of the signed PDF. The document\'s integrity can be ' +
         'verified at any time inside BMS — any change to the file after signing will be detected.',
         9, font, W - 2 * M).forEach(ln => {
        page.drawText(ln, { x: M, y, size: 9, font, color: muted });
        y -= 13;
    });
}

async function embedSignatureIntoPdf() {
    const pdfRenderScale = 1.5; // must match the scale variable used by PDF.js
    const userSigScale   = parseInt($('#sig-scale').val()) / 100;

    // 1. Fetch the original PDF bytes
    const pdfUrl = '<?= buildUrl("document_library") ?>?action=download&document_id=' + selectedDocId;
    const pdfResp = await fetch(pdfUrl, { credentials: 'include' });
    if (!pdfResp.ok) throw new Error('Could not fetch original PDF (HTTP ' + pdfResp.status + ')');
    const contentType = pdfResp.headers.get('Content-Type') || '';
    if (!contentType.includes('application/pdf') && !contentType.includes('octet-stream')) {
        throw new Error('The selected document file was not found on the server. Please re-upload the document and try again.');
    }
    const pdfBytes = await pdfResp.arrayBuffer();

    // 1b. Fingerprint the original document (SHA-256) for the certificate page
    const originalHash = await sha256Hex(pdfBytes.slice(0));

    // 2. Load with pdf-lib
    const pdfLibDoc = await PDFLib.PDFDocument.load(pdfBytes);

    // 3. Get target page (pdf-lib is 0-indexed)
    const pageIndex = pageNum - 1;
    if (pageIndex >= pdfLibDoc.getPageCount()) throw new Error('Page number out of range');
    const pdfPage = pdfLibDoc.getPage(pageIndex);
    const { height: pageH } = pdfPage.getSize();

    // 4. Fetch the signature image bytes
    const sigImgEl = document.getElementById('sig-overlay-img');
    const sigResp  = await fetch(sigImgEl.src, { credentials: 'include' });
    if (!sigResp.ok) throw new Error('Could not fetch signature image');
    const sigBytes = await sigResp.arrayBuffer();

    // 5. Embed image — pdf-lib supports PNG and JPG
    const srcLower = sigImgEl.src.toLowerCase();
    let embeddedSig;
    if (srcLower.endsWith('.png') || srcLower.includes('/png')) {
        embeddedSig = await pdfLibDoc.embedPng(sigBytes);
    } else {
        embeddedSig = await pdfLibDoc.embedJpg(sigBytes);
    }

    // 6. Compute signature size in PDF points
    //    The img is rendered at its natural CSS size, then scaled by the user slider.
    const $img   = $('#sig-overlay-img');
    const sigWPdf = ($img.width()  * userSigScale) / pdfRenderScale;
    const sigHPdf = ($img.height() * userSigScale) / pdfRenderScale;

    // 7. Convert canvas position (top-left origin) → PDF position (bottom-left origin)
    const pdfX = posX / pdfRenderScale;
    const pdfY = pageH - (posY / pdfRenderScale) - sigHPdf;

    // 8. Draw the signature onto the page
    pdfPage.drawImage(embeddedSig, {
        x:      pdfX,
        y:      pdfY,
        width:  sigWPdf,
        height: sigHPdf,
    });

    // 8b. "Digitally signed by..." protocol label rendered below the signature image
    {
        const { StandardFonts, rgb } = PDFLib;
        const lblFont  = await pdfLibDoc.embedFont(StandardFonts.Helvetica);
        const lblBold  = await pdfLibDoc.embedFont(StandardFonts.HelveticaBold);
        const inkBlue  = rgb(0.05, 0.43, 0.99);
        const inkGray  = rgb(0.30, 0.30, 0.30);
        const now      = new Date();
        const dateFmt  = now.toLocaleDateString('en-GB',  { day: '2-digit', month: 'short', year: 'numeric' });
        const timeFmt  = now.toLocaleTimeString('en-GB',  { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        const lSize    = 7;
        const lh       = 9;
        const lx       = pdfX;
        let   ly       = pdfY - 4;  // just below the signature image bottom edge

        pdfPage.drawText('Digitally signed by: ' + (SIGNER_NAME || 'BMS User'), {
            x: lx, y: ly, size: lSize, font: lblBold, color: inkBlue,
        });
        pdfPage.drawText(dateFmt + '  ·  ' + timeFmt, {
            x: lx, y: ly - lh, size: lSize - 0.5, font: lblFont, color: inkGray,
        });
        if (signingReference) {
            pdfPage.drawText('Ref: ' + signingReference, {
                x: lx, y: ly - lh * 2, size: lSize - 0.5, font: lblFont, color: inkGray,
            });
        }
    }

    // 9. Append the Certificate of Completion page
    await appendCertificatePage(pdfLibDoc, {
        documentName:     selectedDocName,
        signerName:       SIGNER_NAME || 'BMS User',
        signerEmail:      SIGNER_EMAIL,
        signedAt:         new Date().toLocaleString(),
        signingReference: signingReference,
        originalHash:     originalHash,
        consentText:      CONSENT_TEXT
    });

    // 10. Serialise to bytes and send as a Blob (avoids base64 size inflation)
    const signedBytes = await pdfLibDoc.save();
    const blob = new Blob([signedBytes], { type: 'application/pdf' });

    const fd = new FormData();
    fd.append('original_document_id', selectedDocId);
    fd.append('signature_id',         selectedSigId);
    fd.append('signature_position',   'custom');
    fd.append('consent_text',         CONSENT_TEXT);
    fd.append('consent_accepted_at',  consentAt || new Date().toISOString());
    fd.append('viewed_at',            viewedAt  || '');
    fd.append('signing_reference',    signingReference);
    fd.append('signed_pdf_file',      blob, 'signed.pdf');

    // 11. Upload to server — the server recomputes both hashes authoritatively
    const saveResp = await fetch('<?= buildUrl("api/document/save_signed_pdf.php") ?>', {
        method:      'POST',
        body:        fd,
        credentials: 'include',
        headers:     { 'X-CSRF-Token': CSRF_TOKEN },
    });
    const saveData = await saveResp.json();
    if (!saveData.success) throw new Error(saveData.message || 'Failed to save signed document');

    return saveData;
}

// Re-check the integrity of a signed document against its recorded hash.
function verifySignedDocument(documentId) {
    Swal.fire({ title: 'Verifying…', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
    $.getJSON('<?= buildUrl("api/document/verify_signed_document.php") ?>', { document_id: documentId })
        .done(function (res) {
            if (!res.success) {
                Swal.fire('Verification', res.message || 'Could not verify the document.', 'error');
            } else if (res.verified === true) {
                Swal.fire('Verified ✓', res.message, 'success');
            } else if (res.verified === false) {
                Swal.fire('Tampered ✗', res.message, 'error');
            } else {
                Swal.fire('Verification', res.message, 'info');
            }
        })
        .fail(() => Swal.fire('Verification', 'Server error during verification.', 'error'));
}

// Quick Upload logic
$('#wizardQuickUploadForm').on('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    const btn = $('#btnWizardUpload');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Uploading...');
    
    $.ajax({
        url: '<?= buildUrl("api/document/quick_upload_document.php") ?>',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                selectedDocId = res.document_id;
                selectedDocName = res.document_name;
                selectedDocPath = res.file_path;
                $('#selected-doc-name').text(selectedDocName);
                changeStep(1);
            } else {
                Swal.fire('Error!', res.message, 'error');
            }
        },
        error: () => Swal.fire('Error!', 'Upload failed', 'error'),
        complete: () => btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> Upload and Select')
    });
});

function formatFileSize(bytes) {
    if (!bytes) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function escapeHtml(t) {
    return t ? $('<div>').text(t).html() : '';
}

// Build an absolute URL for a signature image, tolerating both '/uploads/..' and 'uploads/..'
function sigImageUrl(sig) {
    const p = (sig.thumbnail_path || sig.file_path || '').replace(/^\//, '');
    return p ? (APP_URL + '/' + p) : '';
}
</script>

<?php
includeFooter();
ob_end_flush();
?>
