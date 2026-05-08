<?php
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

// Include the header
includeHeader();

// Enforce permission (if applicable)
if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('document_templates');
}

// Handle template actions (Delete/Download/Preview logic)
$action = $_GET['action'] ?? '';
$template_id = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;

// (Keep original logic but adapt to professional UI)
// Note: In a real system, these would call API endpoints.
// For now, we'll keep the logic in-page but use the new UI.

$categories = $pdo->query("SELECT * FROM template_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-file-earmark-richtext"></i> Document Templates</h2>
                    <p class="text-muted mb-0">Create and manage standardized document templates for your business</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if (canCreate('documents')): ?>
                    <button type="button" class="btn btn-primary shadow-sm" onclick="openUploadModal()">
                        <i class="bi bi-cloud-upload"></i> Upload Template
                    </button>
                    <button type="button" class="btn btn-primary shadow-sm" onclick="openCreateModal()">
                        <i class="bi bi-plus-circle"></i> Create HTML Template
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-templates">0</h4>
                            <p class="mb-0">Total Templates</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-file-earmark-text" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-active-templates">0</h4>
                            <p class="mb-0">Active Templates</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-usage">0</h4>
                            <p class="mb-0">Total Documents Generated</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-play-circle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-categories">0</h4>
                            <p class="mb-0">Categories</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-tags" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
        </div>
        <div class="card-body">
            <form id="filterForm">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Category</label>
                        <select class="form-select" id="categoryFilter">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Template Type</label>
                        <select class="form-select" id="typeFilter">
                            <option value="">All Types</option>
                            <option value="uploaded">Uploaded File</option>
                            <option value="html">HTML Builder</option>
                            <option value="built_in">System Default</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Statuses</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-12 d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                            <i class="bi bi-arrow-clockwise"></i> Clear
                        </button>
                        <button type="button" class="btn btn-primary" onclick="applyFilters()">
                            <i class="bi bi-filter"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#templatesTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        <div class="input-group input-group-sm shadow-sm" style="width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
            <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
            <input type="text" id="templates_search" class="form-control border-0 p-2" placeholder="Search templates...">
        </div>
    </div>

    <!-- Templates Table Card -->
    <div class="card">
        <div class="card-header custom-table-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Templates List</h5>
                <span class="badge bg-light text-dark" id="stat-records-filtered">0 templates</span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="templatesTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th>S/NO</th>
                            <th>Template Name</th>
                            <th>Category</th>
                            <th>Type</th>
                            <th>Usage</th>
                            <th>Created By</th>
                            <th>Created At</th>
                            <th>Status</th>
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

<!-- Template Modal (Combined Upload & Edit) -->
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="templateModalTitle"><i class="bi bi-file-earmark-richtext"></i> Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="templateForm" enctype="multipart/form-data">
                <input type="hidden" name="template_id" id="form_template_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Template Title</label>
                        <input type="text" class="form-control" name="template_name" id="form_template_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category_id" id="form_category_id"  required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="file_type_container">
                        <label class="form-label">Template Type</label>
                        <select class="form-select" name="template_type" id="form_template_type">
                            <option value="uploaded">Uploaded File</option>
                            <option value="html">HTML Builder</option>
                        </select>
                    </div>
                    <div class="mb-3" id="file_input_container">
                        <label class="form-label">File Selection</label>
                        <input type="file" class="form-control" name="template_file" id="form_template_file">
                        <div class="form-text">Choose a new file to replace the existing one (optional if editing).</div>
                    </div>
                    <div class="mb-3 d-none" id="html_content_container">
                        <label class="form-label">HTML/Text Content</label>
                        <textarea class="form-control" name="description" id="form_description" rows="5" placeholder="Enter template content or description..."></textarea>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="form_is_active" checked value="1">
                            <label class="form-check-label">Available for use</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSaveTemplate">Save Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts Section -->
<!-- DataTables JS is handled by footer.php -->

<script>
$(document).ready(function() {
    // Audit Log for Page View
    logReportAction('Viewed Document Templates', 'User viewed the document templates library');

    const userPermissions = {
        canEdit: <?= canEdit('documents') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('documents') ? 'true' : 'false' ?>
    };

    const table = $('#templatesTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/get_templates.php`,
            data: d => {
                d.category_id = $('#categoryFilter').val();
                d.type = $('#typeFilter').val();
                d.status = $('#statusFilter').val();
            },
            dataSrc: json => {
                if (json.error) {
                    console.error("API Error:", json.error);
                    Swal.fire('Error', 'Failed to load templates: ' + json.error, 'error');
                    return [];
                }
                if (!json.stats) {
                    console.warn("API did not return stats");
                    return json.data || [];
                }
                const stats = json.stats;
                $('#stat-total-templates').text(stats.totalTemplates || 0);
                $('#stat-active-templates').text(stats.activeTemplates || 0);
                $('#stat-total-usage').text(stats.totalUsage || 0);
                $('#stat-categories').text(stats.categoriesCount || 0);
                $('#stat-records-filtered').text((json.recordsFiltered || 0) + ' templates');
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
                render: (data, t, row) => `<strong>${escapeHtml(data)}</strong><br><small class="text-muted">${escapeHtml(row.description || '')}</small>`
            },
            { 
                data: 'category_name',
                render: data => data ? escapeHtml(data) : '<span class="text-muted">Uncategorized</span>'
            },
            { 
                data: 'file_type',
                render: data => {
                    if (!data) return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2">N/A</span>';
                    let type = data.toLowerCase();
                    let color = type === 'pdf' ? 'danger' : (type === 'docx' || type === 'doc' ? 'primary' : 'info');
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-2 text-uppercase"><i class="bi bi-file-earmark-text"></i> ${data}</span>`;
                }
            },
            { data: 'usage_count', className: 'text-center' },
            { 
                data: 'created_by_name',
                render: data => data ? escapeHtml(data) : '<span class="text-muted">System</span>'
            },
            { 
                data: 'created_at',
                render: data => data ? new Date(data).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : 'N/A'
            },
            { 
                data: 'is_active',
                render: data => data == 1 ? 
                    '<span class="badge bg-success-subtle text-success border border-success-subtle px-3"><i class="bi bi-check-circle"></i> Active</span>' : 
                    '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3"><i class="bi bi-x-circle"></i> Inactive</span>'
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => {
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="document_templates.php?action=generate&template_id=${row.id}"><i class="bi bi-play-circle"></i> Generate Doc</a></li>
                            <li><a class="dropdown-item" href="${APP_URL}/${row.file_path}" target="_blank"><i class="bi bi-eye"></i> Preview / View</a></li>
                            <li><a class="dropdown-item" href="#" onclick="editTemplate(${row.id})"><i class="bi bi-pencil"></i> Edit Details</a></li>`;
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${row.id})"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        order: [[5, 'desc']],
        dom: '<"d-none" l>rt<"d-flex justify-content-between align-items-center p-4 border-top" ip>',
        language: {
            search: "",
            searchPlaceholder: "Search templates..."
        }
    });

    // Custom search handler
    $('#templates_search').on('keyup', function() {
        if (this.value.length > 2) logReportAction('Searched Document Templates', 'User searched for: ' + this.value);
        table.search(this.value).draw();
    });
    
    $('#categoryFilter, #typeFilter, #statusFilter').on('change', function() {
        logReportAction('Filtered Document Templates', 'User applied filters: Category=' + ($('#categoryFilter').val() || 'All') + ', Type=' + ($('#typeFilter').val() || 'All'));
        table.ajax.reload();
    });
});

function applyFilters() { $('#templatesTable').DataTable().ajax.reload(); }
function clearFilters() {
    $('#filterForm')[0].reset();
    $('#templatesTable').DataTable().ajax.reload();
}

function openUploadModal() {
    $('#templateForm')[0].reset();
    $('#form_template_id').val('');
    $('#templateModalTitle').html('<i class="bi bi-cloud-upload"></i> Upload Template File');
    $('#file_type_container').addClass('d-none');
    $('#file_input_container').removeClass('d-none');
    $('#html_content_container').addClass('d-none');
    $('#templateModal').modal('show');
}

function openCreateModal() {
    $('#templateForm')[0].reset();
    $('#form_template_id').val('');
    $('#templateModalTitle').html('<i class="bi bi-plus-circle"></i> Create HTML Template');
    $('#file_type_container').addClass('d-none');
    $('#form_template_type').val('html');
    $('#file_input_container').addClass('d-none');
    $('#html_content_container').removeClass('d-none');
    $('#templateModal').modal('show');
}

function editTemplate(id) {
    $.getJSON(`${APP_URL}/api/get_document_template.php`, { id: id }, function(res) {
        if (res.success) {
            logReportAction('Initiated Template Edit', 'User opened edit modal for template ID: ' + id + ' (' + res.data.template_name + ')');
            const t = res.data;
            $('#form_template_id').val(t.id);
            $('#form_template_name').val(t.template_name);
            $('#form_category_id').val(t.category_id);
            $('#form_description').val(t.description);
            $('#form_is_active').prop('checked', t.is_active == 1);
            
            $('#templateModalTitle').html('<i class="bi bi-pencil"></i> Edit Template');
            $('#file_type_container').removeClass('d-none');
            $('#file_input_container').removeClass('d-none');
            $('#html_content_container').removeClass('d-none');
            
            $('#templateModal').modal('show');
        } else {
            Swal.fire('Error!', res.message, 'error');
        }
    });
}

$('#templateForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const btn = $('#btnSaveTemplate');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');

    $.ajax({
        url: `${APP_URL}/api/save_document_template.php`,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                const isEdit = $('#form_template_id').val();
                logReportAction(isEdit ? 'Updated Document Template' : 'Created Document Template', 'User successfully ' + (isEdit ? 'updated' : 'created') + ' template: ' + $('#form_template_name').val());
                $('#templateModal').modal('hide');
                $('#templatesTable').DataTable().ajax.reload();
                Swal.fire({
                    icon: 'success',
                    title: 'Saved!',
                    text: 'Template saved successfully!',
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                Swal.fire('Error!', res.message, 'error');
            }
        },
        complete: () => btn.prop('disabled', false).html('Save Template')
    });
});

function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You are about to delete this template permanently!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(`${APP_URL}/api/delete_document_template.php`, { id: id }, function(res) {
                if (res.success) {
                    logReportAction('Deleted Document Template', 'User permanently deleted template ID: ' + id);
                    $('#templatesTable').DataTable().ajax.reload();
                    Swal.fire('Deleted!', 'Template has been deleted.', 'success');
                } else {
                    Swal.fire('Error!', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.custom-table-header { border-bottom: 2px solid #e9ecef; }
#templatesTable thead th { font-weight: 600; border-bottom: none; }
</style>

<?php
// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>