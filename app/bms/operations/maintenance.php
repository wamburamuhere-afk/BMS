<?php
// app/bms/operations/maintenance.php
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('assets'); // Using assets permission for now or you can create a 'maintenance' one

includeHeader();
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-primary"><i class="bi bi-tools"></i> Maintenance Logs</h2>
                            <p class="mb-0 text-muted">Track asset repairs, routine services, and inspections</p>
                        </div>
                        <div>
                            <button type="button" class="btn btn-primary shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#maintenanceModal">
                                <i class="bi bi-plus-circle me-1"></i> Log Maintenance
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 d-print-none">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0" id="stat-pending">0</h4>
                            <p class="small mb-0">Pending Tasks</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clock-history" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0" id="stat-progress">0</h4>
                            <p class="small mb-0">In Progress</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-gear-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0" id="stat-completed">0</h4>
                            <p class="small mb-0">Completed</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0" id="stat-total-cost">0</h4>
                            <p class="small mb-0">Total Costs</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash-stack" style="font-size: 2rem;"></i>
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
                    <label class="form-label small fw-bold text-muted mb-1">Status Filter</label>
                    <select class="form-select bg-light border-0" id="statusFilter" style="border-radius: 8px;">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1">Search</label>
                    <div class="input-group shadow-sm" style="border-radius: 8px; overflow: hidden; border: 1px solid #eee;">
                        <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="searchFilter" class="form-control border-0 p-2" placeholder="Search maintenance logs...">
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
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printLogs()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportLogs()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-excel text-success me-1"></i> Excel
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#maintenanceTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
        <div class="card-body p-0">

            <div class="table-responsive">
                <table id="maintenanceTable" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4" style="width: 50px;">S/NO</th>
                            <th>Asset</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Performed By</th>
                            <th>Cost</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Log Maintenance Modal -->
