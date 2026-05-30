<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('sms_templates');
includeHeader();
?>

    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-primary"><i class="bi bi-chat-left-dots"></i> SMS Templates</h2>
                            <p class="mb-0 text-muted">Manage system SMS notifications and text message templates</p>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-primary shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#templateModal" onclick="resetForm()">
                                <i class="bi bi-plus-circle me-1"></i> New Template
                            </button>
                            <button type="button" class="btn btn-outline-info shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#testSmsModal">
                                <i class="bi bi-send me-1"></i> Test SMS
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0" id="stat-total-templates">0</h4>
                            <p class="small mb-0">Total Templates</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-chat-quote" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0" id="stat-active-templates">0</h4>
                            <p class="small mb-0">Active Templates</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card shadow-sm border-0 mb-4 bg-white" style="border-radius: 12px;">
        <div class="card-body p-3">
            <div class="row g-3 align-items-center">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Template Type</label>
                    <select class="form-select bg-light border-0" id="typeFilter" style="border-radius: 8px;">
                        <option value="">All Types</option>
                        <option value="general">General</option>
                        <option value="loan">Loan Related</option>
                        <option value="payment">Payment/Collection</option>
                        <option value="security">Security/Auth</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1">Search</label>
                    <div class="input-group shadow-sm" style="border-radius: 8px; overflow: hidden; border: 1px solid #eee;">
                        <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="templates_search" class="form-control border-0 p-2" placeholder="Search templates...">
                    </div>
                </div>
                <div class="col-md-5 d-flex align-items-end gap-2">
                    <button type="button" class="btn btn-primary px-4 shadow-sm" onclick="refreshTable()" style="border-radius: 8px; height: 38px;">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="clearFilters()" style="border-radius: 8px; height: 38px;">
                        <i class="bi bi-x-circle"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#templatesTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Templates Table Card -->
    <div class="card shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-primary">SMS Template Library</h5>
                <span class="badge bg-success-soft text-success" id="stat-records-filtered">0 templates</span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="templatesTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th>S/NO</th>
                            <th>Template Name</th>
                            <th>Message Content</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Template Create/Edit Modal -->
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Create SMS Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="templateForm">
                <input type="hidden" id="template_id" name="id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="template_name" class="form-label">Template Name *</label>
                            <input type="text" class="form-control" id="template_name" name="template_name" required placeholder="e.g. Payment Reminder">
                        </div>
                        <div class="col-12">
                            <label for="template_type" class="form-label">Type</label>
                            <select class="form-select" id="template_type" name="template_type">
                                <option value="general">General</option>
                                <option value="loan">Loan Related</option>
                                <option value="payment">Payment/Collection</option>
                                <option value="security">Security/Auth</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="content" class="form-label">SMS Content *</label>
                            <textarea class="form-control" id="content" name="content" rows="4" maxlength="160" required placeholder="Dear {{customer_name}}, your payment is due..."></textarea>
                            <div class="d-flex justify-content-between mt-1">
                                <div class="form-text">Use {{customer_name}}, {{amount}}, etc.</div>
                                <div class="small text-muted"><span id="charCount">0</span>/160</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">Template is Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Send Test SMS Modal -->
<div class="modal fade" id="testSmsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Send Test SMS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="testSmsForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="test_template_id" class="form-label">Select Template</label>
                        <select class="form-select" id="test_template_id" name="template_id" required>
                            <option value="">Choose a template...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="test_phone" class="form-label">Recipient Phone *</label>
                        <input type="text" class="form-control" id="test_phone" name="phone" required placeholder="+254700000000">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="testSendBtn">Send Now</button>
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
    border: 1px solid #badbcc !important;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.bg-success-soft { background-color: #d1e7dd !important; }
