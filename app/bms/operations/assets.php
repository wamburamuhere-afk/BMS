<?php
// app/bms/operations/assets.php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('assets');

// Include the header
includeHeader();

?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-dark"><i class="bi bi-box-seam text-primary"></i> Assets Management</h2>
                            <p class="mb-0 text-muted">Track and manage business physical assets, maintenance, and disposal</p>
                        </div>
                        <div>
                            <?php if (canCreate('assets')): ?>
                            <button type="button" class="btn btn-primary shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#assetModal">
                                <i class="bi bi-plus-circle me-1"></i> Add New Asset
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
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-assets">0</h4>
                            <p class="mb-0">Total Assets</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0" id="stat-total-cost">0.00</h4>
                            <p class="mb-0">Total Cost Value</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-currency-dollar" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0" id="stat-maintenance-count">0</h4>
                            <p class="mb-0">In Maintenance</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-tools" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0" id="stat-categories-count">0</h4>
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
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Category</label>
                    <select class="form-select" id="categoryFilter">
                        <option value="">All Categories</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="disposed">Disposed</option>
                        <option value="written_off">Written Off</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="button" class="btn btn-primary w-100" onclick="refreshTable()">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn btn-outline-secondary ms-2" onclick="refreshTable()">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printAssets()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportAssets()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-excel text-success me-1"></i> Excel
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#assetsTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>

            <div class="input-group input-group-sm shadow-sm" style="width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control border-0 p-2" id="searchFilter" placeholder="Search assets..." onkeyup="$('#assetsTable').DataTable().ajax.reload()">
            </div>
        </div>
    </div>
    <!-- Assets Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold">Asset Records</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="assetsTable" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4" style="width: 50px;">S/NO</th>
                            <th>Asset Details</th>
                            <th>Code</th>
                            <th>Category</th>
                            <th>Purchase Date</th>
                            <th>Cost</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Asset Modal -->