<div class="modal fade" id="maintenanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title" id="maintenanceModalLabel">
                    <i class="bi bi-tools me-2"></i>Log Maintenance Task
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="maintenanceForm">
                <input type="hidden" name="log_id" id="log_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Asset <span class="text-danger">*</span></label>
                            <select class="form-select" name="asset_id" id="assetSelect" required>
                                <option value="">Select Asset</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Maintenance Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="maintenance_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="maintenance_type" required>
                                <option value="routine">Routine Service</option>
                                <option value="repair">Repair</option>
                                <option value="upgrade">Upgrade</option>
                                <option value="inspection">Inspection</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Performed By</label>
                            <input type="text" class="form-control" name="performed_by" placeholder="Person or Company name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cost (TZS)</label>
                            <div class="input-group">
                                <span class="input-group-text font-monospace">TZS</span>
                                <input type="number" class="form-control" name="cost" step="0.01" value="0.00">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Expense Account <span class="text-danger gl-account-required d-none">*</span></label>
                            <select class="form-select select2-static" name="expense_account_id" id="expenseAccountSelect">
                                <option value="">— Select account (required when Completed) —</option>
                                <?php
                                require_once __DIR__ . '/../../../core/payment_source.php';
                                foreach (expenseAccounts($pdo) as $acc):
                                ?>
                                <option value="<?= $acc['account_id'] ?>"><?= safe_output($acc['account_code']) ?> — <?= safe_output($acc['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status" id="maintenanceStatus">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Completion Date</label>
                            <input type="date" class="form-control" name="completion_date">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Maintenance Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" rows="3" required placeholder="What was done?"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Additional Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Parts replaced, warranties, etc."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark px-4">
                        <i class="bi bi-check-lg me-1"></i> <span id="btnSaveText">Save Log</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light p-4">
                <h5 class="modal-title fw-bold">Maintenance Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewDetailsContent">
                <!-- Data loaded via JS -->
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .status-badge { padding: 0.35em 0.65em; font-size: 0.75em; font-weight: 600; border-radius: 50rem; }
    
    /* 📱 MOBILE RESPONSIVE REFINEMENTS */
    @media (max-width: 767px) {
        .container-fluid { padding-top: 0 !important; margin-top: 0 !important; }
        
        /* Small Buttons for Mobile */
        .btn { padding: 0.25rem 0.5rem !important; font-size: 0.75rem !important; }
        
        /* Sticky Page Heading (Only this sticks) */
        .row.mb-4:first-child {
            position: sticky;
            top: 55px; /* Just below navbar */
            z-index: 1050;
            background: #fff;
            margin-bottom: 10px !important;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .row.mb-4:first-child h2 { font-size: 1.1rem !important; margin-bottom: 0 !important; }
        .row.mb-4:first-child p { display: none; } /* Hide sub-text on mobile to save space */
        .row.mb-4:first-child .text-end { display: block; width: 100%; margin-top: 5px; }

        /* Filter Section (Moves Upwards / Not Sticky) */
        .card.shadow-sm.border-0.mb-4.bg-white {
            position: relative !important;
            top: 0 !important;
            z-index: 1;
            margin-bottom: 15px !important;
        }

        #maintenanceTable, #maintenanceTable thead, #maintenanceTable tbody, #maintenanceTable th, #maintenanceTable td, #maintenanceTable tr { 
            display: block; 
        }
        
        #maintenanceTable thead { display: none; }
        
        #maintenanceTable tr {
            margin-bottom: 12px;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            padding: 8px;
        }

        #maintenanceTable td {
            border: none;
            position: relative;
            padding-left: 45% !important;
            text-align: right !important;
            min-height: 32px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            border-bottom: 1px solid #f8f9fa;
            font-size: 0.85rem;
        }

        #maintenanceTable td:last-child { border-bottom: none; }

        #maintenanceTable td:before {
            content: attr(data-label);
            position: absolute;
            left: 12px;
            width: 40%;
            padding-right: 5px;
            white-space: nowrap;
            text-align: left;
            font-weight: 700;
            color: #888;
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        #maintenanceTable td[data-label="Asset"] {
            padding-left: 12px !important;
            text-align: left !important;
            display: block;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        #maintenanceTable td[data-label="Asset"]:before { display: none; }
        
        #maintenanceTable td[data-label="Actions"] {
            justify-content: center;
            padding-left: 12px !important;
        }
        #maintenanceTable td[data-label="Actions"]:before { display: none; }
    }
</style>

