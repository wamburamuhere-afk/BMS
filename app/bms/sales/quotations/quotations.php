<?php
// File: app/bms/sales/quotations/quotations.php
require_once __DIR__ . '/../../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('sales_orders');

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View quotations', 'User viewed the quotations management list');

global $pdo;

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$customer_filter = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query for quotations
$query = "
    SELECT 
        q.*,
        c.customer_name,
        c.company_name,
        u.username as created_by_name,
        (SELECT COUNT(*) FROM quotation_items qi WHERE qi.order_id = q.sales_order_id) as total_items
    FROM quotations q
    LEFT JOIN customers c ON q.customer_id = c.customer_id
    LEFT JOIN users u ON q.created_by = u.user_id
    WHERE 1=1
";
$query .= scopeFilterSqlNullable('project', 'q');

$params = [];

if (!empty($status_filter)) {
    $query .= " AND q.status = ?";
    $params[] = $status_filter;
}

if ($customer_filter > 0) {
    $query .= " AND q.customer_id = ?";
    $params[] = $customer_filter;
}

if (!empty($date_from)) {
    $query .= " AND q.order_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND q.order_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY q.order_date DESC, q.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get customers for filter
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$today_ts = strtotime(date('Y-m-d'));
$week_ts  = strtotime('+7 days', $today_ts);
$stats = [
    'total_quotes'  => count($quotations),
    'pending'       => count(array_filter($quotations, fn($q) => $q['status'] == 'pending')),
    'approved'      => count(array_filter($quotations, fn($q) => $q['status'] == 'approved')),
    'declined'      => count(array_filter($quotations, fn($q) => $q['status'] == 'cancelled')),
    'converted'     => count(array_filter($quotations, fn($q) => !empty($q['converted_to_so_id']))),
    'total_value'   => array_sum(array_column($quotations, 'grand_total')),
    'expired'       => count(array_filter($quotations, fn($q) => !empty($q['quote_valid_until']) && strtotime($q['quote_valid_until']) < $today_ts && !in_array($q['status'], ['approved','cancelled']))),
    'expiring_soon' => count(array_filter($quotations, fn($q) => !empty($q['quote_valid_until']) && strtotime($q['quote_valid_until']) >= $today_ts && strtotime($q['quote_valid_until']) <= $week_ts && !in_array($q['status'], ['approved','cancelled']))),
];
$win_denom = $stats['approved'] + $stats['declined'];
$stats['win_rate'] = $win_denom > 0 ? round($stats['approved'] / $win_denom * 100) : null;

// Workflow permissions (review / approve) — assigned per role in user_roles.php.
$can_review  = canReview('sales_orders');
$can_approve = canApprove('sales_orders');

?>
<style>
.quotations-dashboard { background: #ffffff; min-height: 100vh; }
.custom-stat-card { background-color: #d1e7dd !important; border-color: #badbcc !important; transition: transform 0.2s; border-radius: 12px; }
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, .custom-stat-card small { color: #0f5132 !important; font-weight: 600; }
.stats-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-right: 1.25rem; background: rgba(15, 81, 50, 0.1); color: #0f5132 !important; }
.bg-success-soft { background-color: rgba(25, 135, 84, 0.1) !important; }

/* Status Badge Styles */
.status-completed { color: #157347 !important; background-color: #d1e7dd !important; }
.status-warning { color: #997404 !important; background-color: #fff3cd !important; }
.status-danger { color: #bb2d3b !important; background-color: #f8d7da !important; }
.status-secondary { color: #41464b !important; background-color: #e2e3e5 !important; }
.status-info { color: #055160 !important; background-color: #cff4fc !important; }

    @media print {
        body { background: white !important; }
        .quotations-dashboard { background: white !important; padding: 0 !important; }
        .d-print-none { display: none !important; }
        .table-responsive { overflow: visible !important; }
        table { width: 100% !important; border-collapse: collapse !important; }
        th, td { border: 1px solid #dee2e6 !important; padding: 8px !important; }
        th { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; }
        
        .flex-nowrap-print { display: flex !important; flex-wrap: nowrap !important; }
        .col-3-print { width: 25% !important; flex: 0 0 25% !important; max-width: 25% !important; }
        .custom-stat-card { border: 1px solid #badbcc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
</style>

<div class="quotations-dashboard p-4 p-md-5" style="background: #ffffff; min-height: 100vh;">
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
            <h3 class="text-uppercase text-dark fw-bold mb-1">QUOTATIONS REPORT</h3>
            <p class="text-dark">Printed on <?= date('F j, Y h:i A') ?></p>
        </div>
        <hr>
    </div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Quotations</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-5 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-1">
                        <i class="bi bi-file-earmark-text text-primary me-2"></i>Quotations
                    </h2>
                    <p class="text-muted mb-0">Manage customer quotations and estimates</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('quotation_create') ?>" class="btn btn-primary btn-sm shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> New Quotation
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Scrollable Content Wrapper -->
    <div>
    <!-- Statistics Cards -->
    <div class="row g-4 mb-5 flex-nowrap-print">
        <div class="col-md-3 col-3-print">
            <div class="card custom-stat-card h-100 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon d-print-none"><i class="bi bi-file-earmark-text"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= $stats['total_quotes'] ?></h4>
                        <small class="text-uppercase small fw-bold">Total Quotes</small>
                        <?php if ($stats['converted'] > 0): ?>
                        <div style="font-size:0.7rem;margin-top:2px;color:#198754;"><i class="bi bi-check2-circle me-1"></i><?= $stats['converted'] ?> converted</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-3-print">
            <div class="card custom-stat-card h-100 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon d-print-none"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= $stats['pending'] ?></h4>
                        <small class="text-uppercase small fw-bold">Pending</small>
                        <?php if ($stats['expiring_soon'] > 0): ?>
                        <div style="font-size:0.7rem;margin-top:2px;color:#856404;"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= $stats['expiring_soon'] ?> expiring soon</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-3-print">
            <div class="card custom-stat-card h-100 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon d-print-none"><i class="bi bi-trophy"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= $stats['win_rate'] !== null ? $stats['win_rate'] . '%' : 'N/A' ?></h4>
                        <small class="text-uppercase small fw-bold">Win Rate</small>
                        <div style="font-size:0.7rem;margin-top:2px;color:#0f5132;"><?= $stats['approved'] ?> approved · <?= $stats['declined'] ?> declined</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-3-print">
            <div class="card custom-stat-card h-100 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon d-print-none"><i class="bi bi-cash-stack"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?= number_format($stats['total_value'], 2) ?></h4>
                        <small class="text-uppercase small fw-bold">Total Quote Value</small>
                        <?php if ($stats['expired'] > 0): ?>
                        <div style="font-size:0.7rem;margin-top:2px;color:#dc3545;"><i class="bi bi-x-circle me-1"></i><?= $stats['expired'] ?> expired</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Card -->
    <div class="card mb-5 border-0 shadow-sm d-print-none">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filters & Search</h6>
        </div>
        <div class="card-body">
            <form action="" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Customer</label>
                    <select name="customer" id="quoteCustomerFilter" class="form-select border-0 bg-light select2-static">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['customer_id'] ?>" <?= $customer_filter == $c['customer_id'] ? 'selected' : '' ?>>
                                <?= safe_output($c['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select name="status" class="form-select border-0 bg-light">
                        <option value="">All Statuses</option>
                        <option value="draft" <?= $status_filter == 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="reviewed" <?= $status_filter == 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                        <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Declined</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Date From</label>
                    <input type="date" name="date_from" class="form-control border-0 bg-light" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Date To</label>
                    <input type="date" name="date_to" class="form-control border-0 bg-light" value="<?= $date_to ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm shadow-sm px-4 fw-bold me-2">
                        <i class="bi bi-filter me-1"></i> Apply
                    </button>
                    <a href="quotations.php" class="btn btn-outline-secondary btn-sm px-4">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="mb-3 d-print-none text-start">
        <span class="badge bg-white text-dark border border-light-subtle px-3 py-2 fs-6 rounded-2 shadow-sm">
            <i class="bi bi-check-circle-fill text-success me-1"></i> Quotation Records
        </span>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="window.print()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
        </div>
    </div>

    <!-- Quotations Table Card -->
    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="quotationsTable" style="width:100%">
                <thead class="bg-light text-uppercase small fw-bold">
                    <tr>
                        <th style="width:50px;" class="ps-4">S/NO</th>
                        <th class="ps-4">Quotation #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Total Amount</th>
                        <th class="text-center">Expires</th>
                        <th class="text-center">Status</th>
                        <th class="text-center pe-4 d-print-none">Actions</th>
                    </tr>
                </thead>
                        <tbody>
                                <?php
                                $total_rows = count($quotations);
                                $sn = 1;
                                foreach ($quotations as $index => $q):
                                    $dropup   = ($total_rows > 3 && $index > $total_rows - 3) ? 'dropup' : '';
                                    $q_status = $q['status'] ?: 'pending';
                                    // Age badge — open quotes only
                                    $age_html = '';
                                    if (in_array($q_status, ['draft','pending','reviewed'])) {
                                        $q_age = (int)floor((time() - strtotime($q['order_date'])) / 86400);
                                        if ($q_age > 0) {
                                            if ($q_age < 7)       $al = $q_age . 'd ago';
                                            elseif ($q_age < 30)  $al = ceil($q_age / 7) . 'w ago';
                                            else                  $al = floor($q_age / 30) . 'mo ago';
                                            $ac = $q_age >= 14 ? 'danger' : ($q_age >= 7 ? 'warning' : 'secondary');
                                            $age_html = '<br><span class="badge bg-' . $ac . ' bg-opacity-10 text-' . $ac . '" style="font-size:0.65rem;">' . $al . '</span>';
                                        }
                                    }
                                    // Expiry cell HTML
                                    $vld = $q['quote_valid_until'] ?? '';
                                    if (!empty($vld)) {
                                        $dleft = (int)floor((strtotime($vld) - time()) / 86400);
                                        if (in_array($q_status, ['approved','cancelled'])) {
                                            $exp_html = '<small class="text-muted">' . date('d M Y', strtotime($vld)) . '</small>';
                                        } elseif ($dleft < 0) {
                                            $exp_html = '<span class="badge bg-danger" style="font-size:0.7rem;">Expired</span>';
                                        } elseif ($dleft === 0) {
                                            $exp_html = '<span class="badge bg-danger" style="font-size:0.7rem;">Today</span>';
                                        } elseif ($dleft <= 3) {
                                            $exp_html = '<span class="badge bg-danger" style="font-size:0.7rem;">In ' . $dleft . 'd</span>';
                                        } elseif ($dleft <= 7) {
                                            $exp_html = '<span class="badge bg-warning text-dark" style="font-size:0.7rem;">In ' . $dleft . 'd</span>';
                                        } else {
                                            $exp_html = '<small>' . date('d M Y', strtotime($vld)) . '</small>';
                                        }
                                    } else {
                                        $exp_html = '<span class="text-muted small">—</span>';
                                    }
                                ?>
                                    <tr>
                                        <td class="ps-4 text-muted small fw-bold"><?= $sn++ ?></td>
                                        <td class="ps-4 fw-bold text-primary"><?= safe_output($q['order_number']) ?></td>
                                        <td><?= date('d M, Y', strtotime($q['order_date'])) ?><?= $age_html ?></td>
                                        <td>
                                            <div class="fw-bold"><?= safe_output($q['customer_name']) ?></div>
                                            <?php if ($q['company_name']): ?>
                                                <small class="text-muted"><?= safe_output($q['company_name']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?= $q['total_items'] ?>
                                        </td>
                                        <td class="text-end fw-bold">
                                            <?= $q['currency'] ?> <?= number_format($q['grand_total'], 2) ?>
                                        </td>
                                        <td class="text-center"><?= $exp_html ?></td>
                                        <td class="text-center">
                                            <?php
                                            $status = $q_status;
                                            $status_classes = [
                                                'draft' => 'status-secondary',
                                                'pending' => 'status-warning',
                                                'reviewed' => 'status-info',
                                                'approved' => 'status-completed',
                                                'cancelled' => 'status-danger'
                                            ];
                                            $status_labels = [
                                                'draft' => 'Draft',
                                                'pending' => 'Pending',
                                                'reviewed' => 'Reviewed',
                                                'approved' => 'Approved',
                                                'cancelled' => 'Declined'
                                            ];
                                            $badgeClass = $status_classes[$status] ?? 'status-secondary';
                                            $label = $status_labels[$status] ?? ucfirst($status);
                                            ?>
                                            <span class="badge rounded-pill <?= $badgeClass ?> bg-opacity-10 py-2 px-3" style="min-width: 100px; color: currentcolor !important;"><?= strtoupper($label) ?></span>
                                            <?php if (!empty($q['converted_to_so_id'])): ?>
                                            <br><span class="badge bg-success bg-opacity-10 text-success mt-1" style="font-size:0.65rem;"><i class="bi bi-check2-circle me-1"></i>Converted</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center pe-4 d-print-none">
                                            <div class="btn-group <?= $dropup ?>">
                                                <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow">
                                                    <li>
                                                        <a class="dropdown-item" href="<?= getUrl('quotation_view') ?>?id=<?= $q['sales_order_id'] ?>">
                                                            <i class="bi bi-eye text-primary me-2"></i> View Details
                                                        </a>
                                                    </li>
                                                    <?php if ($status !== 'approved'): ?>
                                                    <li>
                                                        <a class="dropdown-item" href="<?= getUrl('quotation_edit') ?>?id=<?= $q['sales_order_id'] ?>">
                                                            <i class="bi bi-pencil text-info me-2"></i> Edit Quote
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <?php if ($status === 'pending' && $can_review): ?>
                                                    <li>
                                                        <a class="dropdown-item text-primary" href="javascript:void(0)" onclick="reviewQuotation(<?= $q['sales_order_id'] ?>)">
                                                            <i class="bi bi-clipboard-check me-2"></i> Mark as Reviewed
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <?php if ($status === 'reviewed' && $can_approve): ?>
                                                    <li>
                                                        <a class="dropdown-item text-success" href="javascript:void(0)" onclick="approveQuotation(<?= $q['sales_order_id'] ?>)">
                                                            <i class="bi bi-check2-circle me-2"></i> Approve
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <?php if ($status === 'approved' && empty($q['converted_to_so_id'])): ?>
                                                    <li>
                                                        <a class="dropdown-item text-success" href="javascript:void(0)" onclick="convertToOrder(<?= $q['sales_order_id'] ?>)">
                                                            <i class="bi bi-check-circle me-2"></i> Convert to Order
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <a class="dropdown-item" href="javascript:void(0)" onclick="printQuote(<?= $q['sales_order_id'] ?>)">
                                                            <i class="bi bi-printer text-secondary me-2"></i> Print PDF
                                                        </a>
                                                    </li>
                                                    <?php if ($status !== 'approved' && $status !== 'cancelled'): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-warning" href="javascript:void(0)" onclick="declineQuotation(<?= $q['sales_order_id'] ?>)">
                                                            <i class="bi bi-x-octagon me-2"></i> Decline
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <?php if ($status !== 'approved'): ?>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteQuote(<?= $q['sales_order_id'] ?>)">
                                                            <i class="bi bi-trash me-2"></i> Delete Quote
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    logReportAction('Viewed Quotations List', 'User viewed the list of customer quotations');

    // §UI-2 — DataTable for search + sort. paging:false keeps ALL rows in the
    // DOM so the existing copyTable()/exportExcel() (which read the table DOM)
    // still capture every row, not just one page.
    if (!$.fn.DataTable.isDataTable('#quotationsTable')) {
        $('#quotationsTable').DataTable({
            paging: false,
            responsive: false,
            scrollX: true,
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: { emptyTable: 'No quotations found.', zeroRecords: 'No matching quotations.' }
        });
    }

    // §UI-3 — DB-backed Customer filter as a searchable Select2.
    if ($('#quoteCustomerFilter').length && !$('#quoteCustomerFilter').hasClass('select2-hidden-accessible')) {
        $('#quoteCustomerFilter').select2({
            theme: 'bootstrap-5',
            placeholder: 'All Customers',
            allowClear: true,
            width: '100%'
        });
    }
});

function reviewQuotation(id) {
    Swal.fire({
        title: 'Mark as Reviewed?',
        text: 'Confirm that you have reviewed this quotation.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Reviewed'
    }).then((result) => {
        if (result.isConfirmed) {
            postWorkflow('<?= buildUrl('api/account/review_quotation.php') ?>', id, 'Submitting review...');
        }
    });
}

function approveQuotation(id) {
    Swal.fire({
        title: 'Approve Quotation?',
        text: 'Approving makes the quotation ready to convert into a sales order.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Approve',
        confirmButtonColor: '#10b981'
    }).then((result) => {
        if (result.isConfirmed) {
            postWorkflow('<?= buildUrl('api/account/approve_quotation.php') ?>', id, 'Approving...');
        }
    });
}

function declineQuotation(id) {
    Swal.fire({
        title: 'Decline Quotation?',
        text: 'This will mark the quotation as declined.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Decline',
        confirmButtonColor: '#d33'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('<?= buildUrl('api/account/update_quotation_status.php') ?>',
                { quotation_id: id, status: 'cancelled' },
                function(res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Declined', timer: 1500, showConfirmButton: false })
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }, 'json').fail(function() {
                    Swal.fire('Error', 'Communication with server failed', 'error');
                });
        }
    });
}

function postWorkflow(url, id, loadingTitle) {
    Swal.fire({ title: loadingTitle, didOpen: () => { Swal.showLoading(); } });
    $.post(url, { quotation_id: id }, function(res) {
        if (res.success) {
            Swal.fire({ icon: 'success', title: 'Done', text: res.message, timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Communication with server failed', 'error');
    });
}

function convertToOrder(id) {
    Swal.fire({
        title: 'Convert to Sales Order?',
        text: 'This will turn this quotation into an active sales order and remove it from the quotations list.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Convert',
        confirmButtonColor: '#10b981'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Converting...', didOpen: () => { Swal.showLoading(); } });
            
            $.post('<?= buildUrl('api/account/convert_quote_to_order.php') ?>', { id: id }, function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Converted!',
                        text: 'Quotation has been successfully converted to a Sales Order.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = '<?= getUrl('sales_orders') ?>';
                    });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function deleteQuote(id) {
    Swal.fire({
        title: 'Delete Quotation?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#d33'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl('api/account/delete_quotation.php') ?>',
                type: 'POST',
                data: { quotation_id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Deleted', 'Quotation removed successfully', 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                }
            });
        }
    });
}

function printQuote(id) {
    window.open('<?= getUrl('print_quotation') ?>?id=' + id, '_blank');
}

function copyTable() {
    let table = document.getElementById('quotationsTable');
    let range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    logReportAction('Copied Quotations Table', 'User copied quotations table to clipboard');
    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1500, showConfirmButton: false });
}

function exportExcel() {
    let table = document.getElementById('quotationsTable');
    let rows = table.querySelectorAll('tr');
    let csv = [];
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length - 1; j++) { // Skip actions column
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(row.join(','));
    }
    let csvContent = csv.join("\n");
    let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    let url = URL.createObjectURL(blob);
    let link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", "quotations_report.csv");
    document.body.appendChild(link);
    link.click();
    logReportAction('Exported Quotations', 'User exported quotations list to CSV');
}
</script>

<?php includeFooter(); ?>
