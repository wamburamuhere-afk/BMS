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
                <h5 class="mb-3">Choose Your Signature</h5>
                <div class="row g-3" id="wizardSignatureGrid">
                    <!-- Loaded via AJAX -->
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
                                        I confirm this is my legally binding signature.
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
                        <p class="text-muted">Your document has been signed and saved in the history.</p>
                        <div class="mt-4 g-2">
                            <button class="btn btn-success btn-lg px-4" id="btnDownloadSigned">
                                <i class="bi bi-download"></i> Download PDF
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

    // Legal Check
    $('#wizardLegalCheck').on('change', function() {
        $('#btnFinalSign').prop('disabled', !this.checked);
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

    // Back button — hidden only on step 4, disabled only on step 1
    $('#btnBack').toggleClass('d-none', onLast).prop('disabled', currentStep === 1);

    // Next / Sign / hidden on step 4
    if (onLast) {
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
    if (currentStep === 2) valid = selectedSigId !== null;
    if (currentStep === 3) valid = $('#wizardLegalCheck').is(':checked');

    $('#btnNext').prop('disabled', !valid);
}

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
            html += `
                <div class="col-md-4">
                    <div class="card h-100 signature-card ${selectedSigId == sig.id ? 'active' : ''}"
                         onclick="selectSignature(${sig.id}, '${sig.thumbnail_path || sig.file_path}', this)">
                        <div class="card-body text-center p-3">
                            <img src="${sig.thumbnail_path || sig.file_path}" class="img-fluid" style="max-height: 80px;">
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
    $('#sig-overlay-img').attr('src', selectedSigPath);
    $('#draggable-signature').show();
    
    if (selectedDocPath && selectedDocPath.toLowerCase().endsWith('.pdf')) {
        $('#preview-loading').show().html('<div class="spinner-border" role="status"></div><p class="mt-2">Rendering PDF Preview...</p>');
        $('#sign-placement-area').css('visibility', 'hidden');
        
        // Use the download endpoint to get the PDF with correct headers
        const url = '<?= buildUrl("document_library") ?>?action=download&document_id=' + selectedDocId;
        
        console.log('Loading PDF from:', url);
        
        const loadingTask = pdfjsLib.getDocument({
            url: url,
            withCredentials: true // Pass session cookies
        });

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

function processFinalSign() {
    if (!$('#wizardLegalCheck').is(':checked')) {
        Swal.fire('Error!', 'Please agree to the legal terms before signing.', 'error');
        return;
    }

    changeStep(1); // Go to step 4
    
    const scaleVal = $('#sig-scale').val();
    
    $.post('<?= buildUrl("api/document/apply_signature.php") ?>', {
        document_id: selectedDocId,
        signature_id: selectedSigId,
        signature_position: 'custom',
        scale: scaleVal,
        posX: posX,
        posY: posY,
        page: pageNum,
        canvasW: canvas.width,
        canvasH: canvas.height
    }, function(res) {
        if (res.success) {
            $('#signing-progress').addClass('d-none');
            $('#signing-success').removeClass('d-none');
            
            $('#btnDownloadSigned').off('click').on('click', function() {
                window.location.href = '<?= buildUrl("document_library") ?>?action=download&document_id=' + selectedDocId;
            });
        } else {
            Swal.fire('Error!', res.message, 'error');
            changeStep(-1);
        }
    }, 'json').fail(() => {
        Swal.fire('Error!', 'Server error while signing', 'error');
        changeStep(-1);
    });
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
</script>

<?php
includeFooter();
ob_end_flush();
?>
