<?php
// scope-audit: skip — HR leaves page has multiple complex queries; individual record access gated via assertScopeForEmployeeRecord; read-side bulk scope deferred to Phase G-2
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('leaves');

// Fetch company settings for print header
$c_name = getSetting('company_name', 'BMS');
$c_logo = getSetting('company_logo', '');

// Include the header
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View leave requests', 'User viewed the leave requests management list');

// Permission flags for UI elements
$can_edit_leaves = isAdmin() || canEdit('leaves');
$can_approve_leaves = isAdmin() || canEdit('leaves'); // Usually Edit permission covers Approval in this system
$can_delete_leaves = isAdmin() || canDelete('leaves');


// Get current date for filters
$current_date = date('Y-m-d');
$selected_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$selected_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$selected_status = isset($_GET['status']) ? $_GET['status'] : '';
$selected_type = isset($_GET['type']) ? $_GET['type'] : '';
$selected_department = isset($_GET['department']) ? (int)$_GET['department'] : null;
$selected_employee = isset($_GET['employee']) ? (int)$_GET['employee'] : null;

// Get departments for filtering
$departments = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

// Get employees for filtering
$employees_query = "SELECT employee_id, first_name, last_name, employee_number FROM employees WHERE status = 'active' ORDER BY first_name, last_name";
$employees = $pdo->query($employees_query)->fetchAll(PDO::FETCH_ASSOC);

