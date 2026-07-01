<?php
ob_start();
$page_title = 'Import Leads';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('crm_import');
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View CRM import', 'User opened CRM Lead Import page');

$campaigns = $pdo->query("SELECT campaign_id, campaign_name FROM marketing_campaigns WHERE is_deleted = 0 ORDER BY campaign_name")->fetchAll(PDO::FETCH_ASSOC);
$users     = $pdo->query("SELECT user_id, COALESCE(NULLIF(TRIM(CONCAT_WS(' ', first_name, last_name)),''), username) AS name FROM users WHERE is_active = 1 ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.step-circle { width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0; }
.step-active  { background:#0d6efd;color:#fff; }
.step-done    { background:#052c65;color:#fff; }
.step-pending { background:#e9ecef;color:#6c757d; }
.import-zone  { border:2px dashed #b6ccfe;border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:background .2s; }
.import-zone:hover { background:#f0f5ff; }
</style>

<div class="container-fluid mt-4 mb-5">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="<?= getUrl('crm/leads') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Leads</a>
        <h4 class="mb-0"><i class="bi bi-file-earmark-arrow-up text-primary me-2"></i>Import Leads</h4>
    </div>

    <!-- Step indicator -->
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <div class="d-flex align-items-center gap-2">
            <div class="step-circle step-active" id="step1-circle">1</div>
            <span class="fw-semibold text-primary" id="step1-label">Upload File</span>
        </div>
        <div class="text-muted">→</div>
        <div class="d-flex align-items-center gap-2">
            <div class="step-circle step-pending" id="step2-circle">2</div>
            <span class="text-muted" id="step2-label">Map Columns</span>
        </div>
        <div class="text-muted">→</div>
        <div class="d-flex align-items-center gap-2">
            <div class="step-circle step-pending" id="step3-circle">3</div>
            <span class="text-muted" id="step3-label">Preview &amp; Import</span>
        </div>
    </div>

    <!-- Step 1: Upload -->
    <div id="stepUpload">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <input type="file" id="csvFile" accept=".csv" class="d-none">
                <div class="import-zone" id="dropZone">
                    <i class="bi bi-filetype-csv display-4 text-primary mb-3 d-block"></i>
                    <div class="fw-bold mb-1">Click to upload a CSV file</div>
                    <div class="text-muted small">Max 5 MB. First row must be the header.</div>
                </div>
                <div class="mt-4 row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Assign to Campaign (optional)</label>
                        <select class="form-select select2-static" id="import_campaign_id">
                            <option value="">— None —</option>
                            <?php foreach ($campaigns as $c): ?>
                            <option value="<?= $c['campaign_id'] ?>"><?= safe_output($c['campaign_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Default Assigned To (optional)</label>
                        <select class="form-select select2-static" id="import_assigned_to">
                            <option value="">— None —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>"><?= safe_output($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-primary" id="uploadBtn" disabled onclick="loadHeaders()">
                        <i class="bi bi-arrow-right me-1"></i>Next: Map Columns
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 2: Map columns -->
    <div id="stepMap" class="d-none">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="fw-bold mb-3">Map your CSV columns to lead fields</h6>
                <div id="colMapForm" class="row g-3"></div>
                <div class="mt-4">
                    <button class="btn btn-secondary me-2" onclick="goStep(1)"><i class="bi bi-arrow-left me-1"></i>Back</button>
                    <button class="btn btn-primary" onclick="previewImport()"><i class="bi bi-eye me-1"></i>Preview Import</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 3: Preview & confirm -->
    <div id="stepPreview" class="d-none">
        <div id="previewContent"></div>
    </div>
</div>

<script>
const IMPORT_URL = '<?= buildUrl('api/crm/import_leads.php') ?>';
const CSRF       = '<?= csrf_token() ?>';
let csvHeaders   = [];
let csvFile      = null;

// Select2 init
$(function () {
    $('.select2-static').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: '— Select —' });

    // dropZone click opens file picker via JS — NOT inline onclick — to avoid
    // the bubbling recursion (click → programmatic click on child → bubbles to parent → loop)
    $('#dropZone').on('click', function () { $('#csvFile').click(); });

    function showFileChosen(file) {
        $('#uploadBtn').prop('disabled', false);
        // Update visual WITHOUT recreating the input inside dropZone (would reintroduce the bug)
        $('#dropZone').html(
            '<i class="bi bi-file-earmark-check display-4 text-primary mb-3 d-block"></i>' +
            '<div class="fw-bold mb-1">' + file.name + '</div>' +
            '<div class="text-muted small">' + (file.size / 1024).toFixed(1) + ' KB &middot; Click to change</div>'
        );
    }

    $('#csvFile').on('change', function () {
        const file = this.files[0];
        if (file) { csvFile = file; showFileChosen(file); }
    });

    $('#dropZone').on('dragover', e => { e.preventDefault(); $('#dropZone').css('background', '#f0f5ff'); });
    $('#dropZone').on('dragleave', () => { $('#dropZone').css('background', ''); });
    $('#dropZone').on('drop', function (e) {
        e.preventDefault();
        $('#dropZone').css('background', '');
        const file = e.originalEvent.dataTransfer.files[0];
        if (file) { csvFile = file; showFileChosen(file); }
    });
});

function goStep(n) {
    $('#stepUpload,#stepMap,#stepPreview').addClass('d-none');
    if (n === 1) { $('#stepUpload').removeClass('d-none'); markStep(1); }
    else if (n === 2) { $('#stepMap').removeClass('d-none');   markStep(2); }
    else if (n === 3) { $('#stepPreview').removeClass('d-none'); markStep(3); }
}

function markStep(active) {
    [1,2,3].forEach(n => {
        const circle = $(`#step${n}-circle`), label = $(`#step${n}-label`);
        if (n < active) { circle.removeClass('step-pending step-active').addClass('step-done'); label.removeClass('text-muted').addClass('text-primary'); }
        else if (n === active) { circle.removeClass('step-pending step-done').addClass('step-active'); label.removeClass('text-muted').addClass('text-primary fw-semibold'); }
        else { circle.removeClass('step-active step-done').addClass('step-pending'); label.addClass('text-muted').removeClass('text-primary fw-semibold'); }
    });
}

function loadHeaders() {
    if (!csvFile) return;
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('csv_file', csvFile);
    fd.append('mode', 'headers');
    $('#uploadBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Reading...');
    $.ajax({ url: IMPORT_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
        success: res => {
            try {
                if (!res.success) { Swal.fire({ icon:'error', title:'Error', text: res.message }); return; }
                csvHeaders = res.headers;
                buildMapping();
                goStep(2);
            } catch(e) {
                console.error('Import mapping error:', e);
                Swal.fire({ icon:'error', title:'Error', text: 'Failed to read CSV headers. Ensure the first row is a header row with no empty columns.' });
            }
        },
        error: () => Swal.fire({ icon:'error', title:'Error', text:'Server error.' }),
        complete: () => $('#uploadBtn').prop('disabled', false).html('<i class="bi bi-arrow-right me-1"></i>Next: Map Columns')
    });
}

function buildMapping() {
    const fields = [
        ['col_first_name', 'First Name *'], ['col_last_name', 'Last Name'],
        ['col_company', 'Company Name'], ['col_email', 'Email'],
        ['col_phone', 'Phone'], ['col_source', 'Lead Source'],
        ['col_value', 'Lead Value'], ['col_close_date', 'Expected Close Date'],
        ['col_stage', 'Pipeline Stage'], ['col_notes', 'Notes'],
    ];
    let html = '';
    fields.forEach(([name, label]) => {
        html += `<div class="col-md-4 col-6">
            <label class="form-label small fw-bold">${label}</label>
            <select class="form-select form-select-sm" name="${name}" id="${name}">
                <option value="-1">— Not mapped —</option>
                ${csvHeaders.map((h, i) => `<option value="${i}">${h}</option>`).join('')}
            </select>
        </div>`;
    });
    // Auto-match by name
    $('#colMapForm').html(html);
    const autoMatch = { col_first_name:['first_name','firstname','first name','name'],
        col_last_name:['last_name','lastname','surname'], col_company:['company','company_name'],
        col_email:['email','e-mail'], col_phone:['phone','tel','telephone'],
        col_source:['source','lead_source'], col_value:['value','lead_value','amount'],
        col_close_date:['close_date','expected_close','close'], col_notes:['notes','note','remarks'] };
    Object.entries(autoMatch).forEach(([field, keywords]) => {
        csvHeaders.forEach((h, i) => {
            if (h != null && keywords.some(k => h.toLowerCase().includes(k)))
                $(`#${field}`).val(i);
        });
    });
}

function collectMapping() {
    const fields = ['col_first_name','col_last_name','col_company','col_email','col_phone',
        'col_source','col_value','col_close_date','col_stage','col_notes'];
    const fd = new FormData();
    fd.append('_csrf', CSRF);
    fd.append('csv_file', csvFile);
    fd.append('campaign_id', $('#import_campaign_id').val() || '');
    fd.append('assigned_to', $('#import_assigned_to').val() || '');
    fields.forEach(f => fd.append(f, $(`#${f}`).val()));
    return fd;
}

function previewImport() {
    const fd = collectMapping();
    fd.set('mode', 'preview');
    $.ajax({ url: IMPORT_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
        success: res => {
            if (!res.success) { Swal.fire({ icon:'error', title:'Error', text: res.message }); return; }
            const r = res.results, p = res.preview || [];
            let html = `<div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Import Preview</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                            <div class="fs-4 fw-bold text-primary">${r.imported}</div><div class="small text-muted">Will Import</div>
                        </div></div>
                        <div class="col-md-4"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                            <div class="fs-4 fw-bold text-muted">${r.skipped}</div><div class="small text-muted">Duplicates / Skipped</div>
                        </div></div>
                        <div class="col-md-4"><div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                            <div class="fs-4 fw-bold text-danger">${r.errors.length}</div><div class="small text-muted">Errors</div>
                        </div></div>
                    </div>`;
            if (p.length) {
                html += `<h6 class="fw-bold mb-2 small text-muted">Sample rows that will be imported (first ${p.length}):</h6>
                <div class="table-responsive"><table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th>Row</th><th>Name</th><th>Company</th><th>Email</th><th>Source</th><th>Value</th></tr></thead><tbody>`;
                p.forEach(row => {
                    html += `<tr><td>${row.row}</td><td>${row.first_name||''} ${row.last_name||''}</td><td>${row.company||''}</td><td>${row.email||''}</td><td>${row.source||''}</td><td>${row.value||0}</td></tr>`;
                });
                html += '</tbody></table></div>';
            }
            if (r.errors.length) {
                html += `<div class="alert alert-danger mt-2"><strong>Errors:</strong><ul class="mb-0 mt-1">` + r.errors.map(e => `<li>${e}</li>`).join('') + '</ul></div>';
            }
            html += `</div></div>
            <div class="d-flex gap-2">
                <button class="btn btn-secondary" onclick="goStep(2)"><i class="bi bi-arrow-left me-1"></i>Back</button>
                ${r.imported > 0 ? `<button class="btn btn-primary" onclick="runImport()"><i class="bi bi-upload me-1"></i>Import ${r.imported} Lead${r.imported!==1?'s':''}</button>` : ''}
            </div>`;
            $('#previewContent').html(html);
            goStep(3);
        },
        error: () => Swal.fire({ icon:'error', title:'Error', text:'Server error.' })
    });
}

function runImport() {
    const fd = collectMapping();
    fd.set('mode', 'import');
    $('button').prop('disabled', true);
    $.ajax({ url: IMPORT_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
        success: res => {
            if (!res.success) { Swal.fire({ icon:'error', title:'Error', text: res.message }); return; }
            const r = res.results;
            Swal.fire({ icon:'success', title:'Import Complete!',
                html: `<strong>${r.imported}</strong> lead(s) imported.<br>${r.skipped} duplicates skipped.<br>${r.errors.length} errors.`,
                confirmButtonText: 'View Leads'
            }).then(() => { window.location.href = '<?= getUrl('crm/leads') ?>'; });
        },
        error: () => Swal.fire({ icon:'error', title:'Error', text:'Server error.' }),
        complete: () => $('button').prop('disabled', false)
    });
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
