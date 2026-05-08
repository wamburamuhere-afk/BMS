<?php
// File: purchase_orders.php
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('purchase_orders');

includeHeader();

// Get filter defaults
$supplier_id = $_GET['supplier'] ?? '';
$status = $_GET['status'] ?? '';

// Get suppliers for filter dropdown
global $pdo;
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// Check projects setting
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}
?>

<div class="purchase-orders-dashboard p-2 p-md-3" style="background: #ffffff; min-height: 100vh; width: 100%;">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
        
        
        <p class="text-dark mb-1 small text-uppercase">
            <?php 
            $web_email = [];
            if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
            if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
            if (!empty($web_email)) echo implode(" | ", $web_email);
            ?>
        </p>

        <p class="text-dark mb-1 small text-uppercase">
            <?php 
            $tin_vrn = [];
            if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
            if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
            if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
            ?>
        </p>

        <div class="mt-3">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Purchase Order List</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Purchase Orders</li>
        </ol>
    </nav>

    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-print-none flex-grow-1">
                    <h2 class="fw-bold mb-0 fs-4 fs-md-3 text-nowrap"><i class="bi bi-cart-check text-success me-2"></i>Purchase Orders</h2>
                    <p class="text-muted mb-0 small d-none d-md-block">Procurement and stock replenishment management</p>
                </div>
                <div class="d-flex align-items-center gap-2 d-print-none">
                    <a href="<?= getUrl('purchase_order_create') ?>" class="btn btn-primary btn-sm shadow-sm px-3 text-nowrap" style="border-radius: 6px;">
                        <i class="bi bi-plus-circle me-1"></i> New Order
                    </a>
                    
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2 p-md-3 overflow-hidden">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="flex-grow-1 overflow-hidden text-center text-sm-start">
                        <h4 class="mb-0 fw-bold text-nowrap" id="stat-total-orders" style="font-size: 1.1rem;">0</h4>
                        <small class="text-uppercase small fw-bold text-muted d-block text-truncate" style="font-size: 0.65rem;">Total Orders</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2 p-md-3 overflow-hidden">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi bi-cash-stack"></i></div>
                    <div class="flex-grow-1 overflow-hidden text-center text-sm-start">
                        <h4 class="mb-0 fw-bold" id="stat-total-amount" style="font-size: 1.1rem; word-break: break-word;">TSh 0.00</h4>
                        <small class="text-uppercase small fw-bold text-muted d-block text-truncate" style="font-size: 0.65rem;">Total Value</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2 p-md-3 overflow-hidden">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi bi-clock-history"></i></div>
                    <div class="flex-grow-1 overflow-hidden text-center text-sm-start">
                        <h4 class="mb-0 fw-bold text-nowrap" id="stat-pending-orders" style="font-size: 1.1rem;">0</h4>
                        <small class="text-uppercase small fw-bold text-muted d-block text-truncate" style="font-size: 0.65rem;">Pending</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2 p-md-3 overflow-hidden">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi bi-check-circle"></i></div>
                    <div class="flex-grow-1 overflow-hidden text-center text-sm-start">
                        <h4 class="mb-0 fw-bold" id="stat-approved-amount" style="font-size: 1.1rem; word-break: break-word;">TSh 0.00</h4>
                        <small class="text-uppercase small fw-bold text-muted d-block text-truncate" style="font-size: 0.65rem;">Approved</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 border-0 shadow-sm d-print-none">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filters & Search</h6>
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select class="form-select" name="status">
                        <option value="" <?= !$status ? 'selected' : '' ?>>All Statuses</option>
                        <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="pending" <?= $status == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= $status == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="ordered" <?= $status == 'ordered' ? 'selected' : '' ?>>Ordered</option>
                        <option value="received" <?= $status == 'received' ? 'selected' : '' ?>>Received</option>
                        <option value="completed" <?= $status == 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Supplier</label>
                    <select class="form-select" name="supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['supplier_id'] ?>" <?= $supplier_id == $s['supplier_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" class="form-control" name="date_from">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" class="form-control" name="date_to">
                </div>
                <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-filter me-1"></i> Apply Filter
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="clearFilters()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="mb-3 d-print-none text-start">
        <span class="badge bg-white text-dark border border-light-subtle px-3 py-2 fs-6 rounded-2 shadow-sm">
            <i class="bi bi-cart-check-fill text-success me-1"></i> Purchase Order Records
        </span>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 d-print-none">
        <div class="d-flex flex-wrap align-items-center gap-2 flex-grow-1">
            <div class="d-flex flex-nowrap shadow-sm bg-white" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; height: 38px;">
                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                </button>
                <div style="width: 1px; background: #eee; height: 38px;"></div>
                <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="exportOrders()" style="background: #fff; height: 38px;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 38px;"></div>
                <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="printList()" style="background: #fff; height: 38px;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-2 py-1" style="border: 1px solid #dee2e6; border-radius: 8px; height: 38px;">
                <span class="small text-muted me-2 text-nowrap"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" id="filter_limit" style="width: 45px; background: transparent;" onchange="$('#purchaseOrdersTable').DataTable().page.len(this.value).draw();">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Views Container -->
    <div id="tableView" class="view-section">
        <!-- Table Card -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom d-flex align-items-center py-2 px-3 d-print-none">
                <span class="fw-bold text-muted small">Purchase Order Records</span>
                <div class="btn-group shadow-sm ms-auto" role="group">
                    <button type="button" class="btn btn-primary btn-sm text-white" id="btn-table-view" onclick="toggleView('table')" title="Table View">
                        <i class="bi bi-table"></i>
                    </button>
                    <button type="button" class="btn btn-light btn-sm border" id="btn-card-view" onclick="toggleView('card')" title="Card View">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="purchaseOrdersTable" style="width: 100%;">
                        <thead class="bg-light text-uppercase small fw-bold">
                            <tr>
                                <th style="width:50px;" class="ps-4">S/NO</th>
                                <th class="ps-4">Order #</th>
                                <th>Supplier</th>
                                <?php if ($enable_projects): ?><th>Project</th><?php endif; ?>
                                <th>Order Date</th>
                                <th class="text-end">Total Amount</th>
                                <th>Status</th>
                                <th class="text-end pe-4 d-print-none">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="border-top-0">
                            <!-- DataTables content -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Card View Container (Hidden by default) -->
    <div id="cardView" class="view-section d-none">
        <div class="row g-3" id="purchaseOrdersCards">
            <!-- Cards will be rendered here by JS -->
        </div>
    </div>
</div> <!-- dashboard end -->

<script>
$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Purchase Orders List', 'User viewed the purchase orders management list');

    const table = $('#purchaseOrdersTable').DataTable({
        dom: 'rtip',
        serverSide: false, // Set to true if dataset grows very large
        ajax: {
            url: '<?= getUrl('api/get_purchase_orders') ?>',
            data: function(d) {
                d.status = $('select[name="status"]').val();
                d.supplier = $('select[name="supplier"]').val();
                d.date_from = $('input[name="date_from"]').val();
                d.date_to = $('input[name="date_to"]').val();
            },
            dataSrc: function(json) {
                if (json.stats) {
                    $('#stat-total-orders').text(json.stats.total_orders);
                    $('#stat-total-amount').text(formatCurrency(json.stats.total_amount));
                    $('#stat-pending-orders').text(json.stats.pending_count);
                    $('#stat-approved-amount').text(formatCurrency(json.stats.approved_amount || 0));
                }
                return json.data;
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'ps-4 text-center text-muted small fw-bold',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            { 
                data: 'order_number',
                className: 'ps-4',
                render: (data, t, row) => `<span class="fw-bold text-dark custom-code">${data}</span>`
            },
            { data: 'supplier_name' },
            <?php if ($enable_projects): ?>
            { 
                data: 'project_name',
                defaultContent: '-',
                render: (data) => data ? `<span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25">${data}</span>` : '-'
            },
            <?php endif; ?>
            { data: 'order_date' },
            { 
                data: 'grand_total',
                className: 'text-end',
                render: (data, t, row) => `<strong>${formatCurrency(data)}</strong> <small class="text-muted">${row.currency}</small>`
            },
            { 
                data: 'status',
                render: data => {
                    const colors = {
                        'draft': 'text-muted',
                        'pending': 'text-warning', // Yellow
                        'approved': 'text-info',
                        'ordered': 'text-info',
                        'received': 'text-primary',
                        'partially_received': 'text-primary', // Blue
                        'completed': 'text-dark', // Black
                        'cancelled': 'text-danger'
                    };
                    const colorClass = colors[data] || 'text-dark';
                    return `<span class="${colorClass} text-uppercase fw-bold" style="font-size: 0.85rem; letter-spacing: 0.5px;">${data.replace('_', ' ')}</span>`;
                }
            },
            { 
                data: null,
                className: 'text-end pe-4 d-print-none',
                render: function(data, type, row) {
                    return `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><a class="dropdown-item py-2" href="<?= getUrl('purchase_order_details') ?>?id=${row.purchase_order_id}" onclick="logReportAction('Viewed Purchase Order Details Link', 'User clicked to view details for PO #${row.order_number}')"><i class="bi bi-eye text-primary me-2"></i> View Details</a></li>
                                <li><a class="dropdown-item py-2" href="<?= getUrl('purchase_order_create') ?>?edit=${row.purchase_order_id}" onclick="logReportAction('Initiated Purchase Order Edit', 'User clicked edit for PO #${row.order_number}')"><i class="bi bi-pencil text-info me-2"></i> Edit Order</a></li>
                                <li><a class="dropdown-item py-2" href="#" onclick="printOrder(${row.purchase_order_id}, '${row.order_number}')"><i class="bi bi-printer text-dark me-2"></i> Print Order</a></li>
                                ${(row.status === 'pending' || row.status === 'draft') ? `<li><a class="dropdown-item py-2 text-success" href="#" onclick="approveOrder(${row.purchase_order_id}, '${row.order_number}')"><i class="bi bi-check-circle text-success me-2"></i> Approve Order</a></li>` : ''}
                                ${(row.status === 'approved' || row.status === 'ordered' || row.status === 'partially_received') ? `<li><a class="dropdown-item py-2 text-info" href="<?= getUrl('dn_create') ?>?po_id=${row.purchase_order_id}"><i class="bi bi-truck me-2"></i> Add Delivery Note</a></li>` : ''}
                                <li><hr class="dropdown-divider opacity-50"></li>
                                <li><a class="dropdown-item py-2 text-danger" href="#" onclick="cancelOrder(${row.purchase_order_id})"><i class="bi bi-trash me-2"></i> Cancel Order</a></li>
                            </ul>
                        </div>
                    `;
                }
            }
        ],
        order: [[0, 'desc']],
        drawCallback: function(settings) {
            const api = this.api();
            const data = api.rows({ page: 'current' }).data();
            const cardContainer = $('#purchaseOrdersCards');
            cardContainer.empty();
            
            data.each(function(row) {
                const statusColors = {
                    'draft': 'bg-secondary',
                    'pending': 'bg-warning',
                    'approved': 'bg-info',
                    'ordered': 'bg-info',
                    'received': 'bg-primary',
                    'partially_received': 'bg-primary',
                    'completed': 'bg-success',
                    'cancelled': 'bg-danger'
                };
                const statusColor = statusColors[row.status] || 'bg-dark';
                
                const card = `
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-light">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <span class="custom-code small mb-2 d-inline-block">${row.order_number}</span>
                                        <h6 class="fw-bold mb-0">${row.supplier_name}</h6>
                                    </div>
                                    <span class="badge ${statusColor} text-uppercase small">${row.status.replace('_', ' ')}</span>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Date:</span>
                                        <span class="fw-medium">${row.order_date}</span>
                                    </div>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-muted">Total:</span>
                                        <span class="fw-bold text-dark">${formatCurrency(row.grand_total)} ${row.currency}</span>
                                    </div>
                                    <?php if ($enable_projects): ?>
                                    <div class="d-flex justify-content-between small">
                                        <span class="text-muted">Project:</span>
                                        <span class="text-info">${row.project_name || '-'}</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <hr class="my-2 opacity-50">
                                <div class="d-flex justify-content-center gap-2">
                                    <a class="btn btn-sm btn-outline-primary shadow-sm" href="<?= getUrl('purchase_order_details') ?>?id=${row.purchase_order_id}" title="View Details"><i class="bi bi-eye"></i> View</a>
                                    <a class="btn btn-sm btn-outline-warning shadow-sm" href="<?= getUrl('purchase_order_create') ?>?edit=${row.purchase_order_id}" title="Edit"><i class="bi bi-pencil"></i> Edit</a>
                                    <button class="btn btn-sm btn-outline-danger shadow-sm" onclick="cancelOrder(${row.purchase_order_id})" title="Cancel"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                cardContainer.append(card);
            });
        }
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        table.ajax.reload();
    });

    // Load view preference (auto-card on mobile)
    const savedView = localStorage.getItem('purchaseOrdersView');
    const view = (window.innerWidth < 768) ? 'card' : (savedView || 'table');
    toggleView(view);
});

function toggleView(viewType) {
    const tableView = $('#tableView');
    const cardView = $('#cardView');
    
    if (viewType === 'table') {
        tableView.removeClass('d-none');
        cardView.addClass('d-none');
        $('#btn-table-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-card-view').removeClass('btn-primary text-white').addClass('btn-light');
    } else {
        tableView.addClass('d-none');
        cardView.removeClass('d-none');
        $('#btn-card-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-table-view').removeClass('btn-primary text-white').addClass('btn-light');
    }
    
    // Only persist explicit desktop choices — mobile auto-switch must not pollute desktop pref
    if (window.innerWidth >= 768) {
        localStorage.setItem('purchaseOrdersView', viewType);
    }
}

function formatCurrency(v) {
    return new Intl.NumberFormat('en-TZ', { style: 'decimal', minimumFractionDigits: 2 }).format(v);
}

function clearFilters() {
    $('#filterForm')[0].reset();
    $('#purchaseOrdersTable').DataTable().ajax.reload();
}

function printList() {
    logReportAction('Printed Purchase Orders List', 'User printed the purchase orders list');
    const oldTitle = document.title;
    document.title = "";
    window.print();
    document.title = oldTitle;
}

function printOrder(id, orderNumber) {
    logReportAction('Printed Purchase Order', 'User printed purchase order #' + orderNumber);
    window.open('<?= getUrl('print_purchase_order') ?>?id=' + id, '_blank');
}

function copyTable() {
    const table = document.getElementById('purchaseOrdersTable');
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    logReportAction('Copied Purchase Orders List', 'User copied purchase orders list to clipboard');
    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1000, showConfirmButton: false });
}

function exportOrders() {
    const table = document.getElementById('purchaseOrdersTable');
    const rows = Array.from(table.querySelectorAll('tr'));
    const csvContent = rows.map(row => {
        const cols = Array.from(row.querySelectorAll('th, td')).slice(0, -1); // Exclude actions
        return cols.map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
    }).join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', 'PurchaseOrders.csv');
    document.body.appendChild(link);
    logReportAction('Exported Purchase Orders Excel', 'User exported purchase orders list to Excel/CSV');
    link.click();
    document.body.removeChild(link);
}

function cancelOrder(id) {
    Swal.fire({
        title: 'Cancel Order?',
        text: "This will permanently delete the order. You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, keep it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('api/delete_purchase_order') ?>',
                type: 'POST',
                data: { order_id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Deleted Purchase Order', 'User deleted purchase order #' + id);
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'The purchase order has been deleted.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        $('#purchaseOrdersTable').DataTable().ajax.reload();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to delete order'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Communication error. Please try again.'
                    });
                }
            });
        }
    });
}

function approveOrder(id, orderNumber) {
    Swal.fire({
        title: 'Approve Order?',
        text: 'Are you sure you want to approve PO #' + orderNumber + '?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, approve it!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl('api/account/update_purchase_order_status.php') ?>',
                type: 'POST',
                data: { purchase_order_id: id, status: 'approved' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Approved Purchase Order', 'User approved purchase order #' + orderNumber);
                        Swal.fire({
                            icon: 'success',
                            title: 'Approved!',
                            text: 'Purchase order has been approved.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        $('#purchaseOrdersTable').DataTable().ajax.reload();
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to approve order' });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Communication error. Please try again.' });
                }
            });
        }
    });
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card small,
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.bg-orange { background-color: #fd7e14 !important; }
.text-orange { color: #fd7e14 !important; }

.stats-icon {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 1.25rem;
    background: rgba(15, 81, 50, 0.1);
    color: #0f5132 !important;
}

.bg-success-soft { background-color: rgba(25, 135, 84, 0.1) !important; }
.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 6px;
    border-radius: 6px;
    font-weight: bold;
}

.table thead th {
    background-color: #f8fafc !important;
    border-bottom: 2px solid #e2e8f0;
    padding: 0.75rem 0.5rem;
    color: #475569;
    font-size: 0.85rem;
}

.table td {
    padding: 0.6rem 0.5rem !important;
    font-size: 0.82rem;
}

.purchase-orders-dashboard {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: hidden !important;
    padding-left: 20px !important;
    padding-right: 20px !important;
}

@media (max-width: 576px) {
    .purchase-orders-dashboard {
        padding-left: 10px !important;
        padding-right: 10px !important;
    }
}

.dropdown-menu { 
    padding: 0.5rem;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 15px;
}
.dropdown-item {
    border-radius: 8px;
    margin-bottom: 2px;
}

@media print {
    body { background: white !important; padding: 0 !important; padding-top: 0 !important; }
    .purchase-orders-dashboard { background: white !important; padding: 20px !important; }
    .custom-stat-card { 
        box-shadow: none !important; 
        border: 1px solid #d1e7dd !important;
        background-color: #f8fafc !important;
    }
    .custom-stat-card h4, .custom-stat-card small { color: #000 !important; }
    .table-responsive { overflow: visible !important; }
    table { width: 100% !important; border-collapse: collapse !important; }
    th, td { border: 1px solid #dee2e6 !important; padding: 8px !important; }
    th { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; }
    .d-print-none { display: none !important; }
    .dataTables_length, .dataTables_info, .dataTables_paginate { display: none !important; }

    /* Force Table View on Print */
    #tableView { display: block !important; }
    #cardView { display: none !important; }

    /* Print header styling */
    #printHeader h1 {
        color: #0d6efd !important;
        text-transform: uppercase;
        font-weight: 800;
        margin: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    #printHeader h2 {
        color: #495057 !important;
        text-transform: uppercase;
        font-weight: 600;
        margin: 5px 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<?php includeFooter(); ?>