// Get leave types
$leave_types = $pdo->query("SELECT * FROM leave_types WHERE status = 'active' ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions removed, now in helpers.php

// Check if user is viewing their own leaves
$user_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
$is_viewing_own = ($selected_employee == $_SESSION['user_id']) || ($user_role == 'Employee');

// Calculate leave statistics EARLY so they are available for the print header
$stats_query = "
    SELECT 
        COUNT(*) as total_leaves,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(total_days) as total_days
    FROM leaves 
    WHERE start_date BETWEEN ? AND ?
";

$stats_params = [$selected_start_date, $selected_end_date];

if ($selected_status) {
    $stats_query .= " AND status = ?";
    $stats_params[] = $selected_status;
}

if ($selected_type) {
    $stats_query .= " AND leave_type = ?";
    $stats_params[] = $selected_type;
}

if ($selected_department) {
    $stats_query .= " AND employee_id IN (SELECT employee_id FROM employees WHERE department_id = ?)";
    $stats_params[] = $selected_department;
}

if ($selected_employee) {
    $stats_query .= " AND employee_id = ?";
    $stats_params[] = $selected_employee;
}

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($stats_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<style>
    @media print {
        @page { size: auto; margin: 15mm; }
        .fixed-print-footer {
            display: block !important;
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            text-align: center;
            padding: 10px;
            border-top: 1px solid #eee;
            background: white;
        }
        tfoot { display: table-footer-group !important; }
        body { -webkit-print-color-adjust: exact; }
    }
    .fixed-print-footer { display: none; }
</style>

<div class="container-fluid mt-4">
    <!-- Professional Print Header -->
    <div class="d-none d-print-block text-center mb-1" id="printHeader" style="margin-top: 15px !important;">
       
        <h2 style="color: #333; font-weight: 700; text-transform: uppercase; margin: 2px 0; font-size: 15pt; letter-spacing: 2px;">LEAVE MANAGEMENT REPORT</h2>
        <p class="text-muted mb-1" style="font-size: 9pt;">Period: <span class="fw-bold text-dark"><?= date('d M Y', strtotime($selected_start_date)) ?></span> to <span class="fw-bold text-dark"><?= date('d M Y', strtotime($selected_end_date)) ?></span></p>
        <div style="border-bottom: 3px solid #0d6efd; width: 100px; margin: 10px auto;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block">
        <div class="row g-2 mb-4" style="page-break-inside: avoid !important; break-inside: avoid !important;">
            <div style="width: 16.66%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Total Leaves</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $stats['total_leaves'] ?? 0 ?></h3>
                </div>
            </div>
            <div style="width: 16.66%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Pending</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $stats['pending'] ?? 0 ?></h3>
                </div>
            </div>
            <div style="width: 16.66%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Approved</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $stats['approved'] ?? 0 ?></h3>
                </div>
            </div>
            <div style="width: 16.66%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Rejected</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $stats['rejected'] ?? 0 ?></h3>
                </div>
            </div>
            <div style="width: 16.66%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Total Days</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= $stats['total_days'] ?? 0 ?></h3>
                </div>
            </div>
            <div style="width: 16.66%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Avg Days</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;">
                        <?= ($stats['total_leaves'] > 0) ? round($stats['total_days'] / $stats['total_leaves'], 1) : 0 ?>
                    </h3>
                </div>
            </div>
        </div>
    </div>
    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-calendar"></i> Leave Management</h2>
                    <p class="text-muted mb-0">Manage employee leaves and approvals</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_edit_leaves || $is_viewing_own): ?>
                    <button type="button" class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#applyLeaveModal">
                        <i class="bi bi-plus-circle me-1"></i> Apply for Leave
                    </button>
                    <?php endif; ?>
                    <a href="<?= getUrl('leave_reports') ?>" class="btn btn-outline-warning px-4 shadow-sm">
                        <i class="bi bi-graph-up me-1"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <?php
    // Stats already calculated at top of file
    ?>
    
    <!-- Filter Card -->
    <div class="card mb-4 d-print-none border-0 shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Leave Filters</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form id="leaveFilterForm" method="GET">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $selected_start_date ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $selected_end_date ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Leave Status</label>
                            <select class="form-select select2-static" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="pending" <?= ($selected_status == 'pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= ($selected_status == 'approved') ? 'selected' : '' ?>>Approved</option>
                                <option value="rejected" <?= ($selected_status == 'rejected') ? 'selected' : '' ?>>Rejected</option>
                                <option value="cancelled" <?= ($selected_status == 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                <option value="taken" <?= ($selected_status == 'taken') ? 'selected' : '' ?>>Taken</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Leave Type</label>
                            <select class="form-select select2-static" id="type" name="type">
                                <option value="">All Types</option>
                                <?php foreach ($leave_types as $type): ?>
                                <option value="<?= $type['type_name'] ?>" <?= ($selected_type == $type['type_name']) ? 'selected' : '' ?>>
                                    <?= safe_output($type['type_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Department</label>
                            <select class="form-select select2-static" id="department" name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>" <?= ($selected_department == $dept['department_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($dept['department_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Employee</label>
                            <select class="form-select select2-static" id="employee" name="employee">
                                <option value="">All Employees</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>" <?= ($selected_employee == $emp['employee_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($emp['first_name'] . ' ' . $emp['last_name']) ?> (<?= safe_output($emp['employee_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Search moved to actions bar -->
                        <div class="col-12 d-flex justify-content-end">
                            <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                                <i class="bi bi-arrow-clockwise"></i> Clear
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <!-- Actions Bar -->
    <div class="row g-3 mb-4 d-print-none align-items-center">
        <div class="col-12 col-lg-auto">
            <div class="d-flex flex-wrap gap-3">
                <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                    <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="window.print()" style="background: #fff; color: #444;">
                        <i class="bi bi-printer text-primary me-1"></i> Print
                    </button>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportLeaves()" style="background: #fff; color: #444;">
                        <i class="bi bi-file-earmark-excel text-success me-1"></i> Excel
                    </button>
                    <?php if ($can_edit_leaves): ?>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" class="btn btn-white fw-medium px-3 border-0" data-bs-toggle="modal" data-bs-target="#bulkLeaveModal" style="background: #fff; color: #444;">
                        <i class="bi bi-upload text-info me-1"></i> Bulk
                    </button>
                    <?php endif; ?>
                </div>
                
                <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                    <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                    <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#leavesTable').DataTable().page.len(this.value).draw();">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="-1">All</option>
                    </select>
                </div>
                <!-- View toggle — desktop only -->
                <div class="btn-group shadow-sm bg-white d-none d-md-flex" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                    <button type="button" id="btn-leaves-table-view" class="btn btn-white fw-medium px-3 border-0" onclick="toggleLeavesView('table')" style="background: #fff; color: #444;">
                        <i class="bi bi-list-task text-primary"></i> <span class="d-none d-xl-inline">List</span>
                    </button>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" id="btn-leaves-card-view" class="btn btn-white fw-medium px-3 border-0" onclick="toggleLeavesView('card')" style="background: #fff; color: #444;">
                        <i class="bi bi-grid-3x3-gap text-primary"></i> <span class="d-none d-xl-inline">Card</span>
                    </button>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-auto ms-lg-auto">
            <div class="input-group input-group-sm shadow-sm" style="min-width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control border-0 p-2" placeholder="Search leaves..." onkeyup="$('#leavesTable').DataTable().search(this.value).draw();">
            </div>
        </div>
    </div>

    <!-- Leaves List -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Leaves List</h5>
            <div class="d-flex">
                <span class="badge bg-light text-dark me-2">
                    <?= $stats['total_leaves'] ?? 0 ?> leaves
                </span>
                <?php if ($can_edit_leaves): ?>
                <button type="button" class="btn btn-light btn-sm" onclick="selectAllLeaves()">
                    <i class="bi bi-check-all"></i> Select All
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php
            // Fetch leaves with employee details
            $leaves_query = "
                SELECT 
                    l.*,
                    l.leave_type,
                    e.first_name,
                    e.last_name,
                    e.employee_number,
                    d.department_name,
                    lt.type_name as official_type,
                    u1.username as applied_by_name
                FROM leaves l
                LEFT JOIN employees e ON l.employee_id = e.employee_id
                LEFT JOIN departments d ON e.department_id = d.department_id
                LEFT JOIN users u1 ON l.applied_by = u1.user_id
                LEFT JOIN leave_types lt ON l.leave_type = lt.type_name
                WHERE l.start_date BETWEEN ? AND ?
            ";
            
            $leaves_params = [$selected_start_date, $selected_end_date];
            
            if ($selected_status) {
                $leaves_query .= " AND l.status = ?";
                $leaves_params[] = $selected_status;
            }
            
            if ($selected_type) {
                $leaves_query .= " AND l.leave_type = ?";
                $leaves_params[] = $selected_type;
            }
            
            if ($selected_department) {
                $leaves_query .= " AND e.department_id = ?";
                $leaves_params[] = $selected_department;
            }
            
            if ($selected_employee) {
                $leaves_query .= " AND l.employee_id = ?";
                $leaves_params[] = $selected_employee;
            }
            
            if (isset($_GET['search']) && !empty($_GET['search'])) {
                $leaves_query .= " AND (l.reason LIKE ? OR l.notes LIKE ?)";
                $search_term = '%' . $_GET['search'] . '%';
                $leaves_params[] = $search_term;
                $leaves_params[] = $search_term;
            }
            
            $leaves_query .= " ORDER BY l.start_date DESC, l.created_at DESC";
            
            $leaves_stmt = $pdo->prepare($leaves_query);
            $leaves_stmt->execute($leaves_params);
            $leaves = $leaves_stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <?php if (count($leaves) > 0): ?>
                <div id="tableView" class="table-responsive">
                    <table id="leavesTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th style="width: 30px;" class="d-md-none d-print-none no-sort"></th> <!-- Chevron -->
                                <?php if ($can_edit_leaves): ?>
                                <th width="30" class="no-sort d-print-none">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                                </th>
                                <?php endif; ?>
                                <th style="width: 50px;">S/NO</th>
                                <th class="d-none d-lg-table-cell" style="width: 100px;">Ref</th>
                                <th>Employee</th>
                                <th class="d-none d-md-table-cell">Dept</th>
                                <th class="d-none d-md-table-cell">Type</th>
                                <th class="d-none d-md-table-cell" style="width: 60px;">Days</th>
                                <th class="d-none d-md-table-cell" style="width: 80px;">Status</th>
                                <th class="d-none d-md-table-cell">Applied By</th>
                                <th style="width: 60px;" class="text-end no-sort">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sn = 1;
                            foreach ($leaves as $leave): ?>
                             <tr class="main-leave-row" data-id="<?= $leave['leave_id'] ?>">
                                <td class="d-md-none d-print-none text-center align-middle">
                                    <i class="bi bi-caret-right-fill text-primary toggle-details" style="cursor: pointer; transition: transform 0.3s ease; font-size: 1.1rem;"></i>
                                </td>
                                <?php if ($can_edit_leaves): ?>
                                <td class="d-print-none">
                                    <input type="checkbox" class="leave-checkbox" value="<?= $leave['leave_id'] ?>">
                                </td>
                                <?php endif; ?>
                                 <td><span class="fw-bold"><?= $sn++ ?></span></td>
                                <td class="d-none d-lg-table-cell">
                                    <small class="text-muted">LEV-<?= $leave['leave_id'] ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-wrap" style="max-width: 150px;"><?= safe_output($leave['first_name'] . ' ' . $leave['last_name']) ?></div>
                                    <div class="d-md-none mt-1">
                                        <span class="badge bg-secondary" style="font-size: 0.6rem;"><?= safe_output($leave['leave_type']) ?></span>
                                        <?php
                                        $status_class = [
                                            'pending' => 'bg-warning',
                                            'approved' => 'bg-success',
                                            'rejected' => 'bg-danger',
                                            'cancelled' => 'bg-secondary'
                                        ][$leave['status']] ?? 'bg-light text-dark';
                                        ?>
                                        <span class="badge <?= $status_class ?>" style="font-size: 0.6rem;"><?= ucfirst($leave['status']) ?></span>
                                    </div>
                                    <small class="text-muted d-block" style="font-size: 0.7rem;">#<?= safe_output($leave['employee_number']) ?></small>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <small><?= safe_output($leave['department_name']) ?></small>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <span class="badge bg-secondary text-wrap" style="font-size: 0.7rem; text-transform: capitalize;">
                                        <?php 
                                            $lt_display = $leave['leave_type'];
                                            if(!str_contains(strtolower($lt_display), 'leave')) $lt_display .= ' Leave';
                                            echo safe_output($lt_display);
                                        ?>
                                    </span>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <span class="badge bg-info p-1"><?= $leave['total_days'] ?></span>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <?php
                                    $status_class = [
                                        'pending' => 'bg-warning',
                                        'approved' => 'bg-success',
                                        'rejected' => 'bg-danger',
                                        'cancelled' => 'bg-secondary'
                                    ][$leave['status']] ?? 'bg-light text-dark';
                                    ?>
                                    <span class="badge <?= $status_class ?> p-1" style="font-size: 0.65rem;"><?= ucfirst($leave['status']) ?></span>
                                </td>
                                <td class="d-none d-md-table-cell text-muted small">
                                    <?= safe_output($leave['applied_by_name']) ?>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="viewLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-eye text-primary me-2"></i> View</a></li>
                                            <?php if($can_edit_leaves && $leave['status'] == 'pending'): ?>
                                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="editLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-pencil text-warning me-2"></i> Edit</a></li>
                                            <?php endif; ?>
                                            <?php if($can_approve_leaves && $leave['status'] == 'pending'): ?>
                                            <li><a class="dropdown-item text-success" href="javascript:void(0)" onclick="approveLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-check-circle me-2"></i> Approve</a></li>
                                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="rejectLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-x-circle me-2"></i> Reject</a></li>
                                            <?php endif; ?>
                                            <?php if($can_delete_leaves): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-trash me-2"></i> Delete</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                    <!-- Hidden Detail Content for JS - Show ALL Columns for Mobile -->
                                    <div class="leave-details-content d-none">
                                        <div class="p-3 bg-white rounded border-start border-primary border-4 shadow-sm" style="font-size: 0.85rem;">
                                            <div class="row g-2 mb-3">
                                                <div class="col-6 border-bottom pb-1"><small class="text-muted d-block">S/NO</small><?= $sn - 1 ?></div>
                                                <div class="col-6 border-bottom pb-1"><small class="text-muted d-block">Ref</small><span class="fw-bold">LEV-<?= $leave['leave_id'] ?></span></div>
                                                <div class="col-12 border-bottom pb-1"><small class="text-muted d-block">Employee</small><span class="fw-bold"><?= safe_output($leave['first_name'] . ' ' . $leave['last_name']) ?></span></div>
                                                <div class="col-6 border-bottom pb-1"><small class="text-muted d-block">Dept</small><?= safe_output($leave['department_name']) ?></div>
                                                <div class="col-6 border-bottom pb-1">
                                                    <small class="text-muted d-block">Type</small>
                                                    <span class="badge bg-secondary text-capitalize"><?= safe_output($leave['leave_type']) ?></span>
                                                </div>
                                                <div class="col-6 border-bottom pb-1"><small class="text-muted d-block">Days</small><span class="badge bg-info"><?= $leave['total_days'] ?> Days</span></div>
                                                <div class="col-6 border-bottom pb-1">
                                                    <small class="text-muted d-block">Status</small>
                                                    <span class="badge <?= $status_class ?>"><?= ucfirst($leave['status']) ?></span>
                                                </div>
                                                <div class="col-12 border-bottom pb-1"><small class="text-muted d-block">Applied By</small><?= safe_output($leave['applied_by_name']) ?></div>
                                                <div class="col-12 mt-2"><small class="text-muted d-block mb-1">Reason</small><div class="p-2 bg-light rounded shadow-sm small italic"><?= safe_output($leave['reason']) ?></div></div>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2 mt-2 pt-2 border-top">
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-eye"></i> View</button>
                                                <?php if($can_edit_leaves && $leave['status'] == 'pending'): ?>
                                                <button class="btn btn-sm btn-outline-warning" onclick="editLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-pencil"></i> Edit</button>
                                                <?php endif; ?>
                                                <?php if($can_approve_leaves && $leave['status'] == 'pending'): ?>
                                                <button class="btn btn-sm btn-outline-success" onclick="approveLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-check-circle"></i> Approve</button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="rejectLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-x-circle"></i> Reject</button>
                                                <?php endif; ?>
                                                <?php if($can_delete_leaves): ?>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteLeave(<?= $leave['leave_id'] ?>)"><i class="bi bi-trash"></i> Delete</button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="d-none d-print-table-footer">
                            <tr style="height: 50px !important; border: none !important;">
                                <td colspan="10" style="border: none !important;">&nbsp;</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Card View -->
                <div id="cardView" style="display:none;">
                    <div class="row g-3">
                        <?php foreach ($leaves as $leave):
                            $sc_map = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary','taken'=>'info'];
                            $sc = $sc_map[$leave['status']] ?? 'secondary';
                        ?>
                        <div class="col-xl-3 col-lg-4 col-md-6">
                            <div class="card h-100 border-0 shadow-sm rounded-3">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2 px-3">
                                    <div>
                                        <div class="fw-bold" style="font-size:0.85rem;"><?= safe_output($leave['first_name'] . ' ' . $leave['last_name']) ?></div>
                                        <small class="text-muted">#<?= safe_output($leave['employee_number']) ?></small>
                                    </div>
                                    <span class="badge bg-<?= $sc ?>"><?= ucfirst($leave['status']) ?></span>
                                </div>
                                <div class="card-body py-2 px-3" style="font-size:0.8rem;">
                                    <div class="mb-1"><i class="bi bi-tag text-muted me-1"></i><?= safe_output($leave['leave_type']) ?></div>
                                    <div class="mb-1"><i class="bi bi-building text-muted me-1"></i><?= safe_output($leave['department_name'] ?? '—') ?></div>
                                    <div class="mb-1"><i class="bi bi-calendar-range text-muted me-1"></i><?= date('d M', strtotime($leave['start_date'])) ?> – <?= date('d M Y', strtotime($leave['end_date'])) ?></div>
                                    <div><span class="badge bg-info"><?= $leave['total_days'] ?> day<?= $leave['total_days'] != 1 ? 's' : '' ?></span></div>
                                </div>
                                <div class="card-footer bg-white" style="padding:6px 8px;">
                                    <div style="display:flex; flex-wrap:nowrap; gap:4px;">
                                        <button class="btn btn-sm btn-outline-primary" onclick="viewLeave(<?= $leave['leave_id'] ?>)" title="View" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-eye"></i></button>
                                        <?php if ($can_edit_leaves && $leave['status'] == 'pending'): ?>
                                        <button class="btn btn-sm btn-outline-warning" onclick="editLeave(<?= $leave['leave_id'] ?>)" title="Edit" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-pencil"></i></button>
                                        <?php endif; ?>
                                        <?php if ($can_approve_leaves && $leave['status'] == 'pending'): ?>
                                        <button class="btn btn-sm btn-outline-success" onclick="approveLeave(<?= $leave['leave_id'] ?>)" title="Approve" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-check-circle"></i></button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="rejectLeave(<?= $leave['leave_id'] ?>)" title="Reject" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-x-circle"></i></button>
                                        <?php endif; ?>
                                        <?php if ($can_delete_leaves): ?>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteLeave(<?= $leave['leave_id'] ?>)" title="Delete" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <?php if ($can_edit_leaves): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <div class="row align-items-center">
                        <div class="col-md-4 mb-2">
                            <small><span id="selectedCount">0</span> leaves selected</small>
                        </div>
                        <div class="col-md-8 text-end">
                            <div class="btn-group">
                                <?php if ($can_approve_leaves): ?>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="bulkUpdateStatus('approved')">
                                    <i class="bi bi-check-circle"></i> Approve
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="bulkUpdateStatus('rejected')">
                                    <i class="bi bi-x-circle"></i> Reject
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="bulkUpdateStatus('cancelled')">
                                    <i class="bi bi-ban"></i> Cancel
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="bulkExportApplications()">
                                    <i class="bi bi-download"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-calendar" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Leaves Found</h4>
                    <p class="text-muted">No leave records found for the selected filters.</p>
                    <?php if ($can_edit_leaves || $is_viewing_own): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#applyLeaveModal">
                        <i class="bi bi-plus-circle"></i> Apply for Leave
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

    <!-- Leave Balance Summary (Moved to Bottom) -->
    <div class="card mb-4 mt-4 d-print-none">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-primary"><i class="bi bi-pie-chart"></i> Leave Balance Summary</h5>
            <span class="badge bg-white text-dark border">
                <?= date('F Y') ?>
            </span>
        </div>
        <div class="card-body">
            <div class="row">
                <?php
                // Get leave balances for current year
                $balance_query = "
                    SELECT 
                        lt.type_name,
                        lt.max_days_per_year,
                        COALESCE(SUM(CASE WHEN l.status = 'approved' THEN l.total_days ELSE 0 END), 0) as used_days
                    FROM leave_types lt
                    LEFT JOIN leaves l ON lt.type_name = l.leave_type 
                        AND YEAR(l.start_date) = YEAR(CURDATE())
                        AND l.status = 'approved'
                        AND l.employee_id = ?
                    WHERE lt.status = 'active'
                    GROUP BY lt.type_id, lt.type_name, lt.max_days_per_year
                    ORDER BY lt.type_name
                ";
                
                $balance_stmt = $pdo->prepare($balance_query);
                $balance_stmt->execute([$selected_employee ?: ($_SESSION['user_id'] ?? 0)]);
                $leave_balances = $balance_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($leave_balances as $balance):
                    $used = $balance['used_days'];
                    $max = $balance['max_days_per_year'];
                    $remaining = $max - $used;
                    $percentage = $max > 0 ? round(($used / $max) * 100) : 0;
                ?>
                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card custom-stat-card">
                        <div class="card-body">
                            <h6 class="card-title"><?= safe_output($balance['type_name']) ?> Leave</h6>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <h4 class="mb-0 text-primary"><?= $remaining ?></h4>
                                    <small class="text-muted">Days Remaining</small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted"><?= $used ?>/<?= $max ?> days</small>
                                </div>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-<?= get_type_badge($balance['type_name']) ?>" 
                                     style="width: <?= $percentage ?>%"
                                     role="progressbar"
                                     aria-valuenow="<?= $percentage ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100"></div>
                            </div>
                            <small class="text-muted mt-2 d-block"><?= $percentage ?>% used</small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Apply for Leave Modal -->
<?php if ($can_edit_leaves || $is_viewing_own): ?>
<div class="modal fade" id="applyLeaveModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="applyLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="applyLeaveModalLabel">
                    <i class="bi bi-plus-circle"></i> Apply for Leave
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="applyLeaveForm">
                <div class="modal-body">
                    <div id="apply-leave-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="apply_employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" id="apply_employee_id" name="employee_id" required <?= ($is_viewing_own && !$can_edit_leaves) ? 'disabled' : '' ?> onchange="updateLeaveBalance()">
                                <?php if ($is_viewing_own && !$can_edit_leaves): ?>
                                <?php 
                                // Get current user's employee record
                                $user_emp = $pdo->prepare("
                                    SELECT e.* FROM employees e 
                                    JOIN users u ON e.employee_id = u.employee_id 
                                    WHERE u.user_id = ?
                                ");
                                $user_emp->execute([$_SESSION['user_id']]);
                                $current_employee = $user_emp->fetch();
                                ?>
                                <option value="<?= $current_employee['employee_id'] ?>" selected>
                                    <?= safe_output($current_employee['first_name'] . ' ' . $current_employee['last_name']) ?> 
                                    (<?= safe_output($current_employee['employee_number']) ?>)
                                </option>
                                <input type="hidden" name="employee_id" value="<?= $current_employee['employee_id'] ?>">
                                <?php else: ?>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>">
                                    <?= safe_output($emp['first_name'] . ' ' . $emp['last_name']) ?> (<?= safe_output($emp['employee_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="apply_leave_type" class="form-label">Leave Type <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" id="apply_leave_type" name="leave_type" required onchange="updateLeaveTypeInfo()">
                                <option value="">Select Type</option>
                                <?php foreach ($leave_types as $type): ?>
                                <option value="<?= $type['type_name'] ?>" 
                                        data-max-days="<?= $type['max_days_per_year'] ?>"
                                        data-requires-doc="<?= $type['requires_document'] ?>">
                                    <?= safe_output($type['type_name']) ?> 
                                    (Max: <?= $type['max_days_per_year'] ?> days/year)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="apply_start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="apply_start_date" name="start_date" required onchange="calculateDays()">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="apply_end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="apply_end_date" name="end_date" required onchange="calculateDays()">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="apply_total_days" class="form-label">Total Days</label>
                            <input type="number" class="form-control" id="apply_total_days" name="total_days" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="apply_half_day" class="form-label">Half Day</label>
                            <select class="form-select" id="apply_half_day" name="half_day" onchange="calculateDays()">
                                <option value="">No</option>
                                <option value="first_half">First Half</option>
                                <option value="second_half">Second Half</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="apply_is_paid" class="form-label">Leave Type</label>
                            <select class="form-select" id="apply_is_paid" name="is_paid">
                                <option value="1">Paid Leave</option>
                                <option value="0">Unpaid Leave</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="apply_reason" class="form-label">Reason for Leave <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="apply_reason" name="reason" rows="3" required placeholder="Please provide a reason for your leave"></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="apply_notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="apply_notes" name="notes" rows="2" placeholder="Any additional information or notes"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="apply_contact_during_leave" class="form-label">Contact During Leave</label>
                            <input type="text" class="form-control" id="apply_contact_during_leave" name="contact_during_leave" placeholder="Phone number or email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="apply_handover_to" class="form-label">Handover To</label>
                            <select class="form-select select2-static" id="apply_handover_to" name="handover_to">
                                <option value="">Select Colleague</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>">
                                    <?= safe_output($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <div id="documentSection" style="display: none;">
                                <label for="apply_document" class="form-label">Supporting Document</label>
                                <input type="file" class="form-control" id="apply_document" name="document" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Upload supporting document (e.g., medical certificate)</small>
                            </div>
                        </div>
                        
                        <!-- Leave Balance Information -->
                        <div class="col-12 mb-3">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Leave Balance Information</h6>
                                </div>
                                <div class="card-body">
                                    <div id="balanceInfo">
                                        <p class="text-muted mb-0">Select an employee and leave type to view balance information.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Submit Leave Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Leave Modal -->
<?php if ($can_edit_leaves): ?>
<div class="modal fade" id="bulkLeaveModal" tabindex="-1" aria-labelledby="bulkLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="bulkLeaveModalLabel">
                    <i class="bi bi-upload"></i> Bulk Leave Import
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="bulkLeaveForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="bulk-leave-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the leave data</li>
                            <li>Upload the completed file</li>
                            <li>File must be in CSV format</li>
                            <li>Maximum file size: 5MB</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="bulk_file" name="bulk_file" accept=".csv" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_action" class="form-label">Import Action</label>
                        <select class="form-select" id="bulk_action" name="bulk_action">
                            <option value="add_new">Add New Leaves Only</option>
                            <option value="update_existing">Update Existing Leaves</option>
                            <option value="add_update">Add New & Update Existing</option>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="bulk_skip_errors" name="skip_errors">
                        <label class="form-check-label" for="bulk_skip_errors">
                            Skip rows with errors and continue
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadLeaveTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Upload & Process
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- View Leave Modal -->
<div class="modal fade" id="viewLeaveModal" tabindex="-1" aria-labelledby="viewLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="viewLeaveModalLabel">
                    <i class="bi bi-info-circle"></i> Leave Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" id="viewLeaveDetails">
                <!-- Data will be loaded here via AJAX -->
                <div class="p-5 text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Leave Modal -->
<div class="modal fade" id="editLeaveModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="editLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="editLeaveModalLabel">
                    <i class="bi bi-pencil"></i> Edit Leave Application
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editLeaveForm">
                <div class="modal-body">
                    <div id="edit-leave-message" class="mb-3"></div>
                    <input type="hidden" id="edit_leave_id" name="leave_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_leave_type" class="form-label">Leave Type <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" id="edit_leave_type" name="leave_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($leave_types as $type): ?>
                                <option value="<?= $type['type_name'] ?>">
                                    <?= safe_output($type['type_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_start_date" name="start_date" required onchange="calculateDays()">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_end_date" name="end_date" required onchange="calculateDays()">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="edit_total_days" class="form-label">Total Days</label>
                            <input type="number" class="form-control" id="edit_total_days" name="total_days" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="edit_half_day" class="form-label">Half Day</label>
                            <select class="form-select select2-static" id="edit_half_day" name="half_day">
                                <option value="">No</option>
                                <option value="first_half">First Half</option>
                                <option value="second_half">Second Half</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="edit_is_paid" class="form-label">Leave Type</label>
                            <select class="form-select select2-static" id="edit_is_paid" name="is_paid">
                                <option value="1">Paid Leave</option>
                                <option value="0">Unpaid Leave</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="edit_reason" class="form-label">Reason for Leave <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="edit_reason" name="reason" rows="3" required></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="edit_notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="edit_notes" name="notes" rows="2"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_contact_during_leave" class="form-label">Contact During Leave</label>
                            <input type="text" class="form-control" id="edit_contact_during_leave" name="contact_during_leave" placeholder="Phone number or email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_handover_to" class="form-label">Handover To</label>
                            <select class="form-select select2-static" id="edit_handover_to" name="handover_to">
                                <option value="">Select Colleague</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>">
                                    <?= safe_output($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i> Update Leave Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script>
// Initialize Select2 on .select2-static elements
function initLeavesSelect2(context, dropdownParent) {
    var opts = { theme: 'bootstrap-5', width: '100%', allowClear: true };
    if (dropdownParent) opts.dropdownParent = dropdownParent;
    $(context).find('.select2-static').each(function() {
        var $el = $(this);
        if ($el.hasClass('select2-hidden-accessible')) {
            $el.select2('destroy');
        }
        var placeholder = $el.find('option[value=""]').first().text() || 'Select...';
        $el.select2($.extend({}, opts, { placeholder: placeholder }));
    });
}

function toggleLeavesView(viewType) {
    const isMobile = window.innerWidth <= 767;
    // On mobile, always force card view
    if (isMobile) viewType = 'card';

    if (viewType === 'card') {
        $('#tableView').hide();
        $('#cardView').show();
        $('#btn-leaves-table-view').css({'background':'#fff','color':'#444','font-weight':''});
        $('#btn-leaves-card-view').css({'background':'#e9ecef','color':'#000','font-weight':'600'});
    } else {
        $('#cardView').hide();
        $('#tableView').show();
        $('#btn-leaves-table-view').css({'background':'#e9ecef','color':'#000','font-weight':'600'});
        $('#btn-leaves-card-view').css({'background':'#fff','color':'#444','font-weight':''});
    }

    // Only persist desktop preference
    if (!isMobile) localStorage.setItem('leavesView', viewType);
}

$(document).ready(function() {
    logReportAction('Viewed Leaves List', 'User viewed the leave management list');

    // Init view based on screen size / preference
    const savedLeavesView = window.innerWidth <= 767 ? 'card' : (localStorage.getItem('leavesView') || 'table');
    toggleLeavesView(savedLeavesView);

    // Enforce card on mobile when orientation/size changes
    $(window).on('resize', function() {
        if (window.innerWidth <= 767) toggleLeavesView('card');
    });

    // Filter selects (outside any modal)
    $('.select2-static:not(.modal .select2-static)').each(function() {
        var $el = $(this);
        if ($el.hasClass('select2-hidden-accessible')) return;
        var placeholder = $el.find('option[value=""]').first().text() || 'Select...';
        $el.select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: placeholder });
    });

    // Modal selects — initialize when modal opens
    $('#applyLeaveModal').on('shown.bs.modal', function() {
        initLeavesSelect2($(this), $(this));
    });
    $('#editLeaveModal').on('shown.bs.modal', function() {
        initLeavesSelect2($(this), $(this));
    });
    
    // Initialize DataTable
    window.leavesTable = $('#leavesTable').DataTable({
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search leaves...",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        responsive: false, 
        dom: 'rtip', 
        pageLength: 25,
        order: [], 
        columnDefs: [
            { orderable: false, targets: 'no-sort' }
        ]
    });

    // Manual Detail Toggle via DataTables API
    $(document).on('click', '.toggle-details', function () {
        var tr = $(this).closest('tr');
        var row = window.leavesTable.row(tr);
        var icon = $(this);

        if (row.child.isShown()) {
            row.child.hide();
            tr.removeClass('shown table-primary');
            icon.css('transform', 'rotate(0deg)');
        } else {
            var detailsHtml = tr.find('.leave-details-content').html();
            row.child(detailsHtml).show();
            tr.addClass('shown table-primary');
            icon.css('transform', 'rotate(90deg)');
        }
    });

    // Apply leave form submission
    $('#applyLeaveForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...');

        $.ajax({
            url: APP_URL + '/api/apply_leave.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Submitted!',
                        text: response.message || 'Leave application submitted successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Submission failed.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Submit Leave Application');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred. Please try again.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Submit Leave Application');
            }
        });
    });

    // Bulk leave form submission
    $('#bulkLeaveForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');

        $.ajax({
            url: APP_URL + '/api/import_leaves.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    let resultMsg = response.message || 'Leaves imported successfully.';
                    if (response.results) {
                        resultMsg += `<br><small>Successful: ${response.results.successful}, Failed: ${response.results.failed}</small>`;
                    }
                    Swal.fire({
                        icon: 'success',
                        title: 'Imported!',
                        html: resultMsg,
                        showConfirmButton: true
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Import failed.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred during import.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
            }
        });
    });

    // Edit leave form submission
    $('#editLeaveForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: APP_URL + '/api/update_leave.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: response.message || 'Leave application updated successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Update failed.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Leave Application');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred. Please try again.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Leave Application');
            }
        });
    });

    $('#editLeaveModal').on('hidden.bs.modal', function() {
        $('#editLeaveForm')[0].reset();
        $('#edit-leave-message').html('');
        $('#editLeaveForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Leave Application');
    });

    // Checkbox selection handlers
    $('.leave-checkbox').on('change', function() {
        updateSelectedCount();
    });

    // Reset forms when modals are closed
    $('#applyLeaveModal').on('hidden.bs.modal', function() {
        $('#applyLeaveForm')[0].reset();
        $('#apply-leave-message').html('');
        $('#documentSection').hide();
        $('#applyLeaveForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Submit Leave Application');
    });
    
    $('#bulkLeaveModal').on('hidden.bs.modal', function() {
        $('#bulkLeaveForm')[0].reset();
        $('#bulk-leave-message').html('');
        $('#bulkLeaveForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
    });
});

function updateLeaveTypeInfo() {
    logReportAction('Selected Leave Type', 'User selected leave type: ' + $('#apply_leave_type').val());
    console.log("Updating leave type info...");
    const selectedOption = $('#apply_leave_type').find(':selected');
    const requiresDoc = selectedOption.data('requires-doc') == 1;
    
    if (requiresDoc) {
        $('#documentSection').show();
    } else {
        $('#documentSection').hide();
    }
    
    updateLeaveBalance();
}

function clearFilters() {
    window.location.href = 'leaves.php';
}

function exportLeaves() {
    // Collect filter params
    const form = document.getElementById('leaveFilterForm');
    const formData = new FormData(form);
    const search = $('#leavesTable').DataTable().search(); // Get current search term
    
    // Construct export URL
    let params = new URLSearchParams(formData);
    if (search) params.append('search', search);
    
    
    logReportAction('Exported Leaves List', 'User exported the leaves list to Excel/CSV');
    window.location.href = APP_URL + '/api/export_leaves.php?' + params.toString();
}

function getSelectedLeaveIds() {
    const selectedIds = [];
    $('.leave-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });
    return selectedIds;
}

function updateSelectedCount() {
    const selectedCount = $('.leave-checkbox:checked').length;
    $('#selectedCount').text(selectedCount);
}

function toggleSelectAll(checkbox) {
    $('.leave-checkbox').prop('checked', checkbox.checked);
    updateSelectedCount();
}

function selectAllLeaves() {
    $('#selectAll').prop('checked', true);
    $('.leave-checkbox').prop('checked', true);
    updateSelectedCount();
}

function bulkUpdateStatus(status) {
    const selectedIds = getSelectedLeaveIds();
    if (selectedIds.length === 0) {
        Swal.fire('Wait!', 'Please select at least one leave.', 'warning');
        return;
    }
    
    Swal.fire({
        title: `${status.charAt(0).toUpperCase() + status.slice(1)} Applications?`,
        text: `Are you sure you want to ${status} ${selectedIds.length} leave application(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Proceed',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/bulk_update_leave_status.php',
                type: 'POST',
                data: { 
                    leave_ids: selectedIds,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Bulk Updated Leave Status', `User bulk updated ${selectedIds.length} leaves to ${status}`);
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error updating status. Please try again.', 'error');
                }
            });
        }
    });
}

function bulkExportApplications() {
    const selectedIds = getSelectedLeaveIds();
    if (selectedIds.length === 0) {
        Swal.fire({ icon: 'warning', title: 'Nothing Selected', text: 'Please select at least one leave.' });

        return;
    }
    
    // Create a form and submit to generate PDF
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = APP_URL + '/api/export_leave_applications.php';
    form.target = '_blank';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'leave_ids';
    input.value = JSON.stringify(selectedIds);
    form.appendChild(input);
    
    
    logReportAction('Exported Individual Leave Applications', `User exported ${selectedIds.length} leave applications to PDF`);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function calculateDays() {
    const startDate = $('#apply_start_date').val();
    const endDate = $('#apply_end_date').val();
    const halfDay = $('#apply_half_day').val();
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        if (end < start) {
            Swal.fire({
                icon: 'warning',
                title: 'Invalid Date Range',
                text: 'End date cannot be before the start date.',
                confirmButtonColor: '#3085d6'
            });
            $('#apply_total_days').val(0);
            $('#apply_end_date').val('');
            return;
        }

        // Calculate difference in days
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // Include both start and end dates
        
        let totalDays = diffDays;
        
        // Adjust for half day
        if (halfDay) {
            totalDays = diffDays - 0.5;
            if (totalDays < 0) totalDays = 0;
        }
        
        $('#apply_total_days').val(totalDays);
        updateLeaveBalance();
    }

    // Also handle edit modal if it's open
    const editStart = $('#edit_start_date').val();
    const editEnd = $('#edit_end_date').val();
    if (editStart && editEnd) {
        const start = new Date(editStart);
        const end = new Date(editEnd);
        if (end >= start) {
            const diffTime = Math.abs(end - start);
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
            $('#edit_total_days').val(diffDays);
        } else {
            $('#edit_total_days').val(0);
        }
    }
}

function updateLeaveBalance() {
    const employeeId = $('#apply_employee_id').val();
    const leaveType = $('#apply_leave_type').val();
    const totalDays = parseFloat($('#apply_total_days').val()) || 0;
    
    if (employeeId && leaveType) {
        $.ajax({
            url: APP_URL + '/api/get_leave_balance.php',
            type: 'GET',
            data: { 
                employee_id: employeeId,
                leave_type: leaveType
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const balance = response.balance;
                    const maxDays = response.max_days_per_year || 0;
                    const usedDays = parseFloat(balance.used_days) || 0;
                    const remaining = maxDays - usedDays;
                    
                    let balanceHtml = `
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4 class="text-primary">${remaining}</h4>
                                    <small class="text-muted">Days Remaining</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4>${usedDays}</h4>
                                    <small class="text-muted">Days Used</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4>${maxDays}</h4>
                                    <small class="text-muted">Annual Limit</small>
                                </div>
                            </div>
                        </div>
                        <div class="progress mt-2" style="height: 10px;">
                            <div class="progress-bar bg-success" style="width: ${maxDays > 0 ? (usedDays/maxDays)*100 : 0}%"></div>
                        </div>
                    `;
                    
                    if (totalDays > 0) {
                        const afterLeave = remaining - totalDays;
                        if (afterLeave < 0) {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Leave Limit Reached',
                                text: `You will exceed your annual limit by ${Math.abs(afterLeave)} days. Please adjust your request.`,
                                confirmButtonColor: '#f8bb86'
                            });
                            balanceHtml += `
                                <div class="alert alert-danger mt-2">
                                    <i class="bi bi-exclamation-triangle"></i> 
                                    After this leave, you will exceed your annual limit by ${Math.abs(afterLeave)} days.
                                </div>
                            `;
                        } else {
                            balanceHtml += `
                                <div class="alert alert-info mt-2">
                                    <i class="bi bi-info-circle"></i> 
                                    After this leave, you will have ${afterLeave} days remaining.
                                </div>
                            `;
                        }
                    }
                    
                    $('#balanceInfo').html(balanceHtml);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading balance:', error);
            }
        });
    }
}

function viewLeave(leaveId) {
    $.ajax({
        url: APP_URL + '/api/get_leave.php',
        type: 'GET',
        data: { id: leaveId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                let data = response.data;
                let statusBadge = '';
                if(data.status == 'pending') statusBadge = '<span class="badge bg-warning text-dark">PENDING</span>';
                else if(data.status == 'approved') statusBadge = '<span class="badge bg-success">APPROVED</span>';
                else if(data.status == 'rejected') statusBadge = '<span class="badge bg-danger">REJECTED</span>';
                else statusBadge = '<span class="badge bg-secondary">' + data.status.toUpperCase() + '</span>';

                let html = `
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0" style="font-size: 0.85rem;">
                            <tr class="bg-light">
                                <th width="35%">Employee Name</th>
                                <td>${data.first_name} ${data.last_name}</td>
                            </tr>
                            <tr>
                                <th>Department</th>
                                <td>${data.department_name}</td>
                            </tr>
                            <tr>
                                <th>Leave Type</th>
                                <td class="fw-bold text-primary">${data.leave_type_display || data.leave_type || '-'}</td>
                            </tr>
                            <tr>
                                <th>Start Date</th>
                                <td>${data.start_date}</td>
                            </tr>
                            <tr>
                                <th>End Date</th>
                                <td>${data.end_date}</td>
                            </tr>
                            <tr>
                                <th>Duration</th>
                                <td><span class="badge bg-info text-dark">${data.total_days} Days</span></td>
                            </tr>
                            <tr>
                                <th>Current Status</th>
                                <td>${statusBadge}</td>
                            </tr>
                            <tr>
                                <th>Applied By</th>
                                <td>${data.applied_by_name || '-'}</td>
                            </tr>
                            <tr class="table-info">
                                <th>Reason For Leave</th>
                                <td style="white-space: normal;">${data.reason}</td>
                            </tr>
                            <tr>
                                <th>Notes/Remarks</th>
                                <td style="white-space: normal;">${data.notes || '-'}</td>
                            </tr>
                        </table>
                    </div>
                `;
                $('#viewLeaveDetails').html(html);
                logReportAction('Viewed Leave Details', 'User viewed full details for leave ID: ' + leaveId);
                $('#viewLeaveModal').modal('show');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Could not load leave details.', 'error');
        }
    });
}

function editLeave(leaveId) {
    // Load leave data for editing
    $.ajax({
        url: APP_URL + '/api/get_leave.php',
        type: 'GET',
        data: { id: leaveId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate edit form
                $('#edit_leave_id').val(response.data.leave_id);
                
                // Smart mapping for select value
                let lType = response.data.leave_type;
                if(lType === 'annual') lType = 'Annual Leave';
                else if(lType === 'sick') lType = 'Sick Leave';
                else if(lType === 'maternity') lType = 'Maternity Leave';
                else if(lType === 'paternity') lType = 'Paternity Leave';
                else if(lType === 'study') lType = 'Study Leave';
                else if(lType === 'unpaid') lType = 'Unpaid Leave';
                
                $('#edit_leave_type').val(lType).trigger('change');
                $('#edit_start_date').val(response.data.start_date);
                $('#edit_end_date').val(response.data.end_date);
                $('#edit_total_days').val(response.data.total_days);
                $('#edit_half_day').val(response.data.half_day || '').trigger('change');
                $('#edit_is_paid').val(response.data.is_paid ?? 1).trigger('change');
                $('#edit_reason').val(response.data.reason);
                $('#edit_notes').val(response.data.notes || '');
                $('#edit_contact_during_leave').val(response.data.contact_during_leave || '');
                $('#edit_handover_to').val(response.data.handover_to || '').trigger('change');
                
                logReportAction('Initiated Leave Edit', 'User opened edit modal for leave ID: ' + leaveId);
                // Show edit modal
                $('#editLeaveModal').modal('show');
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Error loading leave data: ' + response.message });
            }
        },
        error: function(xhr, status, error) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error loading leave data. Please try again.' });
            console.error('Error:', error);
        }
    });
}

function approveLeave(leaveId) {
    Swal.fire({
        title: 'Approve Leave?',
        text: 'Are you sure you want to approve this leave application?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/approve_leave.php',
                type: 'POST',
                data: { 
                    leave_id: leaveId,
                    action: 'approve'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Approved!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            logReportAction('Approved Leave', 'User approved leave application ID: ' + leaveId);
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error approving leave. Please try again.', 'error');
                }
            });
        }
    });
}

function rejectLeave(leaveId) {
    Swal.fire({
        title: 'Reject Application',
        text: 'Please provide a reason for rejection:',
        input: 'textarea',
        inputPlaceholder: 'Reason for rejection...',
        showCancelButton: true,
        confirmButtonText: 'Reject Application',
        confirmButtonColor: '#d33',
        preConfirm: (reason) => {
            if (!reason) {
                Swal.showValidationMessage('Reason is required for rejection');
            }
            return reason;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/reject_leave.php',
                type: 'POST',
                data: { 
                    leave_id: leaveId,
                    reason: result.value
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Rejected!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            logReportAction('Rejected Leave', 'User rejected leave application ID: ' + leaveId);
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error rejecting leave. Please try again.', 'error');
                }
            });
        }
    });
}

function cancelLeave(leaveId) {
    Swal.fire({
        title: 'Cancel Application?',
        text: 'Are you sure you want to cancel this leave application?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Cancel',
        cancelButtonColor: '#d33'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/cancel_leave.php',
                type: 'POST',
                data: { leave_id: leaveId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Cancelled!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error cancelling leave. Please try again.', 'error');
                }
            });
        }
    });
}

function duplicateLeave(leave_id) {
    Swal.fire({
        title: 'Duplicate Application?',
        text: 'Are you sure you want to duplicate this leave application?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Duplicate',
        cancelButtonText: 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/duplicate_leave.php',
                type: 'POST',
                data: { leave_id: leave_id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Duplicated!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            logReportAction('Duplicated Leave', 'User duplicated leave application ID: ' + leave_id);
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Error duplicating leave: ' + response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Server Error',
                        text: 'Error duplicating leave. Please try again.'
                    });
                }
            });
        }
    });
}

function deleteLeave(leaveId) {
    Swal.fire({
        title: 'Delete Application?',
        text: 'Are you sure you want to delete this leave application? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/delete_leave.php',
                type: 'POST',
                data: { leave_id: leaveId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            logReportAction('Deleted Leave Record', 'User deleted leave application ID: ' + leaveId);
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error deleting leave. Please try again.', 'error');
                }
            });
        }
    });
}

function downloadLeaveTemplate() {
    // Create a CSV template file
    const headers = [
        'employee_id', 'leave_type', 'start_date', 'end_date', 'reason', 
        'notes', 'contact_during_leave', 'handover_to'
    ];
    
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\n1,annual,2023-10-01,2023-10-05,Family vacation,Will be available on phone,+255123456789,2";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "leaves_import_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<style>
.card.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.card.custom-stat-card:hover { transform: translateY(-3px); }
.card.custom-stat-card h4, 
.card.custom-stat-card p, 
.card.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.dropdown-menu {
    font-size: 0.875rem;
    min-width: 200px;
}

.dropdown-item {
    padding: 0.25rem 1rem;
}

.dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-warning,
.card.bg-success,
.card.bg-danger,
.card.bg-info,
.card.bg-secondary {
    border: none;
}

.card.bg-primary { background: linear-gradient(45deg, #0d6efd, #0b5ed7); }
.card.bg-warning { background: linear-gradient(45deg, #ffc107, #e0a800); }
.card.bg-success { background: linear-gradient(45deg, #198754, #157347); }
.card.bg-danger { background: linear-gradient(45deg, #dc3545, #bb2d3b); }
.card.bg-info { background: linear-gradient(45deg, #0dcaf0, #0aa2c0); }
.card.bg-secondary { background: linear-gradient(45deg, #6c757d, #5a6268); }

/* Checkbox styling */
.leave-checkbox {
    cursor: pointer;
}

/* Progress bar customization */
.progress-bar {
    font-size: 0.75rem;
}

/* Print styles */
@media print {
    /* Base Reset */
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body {
        background: white !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    /* Hide UI elements */
    .navbar, 
    .card-header:not(.d-print-block), 
    .btn, 
    .btn-group, 
    .dropdown, 
    .dataTables_length, 
    .dataTables_filter, 
    .dataTables_info, 
    .dataTables_paginate, 
    .dt-buttons, 
    .modal, 
    .d-print-none,
    .mt-3.p-3.bg-light.rounded,
    #filterCollapse,
    .collapse {
        display: none !important;
    }
    
    .container-fluid {
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    .card {
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* Repeating Table Footer - The "Jumping" Engine */
    .d-print-table-footer {
        display: table-footer-group !important;
    }

    /* Fixed Branded Footer */
    .fixed-print-footer {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 1.2cm !important;
        display: flex !important;
        flex-direction: column;
        justify-content: center;
        text-align: center;
        background: white !important;
        border-top: 1px solid #333 !important;
        z-index: 99999 !important;
    }

    @page {
        size: auto;
        margin: 0.5in 0.3in 1.5cm 0.3in !important;
    }

    #leavesTable tr {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }

    /* Table Styling for Print */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 8.5pt !important;
    }

    table th, table td {
        border: 1px solid #666 !important;
        padding: 6px !important;
    }

    /* Ensure first/last columns stay hidden */
    #leavesTable th:first-child, 
    #leavesTable td:first-child,
    #leavesTable th:last-child, 
    #leavesTable td:last-child {
        display: none !important;
    }
}

/* Responsive adjustments */
@media (max-width: 767px) {
    .navbar {
        position: sticky;
        top: 0;
        z-index: 1020;
    }
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    @media (max-width: 768px) {
        .table-responsive {
            font-size: 0.72rem !important;
            overflow-x: hidden !important;
        }
        
        table {
            table-layout: fixed !important;
            width: 100% !important;
        }

        table th, table td {
            padding: 4px 2px !important; /* Extremely tight for scanability */
            vertical-align: middle !important;
        }

        .text-wrap {
            white-space: normal !important;
            word-break: break-all;
        }
        
        .badge {
            font-size: 0.62rem !important;
            padding: 2px 4px !important;
        }
        
        .btn-sm {
            padding: 1px 4px !important;
            font-size: 0.65rem !important;
            height: 24px !important;
            line-height: normal !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .dropdown-toggle::after {
            margin-left: 0.15rem !important;
            vertical-align: middle !important;
        }
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .btn-group {
        flex-wrap: wrap;
        gap: 0.25rem;
    }
}

@media (max-width: 576px) {
    .col-xl-2, .col-md-3 {
        margin-bottom: 0.5rem;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
}


.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.table thead th {
    background-color: #f8f9fa !important;
}
</style>

    <!-- Professional Branded Print Footer -->
    <div class="fixed-print-footer d-none d-print-block">
        <p class="mb-1 text-muted" style="font-size: 9pt;">
             This document was Printed by <span class="fw-bold text-dark"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= ucwords($_SESSION['user_role'] ?? 'Staff') ?></span> on <span class="fw-bold text-dark"><?= date('d M, Y \a\t h:i A') ?></span>
        </p>
        <p class="mb-0 fw-bold text-primary" style="font-size: 11pt; letter-spacing: 0.5px;">
            Powered By BJP Technologies  © 2026, All Rights Reserved
        </p>
    </div>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>