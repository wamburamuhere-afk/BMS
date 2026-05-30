<?php
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

// Include the header
includeHeader();

// Enforce permission (if applicable)
if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('compliance');
}

// Categories for Compliance
$compliance_categories = [
    'KYC' => 'Know Your Customer documents (IDs, Photos)',
    'Regulatory' => 'Government and Licensing documents',
    'Tax' => 'Tax clearance and related filings',
    'Legal' => 'Contracts, Legal notices, and Agreements',
    'Audit' => 'Internal and External Audit reports',
    'Other' => 'Miscellaneous compliance documents'
];

?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-primary"><i class="bi bi-shield-check"></i> Compliance Management</h2>
                            <p class="mb-0 text-muted">Track regulatory requirements, KYC documents, and audit trails</p>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if (canCreate('compliance')): ?>
                            <button type="button" class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadComplianceModal">
                                <i class="bi bi-cloud-upload me-1"></i> Upload Compliance Doc
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-total-compliance">0</h4>
                            <p class="small mb-0 opacity-75 uppercase">Total Records</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-journal-text opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-expiring-soon">0</h4>
                            <p class="small mb-0 opacity-75 uppercase">Expiring Soon</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-exclamation-triangle opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-expired">0</h4>
                            <p class="small mb-0 opacity-75 uppercase">Expired Docs</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-x-circle opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold" id="stat-valid">0</h4>
                            <p class="small mb-0 opacity-75 uppercase">Valid & Active</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle opacity-50" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Table -->
        <div class="col-lg-9">
            <!-- Actions Bar -->
            <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                        <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                        <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#complianceTable').DataTable().page.len(this.value).draw();">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>

                    <button type="button" class="btn btn-white shadow-sm border px-3 py-1 d-flex align-items-center gap-2" onclick="exportCompliance()" style="border-radius: 8px; background: white;">
                        <i class="bi bi-file-earmark-excel text-success"></i> <span class="small fw-bold text-muted">Export List</span>
                    </button>
                    <button type="button" class="btn btn-white shadow-sm border px-3 py-1 d-flex align-items-center gap-2" onclick="printCompliance()" style="border-radius: 8px; background: white;">
                        <i class="bi bi-printer text-info"></i> <span class="small fw-bold text-muted">Print Report</span>
                    </button>
                </div>

                <div class="input-group input-group-sm shadow-sm" style="width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                    <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="compliance_search" class="form-control border-0 p-2" placeholder="Search compliance...">
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header border-0 bg-white py-3">
                    <h5 class="mb-0 fw-bold">Regulatory & Compliance Documents</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="complianceTable" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Document Title</th>
                                    <th>Category</th>
                                    <th>Reference No.</th>
                                    <th>Expiry Date</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <!-- AJAX Loaded -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Filters -->
        <div class="col-lg-3">
            <div class="card shadow-sm border-0 mb-4 text-white bg-dark">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-funnel"></i> Quick Filter</h6>
                    <div class="mb-3">
                        <label class="form-label small">Document Category</label>
                        <select class="form-select form-select-sm bg-dark text-white border-secondary" id="filter_category">
                            <option value="">All Categories</option>
                            <?php foreach ($compliance_categories as $cat => $desc): ?>
                                <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Review Status</label>
                        <select class="form-select form-select-sm bg-dark text-white border-secondary" id="filter_status">
                            <option value="">All Statuses</option>
                            <option value="Valid">Valid</option>
                            <option value="Expired">Expired</option>
                            <option value="Pending">Pending Review</option>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-sm w-100 shadow-sm" onclick="logReportAction('Filtered Compliance', 'User applied sidebar filters'); reloadComplianceTable()">Apply Filters</button>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Compliance Tips</h6>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle"></i> Ensure all KYC documents are updated every 12 months for high-risk profiles.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Compliance Modal -->
<div class="modal fade" id="uploadComplianceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cloud-upload"></i> Add Compliance Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="complianceForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_compliance">
                <input type="hidden" name="record_id" id="record_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Document Name</label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. Business License 2024" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select" required>
                                <?php foreach ($compliance_categories as $cat => $desc): ?>
                                    <option value="<?= $cat ?>"><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reference Number</label>
                            <input type="text" name="ref_no" class="form-control" placeholder="TRN-4455-XXX">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">File Selection</label>
                            <input type="file" name="doc_file" class="form-control">
                            <div class="form-text small">Accepted: PDF, JPG, PNG, DOCX (Max 10MB)</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Compliance Notes</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitCompliance">Save Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
#complianceTable thead th { border-top: none; font-weight: 600; }
</style>