<script>
$(document).ready(function() {
    logReportAction('Viewed Maintenance Logs', 'User viewed the maintenance management logs');
    // Load assets for the select dropdown
    $.get('/api/operations/get_assets.php', { length: 1000 }, function(res) {
        if (res.data) {
            res.data.forEach(asset => {
                $('#assetSelect').append(`<option value="${asset.asset_id}">${asset.asset_name} (${asset.asset_code || 'No Code'})</option>`);
            });
        }
    });

    const table = $('#maintenanceTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/operations/get_maintenance_logs.php',
            data: function(d) {
                d.status = $('#statusFilter').val();
                d.search_term = $('#searchFilter').val();
            },
            dataSrc: function(json) {
                // Update Statistics
                $('#stat-pending').text(json.stats.pending || 0);
                $('#stat-progress').text(json.stats.progress || 0);
                $('#stat-completed').text(json.stats.completed || 0);
                $('#stat-total-cost').text(parseFloat(json.stats.total_cost || 0).toLocaleString() + ' TZS');
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
                render: (data, t, row) => `<strong>${data}</strong><br><small class="text-muted">${row.asset_code || ''}</small>`,
                createdCell: (td) => $(td).attr('data-label', 'Asset')
            },
            { 
                data: 'maintenance_date',
                createdCell: (td) => $(td).attr('data-label', 'Date')
            },
            { 
                data: 'maintenance_type', 
                render: data => data.charAt(0).toUpperCase() + data.slice(1),
                createdCell: (td) => $(td).attr('data-label', 'Type')
            },
            { 
                data: 'performed_by',
                createdCell: (td) => $(td).attr('data-label', 'Performed By')
            },
            { 
                data: 'cost', 
                render: data => `<strong>${parseFloat(data).toLocaleString()} TZS</strong>`,
                createdCell: (td) => $(td).attr('data-label', 'Cost')
            },
            { 
                data: 'status',
                render: function(data) {
                    let cls = 'secondary';
                    if (data === 'completed') cls = 'success';
                    if (data === 'in_progress') cls = 'primary';
                    if (data === 'pending') cls = 'warning text-dark';
                    if (data === 'cancelled') cls = 'danger';
                    return `<span class="status-badge bg-${cls}">${data.replace('_', ' ').toUpperCase()}</span>`;
                },
                createdCell: (td) => $(td).attr('data-label', 'Status')
            },
            {
                data: null,
                orderable: false,
                className: 'text-end pe-4',
                createdCell: (td) => $(td).attr('data-label', 'Actions'),
                render: function(data, type, row) {
                    return `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="viewDetails(${row.log_id})"><i class="bi bi-eye me-2 text-info"></i> View Details</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="editLog(${row.log_id})"><i class="bi bi-pencil me-2 text-primary"></i> Edit Log</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteLog(${row.log_id})"><i class="bi bi-trash me-2"></i> Delete</a></li>
                            </ul>
                        </div>
                    `;
                }
            }
        ],
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        dom: '<"d-none" l>rt<"d-flex justify-content-between align-items-center p-4 border-top" ip>',
        initComplete: function() {
            $('#maintenanceTable_length').appendTo('#tableLengthContainer');
            $('.dataTables_length select').addClass('form-select form-select-sm').css('width', 'auto');
        },
        drawCallback: function() {
            const info = this.api().page.info();
            $('#tableInfoContainer').html(`Showing ${info.start + 1} to ${info.end} of ${info.recordsDisplay} logs`);
        }
    });

    // Show/hide expense account required marker based on status
    $(document).on('change', '#maintenanceStatus', function() {
        const isCompleted = $(this).val() === 'completed';
        $('.gl-account-required').toggleClass('d-none', !isCompleted);
    });

    $('#maintenanceForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

        $.post('<?= buildUrl('api/operations/save_maintenance_log.php') ?>', $(this).serialize(), function(res) {
            if (res.success) {
                $('#maintenanceModal').modal('hide');
                table.ajax.reload();
                showToast('success', res.message);
            } else {
                showToast('error', res.message);
            }
        }).always(() => {
            $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Save Log');
        });
    });

    $('#maintenanceModal').on('hidden.bs.modal', function() {
        $('#maintenanceForm')[0].reset();
        $('#log_id').val('');
        $('#maintenanceModalLabel').html('<i class="bi bi-tools me-2"></i>Log Maintenance Task');
    });
});

function refreshTable() { $('#maintenanceTable').DataTable().ajax.reload(); }