<div class="modal fade" id="assetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title" id="assetModalLabel">
                    <i class="bi bi-plus-circle me-2"></i>Add New Asset
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="assetForm">
                <input type="hidden" name="asset_id" id="asset_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Asset Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="asset_name" required placeholder="e.g. MacBook Pro M3">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Asset Code</label>
                            <input type="text" class="form-control" name="asset_code" placeholder="e.g. AST-001">
                        </div>
                        <div class="col-md-6" id="categoryFieldGroup">
                            <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <div id="categorySelectDiv">
                                <select class="form-select shadow-sm" id="categorySelect" style="border-radius: 8px;">
                                    <option value="">Select Category</option>
                                    <option value="Office Equipment">Office Equipment</option>
                                    <option value="Furniture">Furniture</option>
                                    <option value="Vehicles">Vehicles</option>
                                    <option value="Computers">Computers</option>
                                    <option value="Property">Property</option>
                                    <option value="Machinery">Machinery</option>
                                    <option value="Other">Other...</option>
                                </select>
                            </div>
                            <div id="categoryInputDiv" class="d-none">
                                <div class="input-group shadow-sm">
                                    <input type="text" class="form-control" id="categoryInput" placeholder="Enter custom category" style="border-top-left-radius: 8px; border-bottom-left-radius: 8px;">
                                    <button class="btn btn-outline-secondary" type="button" onclick="toggleCategoryField('select')" title="Back to list" style="border-top-right-radius: 8px; border-bottom-right-radius: 8px;">
                                        <i class="bi bi-list-ul"></i>
                                    </button>
                                </div>
                            </div>
                            <input type="hidden" name="category" id="categoryHidden" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Location</label>
                            <input type="text" class="form-control" name="location" placeholder="e.g. Headquarters - Room 204">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Purchase Date</label>
                            <input type="date" class="form-control" name="purchase_date" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cost (TZS) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text font-monospace">TZS</span>
                                <input type="number" class="form-control" name="cost" step="0.01" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status" required>
                                <option value="active">Active</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="disposed">Disposed</option>
                                <option value="written_off">Written Off</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Additional details, serial numbers, etc."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i> <span id="btnSaveText">Save Asset</span>
                    </button>
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
    
    .table thead th { font-weight: 600; letter-spacing: 0.5px; border-top: 0; }
    .table tbody td { padding: 1rem 0.75rem; }
    
    .status-badge {
        padding: 0.35em 0.65em;
        font-size: 0.75em;
        font-weight: 600;
        border-radius: 50rem;
    }

    /* 📱 MOBILE RESPONSIVE CARD VIEW */
    @media (max-width: 767px) {
        .container-fluid { padding-left: 10px; padding-right: 10px; }
        
        /* Sticky Actions Bar for Mobile */
        .d-flex.justify-content-between.align-items-center.mb-4 {
            position: sticky;
            top: 60px;
            z-index: 100;
            background: #f8f9fa;
            padding: 10px 0;
            flex-direction: column !important;
            gap: 10px;
            align-items: stretch !important;
        }

        #assetsTable, #assetsTable thead, #assetsTable tbody, #assetsTable th, #assetsTable td, #assetsTable tr { 
            display: block; 
        }
        
        #assetsTable thead { display: none; } /* Hide headers on mobile */
        
        #assetsTable tr {
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 10px;
            position: relative;
        }

        #assetsTable td {
            border: none;
            position: relative;
            padding-left: 50% !important;
            text-align: right !important;
            min-height: 40px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            border-bottom: 1px solid #f8f9fa;
        }

        #assetsTable td:last-child { border-bottom: none; }

        #assetsTable td:before {
            content: attr(data-label);
            position: absolute;
            left: 15px;
            width: 45%;
            padding-right: 10px;
            white-space: nowrap;
            text-align: left;
            font-weight: 700;
            color: #6c757d;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        /* Specialized styling for key fields in card */
        #assetsTable td[data-label="Asset Details"] {
            padding-left: 15px !important;
            text-align: left !important;
            display: block;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        #assetsTable td[data-label="Asset Details"]:before { display: none; }
        
        #assetsTable td[data-label="Actions"] {
            justify-content: center;
            padding-left: 15px !important;
            background: #fff;
        }
        #assetsTable td[data-label="Actions"]:before { display: none; }

        .custom-stat-card { margin-bottom: 10px; }
    }
</style>