<script>
$(document).ready(function() {
    // Audit Log for Page View
    logReportAction('Viewed Compliance Management', 'User viewed the compliance documents dashboard');

    const table = $('#complianceTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: `${APP_URL}/api/get_compliance.php`,
            data: d => {
                d.category = $('#filter_category').val();
                d.status = $('#filter_status').val();
            },
            dataSrc: json => {
                if (json.error) {
                    console.error("Compliance API Error:", json.error);
                    Swal.fire('Error', 'Failed to load compliance records: ' + json.error, 'error');
                    return [];
                }
                if (json.stats) {
                    $('#stat-total-compliance').text(json.stats.total || 0);
                    $('#stat-expiring-soon').text(json.stats.expiring || 0);
                    $('#stat-expired').text(json.stats.expired || 0);
                    $('#stat-valid').text(json.stats.valid || 0);
                }
                return json.data || [];
            }
        },
        columns: [
            { 
                data: null, 
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1 
            },
            { 
                data: 'title',
                render: (data, t, row) => {
                    const date = row.updated_at ? new Date(row.updated_at).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : 'Never';
                    return `<strong>${escapeHtml(data)}</strong><br><small class="text-muted"><i class="bi bi-clock"></i> Updated: ${date}</small>`;
                }
            },
            { data: 'category' },
            { data: 'ref_no' },
            { 
                data: 'expiry_date',
                render: data => data ? data : '<span class="text-muted">No Expiry</span>'
            },
            {
                data: 'status',
                render: data => {
                    let color = 'success';
                    if (data === 'Expired') color = 'danger';
                    if (data === 'Expiring Soon') color = 'warning';
                    if (data === 'Pending') color = 'info';
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-3">${data}</span>`;
                }
            },
            {
                data: null,
                className: 'text-end',
                render: row => {
                    // Build a clean, URL-encoded link: strip APP_URL's trailing
                    // slash + file_path's leading slash (no double slash), and
                    // encodeURI so spaces/special chars in the filename work.
                    const viewItem = row.file_path
                        ? `<li><a class="dropdown-item" href="${encodeURI(APP_URL.replace(/\\/$/, '') + '/' + String(row.file_path).replace(/^\\//, ''))}" target="_blank"><i class="bi bi-eye me-2"></i> View Document</a></li>`
                        : `<li><span class="dropdown-item text-muted" style="cursor:default"><i class="bi bi-eye-slash me-2"></i> No file attached</span></li>`;
                    return `
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            ${viewItem}
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="editCompliance(${row.id})"><i class="bi bi-pencil me-2"></i> Edit Record</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteCompliance(${row.id})"><i class="bi bi-trash me-2"></i> Remove</a></li>
                        </ul>
                    </div>`;
                }
            }
        ],
        order: [[3, 'desc']],
        dom: '<"d-none" l>rt<"d-flex justify-content-between align-items-center p-4 border-top" ip>',
        language: {
            search: "",
            searchPlaceholder: "Search compliance..."
        }
    });

    // Custom search handler
    $('#compliance_search').on('keyup', function() {
        if (this.value.length > 2) logReportAction('Searched Compliance', 'User searched for: ' + this.value);
        table.search(this.value).draw();
    });
});

function reloadComplianceTable() { $('#complianceTable').DataTable().ajax.reload(); }

function editCompliance(id) {
    $.getJSON(`${APP_URL}/api/get_compliance_record.php`, { id: id }, function(res) {
        if (res.success) {
            logReportAction('Initiated Compliance Edit', 'User opened edit modal for compliance record ID: ' + id + ' (' + res.data.title + ')');
            const r = res.data;
            $('#record_id').val(r.id);
            $('[name="title"]').val(r.title);
            $('[name="category"]').val(r.category);
            $('[name="ref_no"]').val(r.ref_no);
            $('[name="expiry_date"]').val(r.expiry_date);
            $('[name="notes"]').val(r.notes);
            
            $('#uploadComplianceModal .modal-title').html('<i class="bi bi-pencil"></i> Edit Compliance Record');
            $('#uploadComplianceModal').modal('show');
        }
    });
}

function deleteCompliance(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "Delete this compliance record permanently?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(`${APP_URL}/api/delete_compliance.php`, { id: id }, function(res) {
                if (res.success) {
                    logReportAction('Deleted Compliance Record', 'User successfully deleted compliance record ID: ' + id);
                    Swal.fire('Deleted!', 'Record has been removed.', 'success');
                    reloadComplianceTable();
                } else {
                    Swal.fire('Error!', res.message, 'error');
                }
            }, 'json');
        }
    });
}

$('#complianceForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btnSubmitCompliance');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
    
    const formData = new FormData(this);
    $.ajax({
        url: `${APP_URL}/api/save_compliance.php`,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                const isEdit = $('#record_id').val();
                logReportAction(isEdit ? 'Updated Compliance Record' : 'Created Compliance Record', 'User successfully ' + (isEdit ? 'updated' : 'created') + ' record: ' + $('[name="title"]').val());
                $('#uploadComplianceModal').modal('hide');
                reloadComplianceTable();
                $('#complianceForm')[0].reset();
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Compliance record saved successfully!',
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                Swal.fire('Error!', res.message, 'error');
            }
        },
        complete: () => btn.prop('disabled', false).html('Save Record')
    });
});

function exportCompliance() {
    const category = $('#filter_category').val();
    const status = $('#filter_status').val();
    let url = '/api/export_compliance.php?';
    
    if (category) url += 'category=' + encodeURIComponent(category) + '&';
    if (status) url += 'status=' + encodeURIComponent(status);
    
    logReportAction('Exported Compliance List', 'User exported the compliance records to Excel');
    window.location.href = url;
}

function printCompliance() {
    const category = $('#filter_category').val();
    const status = $('#filter_status').val();
    let url = '/api/print_compliance.php?';
    
    if (category) url += 'category=' + encodeURIComponent(category) + '&';
    if (status) url += 'status=' + encodeURIComponent(status);
    
    logReportAction('Printed Compliance Report', 'User generated a printable compliance report');
    window.open(url, '_blank', 'width=1024,height=768');
}
function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}
</script>

<?php 
// Include the footer
includeFooter();
// Flush
ob_end_flush();
?>