function viewDetails(id) {
    $.get('/api/operations/get_maintenance_log.php', { id: id }, function(res) {
        if (res.success) {
            const d = res.data;
            const statusMap = {
                'pending': { cls: 'warning text-dark', label: 'PENDING' },
                'in_progress': { cls: 'primary', label: 'IN PROGRESS' },
                'completed': { cls: 'success', label: 'COMPLETED' },
                'cancelled': { cls: 'danger', label: 'CANCELLED' }
            };
            const s = statusMap[d.status] || { cls: 'secondary', label: d.status.toUpperCase() };

            const html = `
                <div class="mb-4 text-center">
                    <span class="status-badge bg-${s.cls} fs-6">${s.label}</span>
                </div>
                <div class="row g-3">
                    <div class="col-12"><small class="text-muted d-block">Asset</small><strong>${d.asset_name || 'Asset ID: '+d.asset_id}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Log Date</small><strong>${d.maintenance_date}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Type</small><strong>${d.maintenance_type.toUpperCase()}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Cost</small><strong class="text-danger">${parseFloat(d.cost).toLocaleString()} TZS</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Performed By</small><strong>${d.performed_by || 'N/A'}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Completion Date</small><strong>${d.completion_date || 'N/A'}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Date Logged</small><strong>${d.created_at || 'N/A'}</strong></div>
                    <div class="col-12"><small class="text-muted d-block font-weight-bold">Description</small><div class="bg-light p-3 rounded border mt-1">${d.description}</div></div>
                    <div class="col-12"><small class="text-muted d-block font-weight-bold">Additional Notes</small><div class="bg-light p-3 rounded border mt-1 small text-muted">${d.notes || 'No extra notes provided.'}</div></div>
                </div>
            `;
            $('#viewDetailsContent').html(html);
            logReportAction('Viewed Maintenance Detail', 'User viewed full details for maintenance log ID: ' + id);
            $('#viewModal').modal('show');
        }
    });
}

function printLogs() {
    logReportAction('Printed Maintenance Logs', 'User generated a printed list of maintenance logs');
    const status = $('#statusFilter').val();
    const search = $('#searchFilter').val();
    
    const url = '<?= getUrl("print-maintenance") ?>?s=' + status + '&q=' + encodeURIComponent(search) + '&a=1';
    window.open(url, '_blank');
}

function exportLogs() {
    logReportAction('Exported Maintenance Logs', 'User exported the maintenance logs list to Excel/CSV');
    const status = $('#statusFilter').val();
    const search = $('#searchFilter').val();
    window.location.href = `/api/operations/export_maintenance.php?status=${status}&search_term=${search}`;
}

function editLog(id) {
    logReportAction('Initiated Maintenance Edit', 'User clicked edit for maintenance log ID: ' + id);
    $.get('/api/operations/get_maintenance_log.php', { id: id }, function(res) {
        if (res.success) {
            const d = res.data;
            $('#log_id').val(d.log_id);
            $('select[name="asset_id"]').val(d.asset_id);
            $('input[name="maintenance_date"]').val(d.maintenance_date);
            $('select[name="maintenance_type"]').val(d.maintenance_type);
            $('input[name="performed_by"]').val(d.performed_by);
            $('input[name="cost"]').val(d.cost);
            $('select[name="status"]').val(d.status).trigger('change');
            $('input[name="completion_date"]').val(d.completion_date);
            $('textarea[name="description"]').val(d.description);
            $('textarea[name="notes"]').val(d.notes);
            if (d.expense_account_id) $('select[name="expense_account_id"]').val(d.expense_account_id).trigger('change');
            $('#maintenanceModalLabel').text('Edit Maintenance Task');
            $('#maintenanceModal').modal('show');
        }
    });
}

function deleteLog(id) {
    Swal.fire({
        title: 'Delete Log?',
        text: 'Are you sure you want to delete this maintenance log? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/operations/delete_maintenance_log.php', { log_id: id }, function(res) {
                if (res.success) { 
                    logReportAction('Deleted Maintenance Log', 'User deleted maintenance log ID: ' + id);
                    refreshTable(); 
                    showToast('success', res.message); 
                } else {
                    showToast('error', res.message);
                }
            });
        }
    });
}

function clearFilters() {
    $('#statusFilter').val('');
    $('#searchFilter').val('');
    refreshTable();
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
            timer: 3000 
        });
    }
}
</script>

<?php
includeFooter();
ob_end_flush();
?>

<style>
.card.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
    border: 1px solid #badbcc !important;
}
.card.custom-stat-card:hover { transform: translateY(-3px); }
.card.custom-stat-card h4, 
.card.custom-stat-card p, 
.card.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.btn-white:hover {
    background-color: #f8f9fa !important;
}
.form-select-sm:focus {
    box-shadow: none !important;
}
</style>