<!-- Scripts -->
<script>
$(document).ready(function() {
    logReportAction('Viewed Assets List', 'User viewed the asset management page');
    const userPermissions = {
        canEdit: <?= canEdit('assets') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('assets') ? 'true' : 'false' ?>
    };

    const table = $('#assetsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/operations/get_assets.php',
            data: function(d) {
                d.category = $('#categoryFilter').val();
                d.status = $('#statusFilter').val();
                d.search_term = $('#searchFilter').val();
            },
            dataSrc: function(json) {
                $('#stat-total-assets').text(json.stats.total_count);
                $('#stat-total-cost').text(formatCurrency(json.stats.total_cost));
                $('#stat-maintenance-count').text(json.stats.maintenance_count);
                $('#stat-categories-count').text(json.stats.categories_count);
                
                // Populate category filter if empty
                if ($('#categoryFilter option').length === 1 && json.categories) {
                    json.categories.forEach(cat => {
                        $('#categoryFilter').append(`<option value="${cat}">${cat}</option>`);
                    });
                }
                
                return json.data;
            }
        },
        columns: [
            { 
                data: null, 
                title: 'S/NO', 
                orderable: false, 
                searchable: false,
                render: function (data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                },
                className: 'ps-4',
                createdCell: (td) => $(td).attr('data-label', 'S/NO')
            },
            { 
                data: 'asset_name',
                render: function(data, type, row) {
                    return `<div>
                        <div class="fw-bold text-dark">${data}</div>
                        <div class="small text-muted">${row.description ? row.description.substring(0, 50) + '...' : ''}</div>
                    </div>`;
                },
                createdCell: (td) => $(td).attr('data-label', 'Asset Details')
            },
            { 
                data: 'asset_code', 
                render: data => `<span class="badge bg-light text-dark border">${data || 'N/A'}</span>`,
                createdCell: (td) => $(td).attr('data-label', 'Code')
            },
            { 
                data: 'category',
                createdCell: (td) => $(td).attr('data-label', 'Category')
            },
            { 
                data: 'purchase_date', 
                render: data => data ? new Date(data).toLocaleDateString() : 'N/A',
                createdCell: (td) => $(td).attr('data-label', 'Purchase Date')
            },
            { 
                data: 'cost', 
                render: data => `<strong>${formatCurrency(data)}</strong>`,
                createdCell: (td) => $(td).attr('data-label', 'Cost')
            },
            { 
                data: 'status',
                render: function(data) {
                    let cls = 'secondary';
                    if (data === 'active') cls = 'success';
                    if (data === 'maintenance') cls = 'warning';
                    if (data === 'disposed' || data === 'written_off') cls = 'danger';
                    return `<span class="status-badge bg-${cls}">${data.charAt(0).toUpperCase() + data.slice(1)}</span>`;
                },
                createdCell: (td) => $(td).attr('data-label', 'Status')
            },
            { 
                data: 'location', 
                render: data => data || '<span class="text-muted small">Not specified</span>',
                createdCell: (td) => $(td).attr('data-label', 'Location')
            },
            {
                data: null,
                orderable: false,
                className: 'text-end pe-4',
                createdCell: (td) => $(td).attr('data-label', 'Actions'),
                render: function(data, type, row) {
                    let html = `
                        <div class="dropdown action-dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">`;
                    
                    if (userPermissions.canEdit) {
                        html += `<li><a class="dropdown-item" href="javascript:void(0)" onclick="editAsset(${row.asset_id})"><i class="bi bi-pencil me-2 text-primary"></i> Edit Details</a></li>`;
                        
                        if (row.status === 'active') {
                            html += `<li><a class="dropdown-item" href="javascript:void(0)" onclick="changeStatus(${row.asset_id}, 'maintenance')"><i class="bi bi-tools me-2 text-warning"></i> Send to Maintenance</a></li>`;
                        } else if (row.status === 'maintenance') {
                            html += `<li><a class="dropdown-item" href="javascript:void(0)" onclick="changeStatus(${row.asset_id}, 'active')"><i class="bi bi-check-circle me-2 text-success"></i> Return to Service</a></li>`;
                        }
                    }
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                                 <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteAsset(${row.asset_id})"><i class="bi bi-trash me-2"></i> Delete Asset</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        pageLength: 25,
        dom: 'rtip',
        drawCallback: function() {
            console.log('Table redrawn');
        }
    });

    // Log View Action
    $.post(APP_URL + '/api/log_audit', {
        action: 'view_list',
        activity_type: 'view',
        entity_type: 'asset',
        description: 'Viewed assets management page'
    });

    // Log when Add Asset modal is opened
    $('#assetModal').on('show.bs.modal', function() {
        if (!$('#asset_id').val()) {
            $.post(APP_URL + '/api/log_audit', {
                action: 'open_add_modal',
                activity_type: 'view',
                entity_type: 'asset',
                description: 'Opened Add New Asset modal'
            });
        }
    });

    let searchTimeout;
    $('#searchFilter').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            table.ajax.reload();
            $.post(APP_URL + '/api/log_audit', {
                action: 'search_assets',
                activity_type: 'view',
                entity_type: 'asset',
                description: `Searched assets with term: ${$(this).val()}`
            });
        }, 500);
    });

    // Form submission
    $('#assetForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#assetForm button[type="submit"]');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');

        const formData = $(this).serialize();
        const assetId = $('#asset_id').val();
        const action = assetId ? 'update_asset' : 'add_asset';

        $.ajax({
            url: APP_URL + '/api/operations/save_asset',
            type: 'POST',
            data: formData,
            success: function(res) {
                if (res.success) {
                    $('#assetModal').modal('hide');
                    table.ajax.reload();
                    
                    Swal.fire({
                        icon: 'success',
                        title: res.message,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#0d6efd'
                    });
                } else {
                    showToast('error', res.message);
                }
            },
            complete: () => {
                $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Save Asset');
            }
        });
    });

    // Category field toggling logic
    $('#categorySelect').on('change', function() {
        const val = $(this).val();
        if (val === 'Other') {
            toggleCategoryField('input');
        } else {
            $('#categoryHidden').val(val);
        }
    });

    $('#categoryInput').on('input', function() {
        $('#categoryHidden').val($(this).val());
    });

    // Modal reset
    $('#assetModal').on('hidden.bs.modal', function() {
        $('#assetForm')[0].reset();
        $('#asset_id').val('');
        $('#assetModalLabel').html('<i class="bi bi-plus-circle me-2"></i>Add New Asset');
        $('#btnSaveText').text('Save Asset');
        toggleCategoryField('select'); // Reset category field to select
    });
});