#templatesTable thead th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border-bottom: none; }
.badge-active { background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
.badge-inactive { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
.btn-white:hover { background-color: #f8f9fa !important; }
</style>

<script>
$(document).ready(function() {
    logReportAction('Viewed SMS Template Library', 'User viewed the system SMS templates list');
    const table = $('#templatesTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '/api/get_sms_templates.php',
            data: function(d) {
                d.type = $('#typeFilter').val();
                d.search_term = $('#templates_search').val();
            },
            dataSrc: function(json) {
                // Safety check for stats
                if (json.stats) {
                    $('#stat-total-templates').text(json.stats.totalTemplates || 0);
                    $('#stat-active-templates').text(json.stats.activeTemplates || 0);
                }
                
                $('#stat-records-filtered').text((json.recordsFiltered || 0) + ' templates');
                
                // Update test sms template dropdown safely
                let options = '<option value="">Choose a template...</option>';
                if (json.data && Array.isArray(json.data)) {
                    json.data.forEach(t => {
                        options += `<option value="${t.id}">${t.template_name}</option>`;
                    });
                }
                $('#test_template_id').html(options);
                
                return json.data || [];
            }
        },
        columns: [
            { 
                data: null, 
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1 
            },
            { 
                data: 'template_name',
                render: data => `<div class="fw-bold text-dark">${escapeHtml(data)}</div>`
            },
            { 
                data: 'content',
                render: data => `<div class="text-muted small" style="max-width: 300px; white-space: normal;">${escapeHtml(data)}</div>`
            },
            { 
                data: 'template_type',
                render: data => `<span class="badge bg-light text-primary border-0 small text-uppercase" style="font-size: 0.65rem;">${data}</span>`
            },
            { 
                data: 'is_active',
                render: data => data == 1 
                    ? '<span class="badge badge-active">Active</span>' 
                    : '<span class="badge badge-inactive">Inactive</span>'
            },
            { 
                data: 'created_at',
                render: data => `<span class="text-muted small">${new Date(data).toLocaleDateString()}</span>`
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => `
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light border-0 shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick='editTemplate(${JSON.stringify(row)})'><i class="bi bi-pencil me-2 text-primary"></i> Edit Template</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteTemplate(${row.id})"><i class="bi bi-trash me-2"></i> Delete</a></li>
                        </ul>
                    </div>`
            }
        ],
        order: [[4, 'desc']],
        dom: '<"d-none" l>rt<"d-flex justify-content-between align-items-center p-4 border-top" ip>',
        language: {
            search: "",
            searchPlaceholder: "Search templates..."
        }
    });

    // Custom search & filter
    $('#templates_search').on('keyup', function() { 
        if ($(this).val().length > 2) logReportAction('Searched SMS Templates', 'User searched for: ' + $(this).val());
        table.ajax.reload(); 
    });
    $('#typeFilter').on('change', function() { 
        logReportAction('Filtered SMS Templates', 'User filtered by type: ' + ($(this).val() || 'All'));
        table.ajax.reload(); 
    });

    // Character counter
    $('#content').on('input', function() {
        $('#charCount').text($(this).val().length);
    });

    // Handle Form Submit
    $('#templateForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#saveBtn');
        btn.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: '/api/save_sms_template.php',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    const isEdit = $('#template_id').val();
                    logReportAction(isEdit ? 'Updated SMS Template' : 'Created SMS Template', 'User successfully ' + (isEdit ? 'updated' : 'created') + ' template: ' + $('#template_name').val());
                    $('#templateModal').modal('hide');
                    table.ajax.reload();

                    Swal.fire({
                        title: 'Success!',
                        text: response.message,
                        icon: 'success',
                        timer: 3000,
                        showConfirmButton: true,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: response.message,
                        icon: 'error',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#3085d6'
                    });
                }
            },
            complete: function() {
                btn.prop('disabled', false).text('Save Template');
            }
        });
    });

    // Handle Test SMS Submit
    $('#testSmsForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#testSendBtn');
        btn.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: '/api/test_sms_config.php',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    logReportAction('Sent Test SMS', 'User sent a test SMS using template ID: ' + $('#test_template_id').val() + ' to ' + $('#test_phone').val());
                    Swal.fire({
                        title: 'Sent!',
                        text: 'Test SMS sent successfully!',
                        icon: 'success',
                        timer: 3000,
                        showConfirmButton: true,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#3085d6'
                    });
                    $('#testSmsModal').modal('hide');
                } else {
                    Swal.fire({
                        title: 'Failed!',
                        text: response.message,
                        icon: 'error',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#3085d6'
                    });
                }
            },
            complete: function() {
                btn.prop('disabled', false).text('Send Now');
            }
        });
    });
});

function refreshTable() { $('#templatesTable').DataTable().ajax.reload(); }
function clearFilters() {
    $('#typeFilter').val('');
    $('#templates_search').val('');
    refreshTable();
}

function resetForm() {
    $('#modalTitle').text('Create SMS Template');
    $('#templateForm')[0].reset();
    $('#template_id').val('');
    $('#charCount').text('0');
}

function editTemplate(data) {
    $('#modalTitle').text('Edit SMS Template');
    $('#template_id').val(data.id);
    $('#template_name').val(data.template_name);
    $('#template_type').val(data.template_type);
    $('#content').val(data.content);
    $('#is_active').prop('checked', data.is_active == 1);
    $('#charCount').text(data.content.length);
    $('#templateModal').modal('show');
}

function deleteTemplate(id) {
    if (confirm('Are you sure you want to delete this template?')) {
        $.post('/api/delete_sms_template.php', {id: id}, function(response) {
            if (response.success) {
                logReportAction('Deleted SMS Template', 'User successfully deleted SMS template ID: ' + id);
                $('#templatesTable').DataTable().ajax.reload();
                Swal.fire({
                    title: 'Deleted!',
                    text: 'Template deleted successfully.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    title: 'Error!',
                    text: response.message,
                    icon: 'error',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#3085d6'
                });
            }
        });
    }
}

function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}
</script>

<?php
include("footer.php");
ob_end_flush();
?>