function toggleCategoryField(mode) {
    if (mode === 'input') {
        $('#categorySelectDiv').addClass('d-none');
        $('#categoryInputDiv').removeClass('d-none');
        $('#categoryInput').focus();
        // Clear hidden val so user has to type
        $('#categoryHidden').val($('#categoryInput').val());
    } else {
        $('#categoryInputDiv').addClass('d-none');
        $('#categorySelectDiv').removeClass('d-none');
        $('#categorySelect').val('');
        $('#categoryHidden').val('');
    }
}

function formatCurrency(v) {
    return parseFloat(v).toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' TZS';
}

function refreshTable() {
    $('#assetsTable').DataTable().ajax.reload();
}

function changeStatus(id, status) {
    // Log intent
    $.post(APP_URL + '/api/log_audit', {
        action: 'update_status_intent',
        activity_type: 'update',
        entity_type: 'asset',
        entity_id: id,
        description: `User initiated status update for asset (ID: ${id}, New Status: ${status})`
    });

    const action = status === 'maintenance' ? 'send to maintenance' : 'return to service';
    
    Swal.fire({
        title: 'Are you sure?',
        text: `Do you want to ${action} this asset?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, proceed!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/operations/save_asset', { asset_id: id, status: status, action: 'update_status' }, function(res) {
                if (res.success) {
                    logReportAction('Updated Asset Status', `User updated asset ID: ${id} status to ${status}`);
                    refreshTable();
                    showToast('success', res.message);
                } else {
                    showToast('error', res.message);
                }
            });
        }
    });
}

function editAsset(id) {
    // Log intent
    logReportAction('Initiated Asset Edit', `User clicked to edit asset (ID: ${id})`);

    $.get(APP_URL + '/api/operations/get_asset', { id: id }, function(res) {
        if (res.success) {
            const data = res.data;
            $('#asset_id').val(data.asset_id);
            $('input[name="asset_name"]').val(data.asset_name);
            $('input[name="asset_code"]').val(data.asset_code);
            
            // Handle Category value for Select or Input
            const standardCategories = ["Office Equipment", "Furniture", "Vehicles", "Computers", "Property", "Machinery"];
            if (standardCategories.includes(data.category)) {
                toggleCategoryField('select');
                $('#categorySelect').val(data.category);
            } else {
                toggleCategoryField('input');
                $('#categoryInput').val(data.category);
            }
            $('#categoryHidden').val(data.category);

            $('input[name="location"]').val(data.location);
            $('input[name="purchase_date"]').val(data.purchase_date);
            $('input[name="cost"]').val(data.cost);
            $('select[name="status"]').val(data.status);
            $('textarea[name="description"]').val(data.description);
            
            $('#assetModalLabel').html('<i class="bi bi-pencil me-2"></i>Edit Asset');
            $('#btnSaveText').text('Update Asset');
            $('#assetModal').modal('show');
        } else {
            showToast('error', res.message);
        }
    });
}

function deleteAsset(id) {
    // Log intent
    logReportAction('Initiated Asset Deletion', `User initiated deletion for asset (ID: ${id})`);

    Swal.fire({
        title: 'Delete Asset?',
        text: 'Are you sure you want to delete this asset? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, keep it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/operations/delete_asset', { asset_id: id }, function(res) {
                if (res.success) {
                    logReportAction('Deleted Asset Record', `User successfully deleted asset ID: ${id}`);
                    refreshTable();
                    showToast('success', res.message);
                } else {
                    showToast('error', res.message);
                }
            });
        }
    });
}

function printAssets() {
    logReportAction('Printed Assets List', 'User generated a printed list of assets');
    const category = $('#categoryFilter').val();
    const status = $('#statusFilter').val();
    const search = $('#searchFilter').val();
    
    // Open in a new tab/window using the dynamic system URL
    const url = '<?= getUrl("print-assets") ?>?c=' + category + '&s=' + status + '&q=' + encodeURIComponent(search) + '&a=1';
    window.open(url, '_blank');
}

function exportAssets() {
    logReportAction('Exported Assets List', 'User exported the assets list to Excel/CSV');
    const category = $('#categoryFilter').val();
    const status = $('#statusFilter').val();
    window.location.href = `/api/operations/export_assets.php?category=${category}&status=${status}`;
}

function showToast(type, msg) {
    if (type === 'success') {
        Swal.fire({
            icon: 'success',
            title: msg,
            confirmButtonText: 'OK',
            confirmButtonColor: '#0d6efd'
        });
    } else {
        Swal.fire({
            icon: type,
            title: msg,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    }
}
</script>

<?php
includeFooter();
?>

<!-- AUTOMATED TEST SCRIPT FOR CATEGORY FIELD -->
<div id="test-trigger" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999;">
    <button class="btn btn-dark btn-sm rounded-pill shadow" onclick="runCategoryTest()">
        <i class="bi bi-bug"></i> Test Category Feature
    </button>
</div>

<script>
function runCategoryTest() {
    console.log("Starting Category Feature Test...");
    
    // 1. Open Modal
    $('#assetModal').modal('show');
    
    setTimeout(() => {
        // 2. Check if select is visible
        if ($('#categorySelectDiv').is(':visible')) {
            console.log("✓ Select dropdown is visible.");
        } else {
            console.error("✗ Select dropdown is hidden!");
            Swal.fire('Test Failed', 'Dropdown not visible', 'error');
            return;
        }

        // 3. Select 'Other'
        $('#categorySelect').val('Other').trigger('change');
        
        setTimeout(() => {
            // 4. Check if input is visible
            if ($('#categoryInputDiv').is(':visible') && $('#categorySelectDiv').is(':hidden')) {
                console.log("✓ Successfully switched to Input field after selecting 'Other'.");
            } else {
                console.error("✗ Failed to switch to input field!");
                Swal.fire('Test Failed', 'Input field did not appear', 'error');
                return;
            }

            // 5. Type something and check hidden value
            $('#categoryInput').val('Test Category').trigger('input');
            if ($('#categoryHidden').val() === 'Test Category') {
                console.log("✓ Hidden input correctly updated from manual text.");
            } else {
                console.error("✗ Hidden input not updated!");
                Swal.fire('Test Failed', 'Data sync failed', 'error');
                return;
            }

            // 6. Go back to select
            toggleCategoryField('select');
            if ($('#categorySelectDiv').is(':visible')) {
                console.log("✓ Successfully switched back to Select.");
                Swal.fire('Test Passed!', 'Category dropdown and manual input logic is working perfectly.', 'success');
            } else {
                console.error("✗ Failed to switch back to select!");
            }
        }, 1000);
    }, 1000);
}
</script>

<?php
ob_end_flush();
?>
