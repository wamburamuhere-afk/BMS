<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('employees');

// Fetch company settings for print header
$c_name = getSetting('company_name', 'BMS');
$c_logo = getSetting('company_logo', '');

// Include the header
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View employees', 'User viewed the employees management list');

// Permission flags for UI elements
$can_edit_employees = isAdmin() || canEdit('employees');
$can_delete_employees = isAdmin() || canDelete('employees');

// ── Project context (clean deep-link from Project Details) ───────────────────
// Arriving as ?project=<id>&back=<tab> pre-selects & locks the project, shows a
// "Back to Project" affordance, and returns there after save — mirroring the
// Purchase Order create/edit pattern. The return URL is rebuilt server-side from
// the project id + short tab key, so the address bar stays clean.
$proj_ctx_id     = isset($_GET['project']) ? intval($_GET['project']) : 0;
$proj_ctx_back   = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['back'] ?? ''));
$proj_ctx_name   = '';
$proj_ctx_return = '';
if ($proj_ctx_id > 0) {
    $pcs = $pdo->prepare("SELECT project_name FROM projects WHERE project_id = ?");
    $pcs->execute([$proj_ctx_id]);
    $proj_ctx_name   = $pcs->fetchColumn() ?: '';
    $proj_ctx_tab    = $proj_ctx_back !== '' ? $proj_ctx_back : 'staff';
    $proj_ctx_return = getUrl('project_view') . '?id=' . $proj_ctx_id . '&tab=' . $proj_ctx_tab;
}


// Get departments
$departments = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

// Get designations
$designations = $pdo->query("SELECT * FROM designations WHERE status = 'active' ORDER BY designation_name")->fetchAll(PDO::FETCH_ASSOC);

// Get employment types
$employment_types = $pdo->query("SELECT * FROM employment_types WHERE status = 'active' ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);

$url_project_id = $_GET['project_id'] ?? null;

// Projects dropdown — admins see all active; non-admins see only their assigned projects
if (isAdmin()) {
    $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status NOT IN ('completed', 'cancelled') ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
    if (empty($assigned)) {
        $projects = [];
    } else {
        $ph = implode(',', array_fill(0, count($assigned), '?'));
        $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status NOT IN ('completed','cancelled') AND project_id IN ($ph) ORDER BY project_name");
        $pstmt->execute($assigned);
        $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch employees with additional data
$query = "
    SELECT 
        e.*,
        d.department_name,
        des.designation_name,
        pr.project_name,
        et.type_name as employment_type,
        u1.username as created_by_name,
        u2.username as updated_by_name,
        COUNT(DISTINCT a.attendance_id) as total_attendance,
        COUNT(DISTINCT l.leave_id) as total_leaves,
        COUNT(DISTINCT p.payroll_id) as total_payrolls
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN designations des ON e.designation_id = des.designation_id
    LEFT JOIN employment_types et ON e.employment_type_id = et.type_id
    LEFT JOIN projects pr ON e.project_id = pr.project_id
    LEFT JOIN attendance a ON e.employee_id = a.employee_id
    LEFT JOIN leaves l ON e.employee_id = l.employee_id
    LEFT JOIN payroll p ON e.employee_id = p.employee_id
    LEFT JOIN users u1 ON e.created_by = u1.user_id
    LEFT JOIN users u2 ON e.updated_by = u2.user_id
    WHERE e.status != 'terminated'" . scopeFilterSqlNullable('project', 'e') . "
    GROUP BY e.employee_id
    ORDER BY e.first_name, e.last_name ASC
";

$stmt = $pdo->query($query);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_employees = count($employees);
$active_employees = array_filter($employees, function($employee) {
    return $employee['employment_status'] == 'active';
});
$on_leave_employees = array_filter($employees, function($employee) {
    return $employee['employment_status'] == 'on_leave';
});
$probation_employees = array_filter($employees, function($employee) {
    return $employee['employment_status'] == 'probation';
});
$contract_employees = array_filter($employees, function($employee) {
    return $employee['employment_status'] == 'contract';
});

// Suggested next employee number (company format, e.g. BFS-EMP-0001). This is a
// preview only — peekNextCode() does NOT advance the sequence; the real number is
// allocated at save in api/add_employee.php.
require_once __DIR__ . '/../../../core/code_generator.php';
$next_employee_number = peekNextCode($pdo, 'EMP');

// Helper functions removed, now in helpers.php
?>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-1" id="printHeader" style="margin-top: -20px !important;">
       
        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 2px 0; font-size: 12pt; letter-spacing: 1px;">EMPLOYEE RECORDS REPORT</h2>
        <p style="color: #6c757d; margin: 0; font-size: 8pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 2px solid #0d6efd; margin-top: 5px; margin-bottom: 10px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block">
        <div class="row g-3 mb-4">
            <div class="col-3">
                <div style="border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 9pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Total Employees</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 16pt;"><?= $total_employees ?></h3>
                </div>
            </div>
            <div class="col-3">
                <div style="border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 9pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Active Staff</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 16pt;"><?= count($active_employees) ?></h3>
                </div>
            </div>
            <div class="col-3">
                <div style="border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 9pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">On Leave</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 16pt;"><?= count($on_leave_employees) ?></h3>
                </div>
            </div>
            <div class="col-3">
                <div style="border: 1px solid #dee2e6; padding: 12px; border-radius: 8px; text-align: center;">
                    <p style="color: #666; font-size: 9pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Probation</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 16pt;"><?= count($probation_employees) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="#">Operations</a></li>
            <li class="breadcrumb-item active">Employee Management</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="bi bi-people-fill text-primary"></i> Employee Management</h2>
                    <p class="text-muted mb-0">Professional management of employee records and payroll</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($can_edit_employees): ?>
                    <button type="button" class="btn btn-primary px-4 shadow-sm action-btn-premium flex-grow-1 flex-md-grow-0" data-bs-toggle="modal" data-bs-target="#addEmployeeModal" style="font-weight: 600;">
                        <i class="bi bi-person-plus-fill me-1"></i> Add Employee
                    </button>
                    <?php endif; ?>
                    <a href="<?= getUrl('employee_report') ?>" class="btn btn-outline-primary px-4 shadow-sm action-btn-premium flex-grow-1 flex-md-grow-0" style="font-weight: 600;">
                        <i class="bi bi-graph-up-arrow me-1"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 d-print-none">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $total_employees ?></h4>
                            <p class="mb-0">Total Employees</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-people-fill" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0"><?= count($active_employees) ?></h4>
                            <p class="mb-0">Active Staff</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-person-check-fill" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0"><?= count($on_leave_employees) ?></h4>
                            <p class="mb-0">On Leave</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar2-range-fill" style="font-size: 2rem;"></i>
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
                            <h4 class="mb-0"><?= count($probation_employees) ?></h4>
                            <p class="mb-0">Probation</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clock-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 d-print-none border-0 shadow-sm">
        <div class="card-header bg-light border-bottom">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel"></i> Filters & Search</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <label class="form-label small fw-bold text-muted">Employment Status</label>
                    <select class="form-select border-0 shadow-sm select2-static" id="statusFilter" style="border-radius: 8px;">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="probation">Probation</option>
                        <option value="contract">Contract</option>
                        <option value="on_leave">On Leave</option>
                        <option value="terminated">Terminated</option>
                        <option value="resigned">Resigned</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <label class="form-label small fw-bold text-muted">Department</label>
                    <select class="form-select border-0 shadow-sm select2-static" id="departmentFilter" style="border-radius: 8px;">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>"><?= safe_output($dept['department_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <label class="form-label small fw-bold text-muted">Designation</label>
                    <select class="form-select border-0 shadow-sm select2-static" id="designationFilter" style="border-radius: 8px;">
                        <option value="">All Designations</option>
                        <?php foreach ($designations as $designation): ?>
                            <option value="<?= $designation['designation_id'] ?>"><?= safe_output($designation['designation_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4 col-sm-6">
                    <label class="form-label small fw-bold text-muted">Employment Type</label>
                    <select class="form-select border-0 shadow-sm select2-static" id="employmentTypeFilter" style="border-radius: 8px;">
                        <option value="">All Types</option>
                        <?php foreach ($employment_types as $type): ?>
                            <option value="<?= $type['type_id'] ?>"><?= safe_output($type['type_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-4 col-md-8 col-sm-12 d-flex align-items-end gap-2">
                    <button type="button" class="btn btn-primary flex-grow-1 shadow-sm" onclick="applyFilters()" style="border-radius: 8px;">
                        <i class="bi bi-filter"></i> <span class="d-none d-sm-inline">Apply Filters</span><span class="d-inline d-sm-none">Apply</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-3 shadow-sm" onclick="clearFilters()" style="border-radius: 8px;">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="mb-4 d-print-none">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex flex-wrap align-items-center gap-3 flex-grow-1 flex-md-grow-0">
                <!-- Export Group -->
                <div class="btn-group shadow-sm bg-white" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                    <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printEmployees()" style="background: #fff; color: #444;">
                        <i class="bi bi-printer text-primary me-1"></i> <span class="d-none d-sm-inline">Print</span>
                    </button>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportEmployees()" style="background: #fff; color: #444;">
                        <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> <span class="d-none d-sm-inline">Excel</span>
                    </button>
                    <?php if ($can_edit_employees): ?>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" class="btn btn-white fw-medium px-3 border-0" data-bs-toggle="modal" data-bs-target="#importEmployeesModal" style="background: #fff; color: #444;">
                        <i class="bi bi-upload text-info me-1"></i> <span class="d-none d-md-inline">Import</span>
                    </button>
                    <?php endif; ?>
                </div>
                
                <!-- Pagesize Group -->
                <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                    <span class="small text-muted me-2 d-none d-md-inline"><i class="bi bi-list-ol"></i> Show:</span>
                    <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 50px; box-shadow: none; background: transparent;" onchange="$('#employeesTable').DataTable().page.len(this.value).draw();">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="-1">All</option>
                    </select>
                </div>

                <!-- Search Group -->
                <div class="input-group input-group-sm shadow-sm flex-grow-1" style="min-width: 200px; max-width: 300px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                    <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-0 p-2" id="searchEmployees" placeholder="Search employees..." onkeyup="$('#employeesTable').DataTable().search(this.value).draw();">
                </div>
            </div>

            <!-- View Toggle & Badge -->
            <div class="d-flex align-items-center gap-2 ms-auto ms-md-0">
                <div class="btn-group shadow-sm bg-white d-none d-md-flex" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                    <button type="button" id="btn-table-view" class="btn btn-white fw-medium px-3 border-0" onclick="toggleView('table')" style="background: #fff; color: #444;">
                        <i class="bi bi-list-task text-primary"></i> <span class="d-none d-xl-inline">List</span>
                    </button>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" id="btn-card-view" class="btn btn-white fw-medium px-3 border-0" onclick="toggleView('card')" style="background: #fff; color: #444;">
                        <i class="bi bi-grid-3x3-gap text-primary"></i> <span class="d-none d-xl-inline">Card</span>
                    </button>
                </div>
                <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill d-none d-sm-inline">
                    <i class="bi bi-people-fill me-1"></i> <?= $total_employees ?> Employees
                </span>
            </div>
        </div>
    </div>

    <!-- Employees Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-print-none">
            <h5 class="mb-0 fw-bold">Employees List</h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (!empty($employees)): // Show table/cards if employees exist ?>
                <!-- Table View -->
                <div id="tableView" class="table-responsive w-100">
                    <table id="employeesTable" class="table table-striped table-hover align-middle" style="width: 100%;" width="100%">
                        <thead>
                            <tr>
                                <th style="width: 50px;">S/NO</th>
                                <th>Employee #</th>
                                <th>Name</th>
                                <th>Department</th>
                                <th>Designation</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Attendance</th>
                                <th>Leaves</th>
                                <th>Payrolls</th>
                                <th>Joined</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Data loaded via AJAX -->
                        </tbody>
                        <tbody>
                            <!-- Data loaded via AJAX -->
                        </tbody>
                        <tfoot>
                            <!-- Spacer to prevent data hidden behind fixed footer in print -->
                            <tr class="d-none d-print-table-row" style="height: 80px; border: none !important;">
                                <td colspan="12" style="border: none !important;"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Card View (Hidden by default) -->
                <div id="cardView" class="row d-none">
                    <?php foreach ($employees as $employee): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-3 employee-card" 
                         data-status="<?= $employee['employment_status'] ?>" 
                         data-dept="<?= $employee['department_id'] ?>" 
                         data-designation="<?= $employee['designation_id'] ?>" 
                         data-type="<?= $employee['employment_type_id'] ?>"
                         data-name="<?= strtolower(safe_output($employee['first_name'] . ' ' . $employee['last_name'])) ?>">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0"><?= safe_output($employee['first_name'] . ' ' . $employee['last_name']) ?></h6>
                                    <small class="text-muted"><?= safe_output($employee['employee_number']) ?></small>
                                </div>
                                <span class="badge bg-<?= get_status_badge($employee['employment_status']) ?>">
                                    <?= ucfirst(substr($employee['employment_status'], 0, 1)) ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-3">
                                    <div class="avatar-placeholder bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; font-size: 2rem;">
                                        <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                                    </div>
                                </div>
                                
                                <div class="mb-2 text-center">
                                    <strong><?= safe_output($employee['designation_name']) ?></strong><br>
                                    <small class="text-muted"><?= safe_output($employee['department_name']) ?></small>
                                    <?php if ($employee['project_name']): ?>
                                    <div class="mt-1"><small class="badge bg-light-soft text-primary border border-primary-subtle" style="font-size: 0.65rem;"><i class="bi bi-briefcase me-1"></i><?= safe_output($employee['project_name']) ?></small></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if (!empty($employee['email'])): ?>
                                    <small><i class="bi bi-envelope"></i> <?= safe_output($employee['email']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if (!empty($employee['phone'])): ?>
                                    <small><i class="bi bi-telephone"></i> <?= safe_output($employee['phone']) ?></small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <small><i class="bi bi-calendar"></i> 
                                        Hired: <?= date('d M Y', strtotime($employee['hire_date'])) ?>
                                    </small>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-success">
                                        <i class="bi bi-cash"></i> Salary: <?= format_currency($employee['basic_salary'] ?? 0) ?>
                                    </small>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-3">
                                    <div class="text-center">
                                        <div class="badge bg-primary"><?= $employee['total_attendance'] ?? 0 ?></div>
                                        <br>
                                        <small>Attendance</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="badge bg-info"><?= $employee['total_leaves'] ?? 0 ?></div>
                                        <br>
                                        <small>Leaves</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="badge bg-success"><?= $employee['total_payrolls'] ?? 0 ?></div>
                                        <br>
                                        <small>Payrolls</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-white" style="padding: 6px 8px;">
                                <div style="display:flex; flex-wrap:nowrap; gap:4px;">
                                     <a href="<?= getUrl('employee_details') ?>?id=<?= $employee['employee_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Details" style="flex:1; min-width:0; padding:3px 4px; font-size:0.72rem;">
                                         <i class="bi bi-eye"></i>
                                     </a>
                                     <?php if ($can_edit_employees): ?>
                                     <button class="btn btn-sm btn-outline-warning" onclick="editEmployee(<?= $employee['employee_id'] ?>)" title="Edit" style="flex:1; min-width:0; padding:3px 4px; font-size:0.72rem;">
                                         <i class="bi bi-pencil"></i>
                                     </button>
                                     <?php endif; ?>
                                     <a href="<?= getUrl('attendance') ?>?employee=<?= $employee['employee_id'] ?>" class="btn btn-sm btn-outline-success" title="Attendance" style="flex:1; min-width:0; padding:3px 4px; font-size:0.72rem;">
                                         <i class="bi bi-clock"></i>
                                     </a>
                                     <a href="<?= getUrl('payroll') ?>?employee=<?= $employee['employee_id'] ?>" class="btn btn-sm btn-outline-info" title="Payroll" style="flex:1; min-width:0; padding:3px 4px; font-size:0.72rem;">
                                         <i class="bi bi-cash"></i>
                                     </a>
                                     <a href="<?= getUrl('employee_statement') ?>?employee_id=<?= $employee['employee_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Account" style="flex:1; min-width:0; padding:3px 4px; font-size:0.72rem;">
                                         <i class="bi bi-file-earmark-text"></i>
                                     </a>
                                 </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-people" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Employees Found</h4>
                    <p class="text-muted">Get started by adding your first employee.</p>
                    <?php if ($can_edit_employees): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                        <i class="bi bi-plus-circle"></i> Add Your First Employee
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

   
</div>

<!-- Add Employee Modal -->
<?php if ($can_edit_employees): ?>
<div class="modal fade" id="addEmployeeModal" tabindex="-1" aria-modal="true" role="dialog" aria-labelledby="addEmployeeModalLabel">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addEmployeeModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Employee
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addEmployeeForm">
                <input type="hidden" id="employee_id" name="employee_id" value="">
                <div class="modal-body">
                    <div id="add-employee-message" class="mb-3"></div>
                    <?php if ($proj_ctx_id > 0): ?>
                    <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 px-3 mb-3">
                        <span class="small mb-0"><i class="bi bi-diagram-3 me-1"></i>Adding to project: <strong><?= safe_output($proj_ctx_name) ?></strong></span>
                        <a href="<?= htmlspecialchars($proj_ctx_return) ?>" class="btn btn-outline-primary btn-sm text-nowrap">
                            <i class="bi bi-arrow-left me-1"></i> Back to Project
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 id="stepIndicator" class="text-primary fw-bold mb-0">Step 1 of 5: Personal Info</h6>
                        <div class="progress" style="width: 200px; height: 10px;">
                            <div id="wizardProgressBar" class="progress-bar bg-success" role="progressbar" style="width: 20%;" aria-valuenow="20" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>

                    <div id="wizardStepsContainer">
                        <!-- Personal Information Tab -->
                        <div class="wizard-step" id="step-0" style="display:block;">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required placeholder="Enter first name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="Enter middle name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required placeholder="Enter last name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select class="form-select select2-static" id="gender" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="date_of_birth" class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="marital_status" class="form-label">Marital Status</label>
                                    <select class="form-select select2-static" id="marital_status" name="marital_status">
                                        <option value="">Select Status</option>
                                        <option value="single">Single</option>
                                        <option value="married">Married</option>
                                        <option value="divorced">Divorced</option>
                                        <option value="widowed">Widowed</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="national_id" class="form-label">National ID Number</label>
                                    <input type="text" class="form-control" id="national_id" name="national_id" placeholder="National ID">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="passport_number" class="form-label">Passport Number</label>
                                    <input type="text" class="form-control" id="passport_number" name="passport_number" placeholder="Passport number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="notes" class="form-label">Personal Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Any personal notes about employee"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Employment Details Step -->
                        <div class="wizard-step" id="step-1" style="display:none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="employee_number" class="form-label">Employee Number <span class="text-muted small">(Auto-generated)</span> <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control bg-light" id="employee_number" name="employee_number" required value="<?= $next_employee_number ?>" readonly>
                                    <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i> Unique code automatically assigned by system</p>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="hire_date" class="form-label">Hire Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="hire_date" name="hire_date" required value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                                    <select class="form-select select2-static" id="department_id" name="department_id" required>
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                        <option value="<?= $dept['department_id'] ?>"><?= safe_output($dept['department_name']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="other">➕ Other (specify)…</option>
                                    </select>
                                    <div id="department_other_box" class="input-group mt-2 d-none">
                                        <input type="text" class="form-control" id="department_other" name="department_other" placeholder="Type new department — it will be saved">
                                        <button type="button" class="btn btn-outline-secondary" id="department_other_back" title="Back to list"><i class="bi bi-arrow-left"></i></button>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="designation_id" class="form-label">Designation <span class="text-danger">*</span></label>
                                    <select class="form-select select2-static" id="designation_id" name="designation_id" required>
                                        <option value="">Select Designation</option>
                                        <?php foreach ($designations as $designation): ?>
                                        <option value="<?= $designation['designation_id'] ?>" data-department-id="<?= $designation['department_id'] ?>"><?= safe_output($designation['designation_name']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="other">➕ Other (specify)…</option>
                                    </select>
                                    <div id="designation_other_box" class="input-group mt-2 d-none">
                                        <input type="text" class="form-control" id="designation_other" name="designation_other" placeholder="Type new designation — it will be saved">
                                        <button type="button" class="btn btn-outline-secondary" id="designation_other_back" title="Back to list"><i class="bi bi-arrow-left"></i></button>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="employment_type_id" class="form-label">Employment Type <span class="text-danger">*</span></label>
                                    <select class="form-select select2-static" id="employment_type_id" name="employment_type_id" required>
                                        <option value="">Select Type</option>
                                        <?php foreach ($employment_types as $type): ?>
                                        <option value="<?= $type['type_id'] ?>"><?= safe_output($type['type_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="employment_status" class="form-label">Employment Status <span class="text-danger">*</span></label>
                                    <select class="form-select select2-static" id="employment_status" name="employment_status" required>
                                        <option value="probation" selected>Probation</option>
                                        <option value="active">Active</option>
                                        <option value="contract">Contract</option>
                                        <option value="on_leave">On Leave</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="probation_end_date" class="form-label">Probation End Date</label>
                                    <input type="date" class="form-control" id="probation_end_date" name="probation_end_date">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contract_end_date" class="form-label">Contract End Date</label>
                                    <input type="date" class="form-control" id="contract_end_date" name="contract_end_date">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="reporting_to_id" class="form-label">Reporting To</label>
                                    <select class="form-select select2-employee-ajax" id="reporting_to_id" name="reporting_to_id" style="width:100%"></select>
                                    <div class="form-text" id="reporting_to_legacy_hint"></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="work_location" class="form-label">Work Location</label>
                                    <input type="text" class="form-control" id="work_location" name="work_location" placeholder="Office location">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="project_id" class="form-label">Assign to Project</label>
                                    <select class="form-select select2-static" id="project_id" name="project_id">
                                        <option value="">No Project / General</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['project_id'] ?>" <?= ($url_project_id == $project['project_id']) ? 'selected' : '' ?>>
                                            <?= safe_output($project['project_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Optional: Link this employee to a specific project.</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Salary & Benefits Step -->
                        <div class="wizard-step" id="step-2" style="display:none;">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="basic_salary" class="form-label">Basic Salary <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="basic_salary" name="basic_salary" step="0.01" required placeholder="0.00">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="hourly_rate" class="form-label">Hourly Rate</label>
                                    <input type="number" class="form-control" id="hourly_rate" name="hourly_rate" step="0.01" placeholder="Hourly rate if applicable">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="currency" class="form-label">Currency</label>
                                    <select class="form-select select2-static" id="currency" name="currency">
                                        <option value="TZS" selected>Tanzanian Shilling (TZS)</option>
                                        <option value="USD">US Dollar (USD)</option>
                                        <option value="EUR">Euro (EUR)</option>
                                        <option value="GBP">British Pound (GBP)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="payment_frequency" class="form-label">Payment Frequency</label>
                                    <select class="form-select select2-static" id="payment_frequency" name="payment_frequency" onchange="togglePaymentFrequencyOther(this.value)">
                                        <option value="monthly" selected>Monthly</option>
                                        <option value="biweekly">Bi-Weekly</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="daily">Daily</option>
                                        <option value="hourly">Hourly</option>
                                        <option value="other">Other (Manual Entry)</option>
                                    </select>
                                    <div id="payment_frequency_other_div" class="mt-2 d-none">
                                        <input type="text" class="form-control" id="payment_frequency_other" name="payment_frequency_other" placeholder="e.g. 10 days, 3 months">
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="payment_method" class="form-label">Payment Method</label>
                                    <select class="form-select select2-static" id="payment_method" name="payment_method">
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cash">Cash</option>
                                        <option value="check">Check</option>
                                        <option value="mobile">Mobile Money</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="tax_id" class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" id="tax_id" name="tax_id" placeholder="Tax Identification Number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="social_security_number" class="form-label">Social Security Number</label>
                                    <input type="text" class="form-control" id="social_security_number" name="social_security_number" placeholder="SSN/NIDA">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Benefits</label>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="health_insurance" name="benefits[]" value="health_insurance">
                                                <label class="form-check-label" for="health_insurance">Health Insurance</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="life_insurance" name="benefits[]" value="life_insurance">
                                                <label class="form-check-label" for="life_insurance">Life Insurance</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="pension" name="benefits[]" value="pension">
                                                <label class="form-check-label" for="pension">Pension</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="transport_allowance" name="benefits[]" value="transport_allowance">
                                                <label class="form-check-label" for="transport_allowance">Transport Allowance</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Details Step -->
                        <div class="wizard-step" id="step-3" style="display:none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" required placeholder="employee@company.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="phone" name="phone" required placeholder="+255 123 456 789">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="alternate_phone" class="form-label">Alternate Phone</label>
                                    <input type="text" class="form-control" id="alternate_phone" name="alternate_phone" placeholder="Alternate phone number">
                                </div>


                                <div class="col-md-6 mb-3">
                                    <label for="physical_address" class="form-label">Physical Address <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="physical_address" name="physical_address" rows="2" required placeholder="e.g. Msasani, Dar es Salaam"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="postal_address" class="form-label">Postal Address</label>
                                    <textarea class="form-control" id="postal_address" name="postal_address" rows="2" placeholder="e.g. P.O. Box 1234, Dar es Salaam"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="country" name="country" placeholder="Country" value="Tanzania">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="state" class="form-label">Region</label>
                                    <input type="text" class="form-control" id="state" name="state" placeholder="e.g. Dar es Salaam">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="city" class="form-label">District</label>
                                    <input type="text" class="form-control" id="city" name="city" placeholder="e.g. Ilala">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="ward" class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="ward" name="ward" placeholder="e.g. Kariakoo">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="village" class="form-label">Street/Village</label>
                                    <input type="text" class="form-control" id="village" name="village" placeholder="e.g. Mtaa wa Kariakoo">
                                </div>

                                <!-- Emergency Contact Sub-section -->
                                <div class="col-12 mb-2 mt-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-person-exclamation text-primary"></i>
                                        <h6 class="fw-bold text-primary mb-0" style="font-size: 0.9rem; letter-spacing: 0.5px; text-transform: uppercase;">Emergency Contact</h6>
                                    </div>
                                    <hr class="mt-1 mb-2 border-primary border-opacity-25">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="emergency_contact" class="form-label">Contact Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" required placeholder="Full name of emergency contact">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="emergency_contact_relationship" class="form-label">Relationship with Employee</label>
                                    <input type="text" class="form-control" id="emergency_contact_relationship" name="emergency_contact_relationship" placeholder="e.g. Spouse, Parent, Sibling">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="emergency_contact_phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone" required placeholder="Phone number of emergency contact">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="emergency_contact_email" class="form-label">Email Address <small class="text-muted">(if available)</small></label>
                                    <input type="email" class="form-control" id="emergency_contact_email" name="emergency_contact_email" placeholder="contact@example.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="emergency_contact_postal_address" class="form-label">Postal Address <small class="text-muted">(of Contact)</small></label>
                                    <input type="text" class="form-control" id="emergency_contact_postal_address" name="emergency_contact_postal_address" placeholder="P.O. Box or postal address">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="emergency_contact_physical_address" class="form-label">Physical Address <small class="text-muted">(of Contact)</small></label>
                                    <textarea class="form-control" id="emergency_contact_physical_address" name="emergency_contact_physical_address" rows="2" placeholder="Street, neighbourhood, town/city"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bank & Documents Step -->
                        <div class="wizard-step" id="step-4" style="display:none;">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="bank_account" class="form-label">Bank Account Number</label>
                                    <input type="text" class="form-control" id="bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="bank_branch" class="form-label">Bank Branch</label>
                                    <input type="text" class="form-control" id="bank_branch" name="bank_branch" placeholder="Bank branch">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="mobile_money" class="form-label">Mobile Money Number</label>
                                    <input type="text" class="form-control" id="mobile_money" name="mobile_money" placeholder="Mobile money number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Required Documents</label>
                                    <div class="row g-3">
                                        <!-- Compulsory Documents -->
                                        <div class="col-md-4">
                                            <div class="mb-2">
                                                <label class="form-label fw-bold">CV/Resume <span class="text-danger">*</span></label>
                                                <input type="file" class="form-control form-control-sm" id="cv_file" name="cv_file" accept=".pdf,.doc,.docx" required>
                                                <div class="invalid-feedback">CV/Resume is compulsory</div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-2">
                                                <label class="form-label fw-bold">ID Copy <span class="text-danger">*</span></label>
                                                <input type="file" class="form-control form-control-sm" id="id_file" name="id_file" accept=".pdf,.jpg,.jpeg,.png" required>
                                                <div class="invalid-feedback">ID Copy is compulsory</div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="mb-2">
                                                <label class="form-label fw-bold">Certificates <span class="text-danger">*</span></label>
                                                <input type="file" class="form-control form-control-sm" id="certificates_file" name="certificates_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                                                <div class="invalid-feedback">Certificates are compulsory</div>
                                            </div>
                                        </div>

                                        <div class="col-12"><hr class="my-1"></div>
                                        <div class="col-12">
                                            <label class="form-label small text-muted">Additional / Optional Documents (Tick to upload)</label>
                                        </div>
                                        
                                        <!-- Optional Documents -->
                                        <div class="col-md-4">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" id="intro_letter_attached" name="documents[]" value="intro_letter" onchange="toggleDocUpload('intro_letter')">
                                                <label class="form-check-label" for="intro_letter_attached">Introduction Letter</label>
                                            </div>
                                            <div id="intro_letter_upload_div" class="d-none">
                                                <input type="file" class="form-control form-control-sm" id="intro_letter_file" name="intro_letter_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" id="app_letter_attached" name="documents[]" value="app_letter" onchange="toggleDocUpload('app_letter')">
                                                <label class="form-check-label" for="app_letter_attached">Application Letter</label>
                                            </div>
                                            <div id="app_letter_upload_div" class="d-none">
                                                <input type="file" class="form-control form-control-sm" id="app_letter_file" name="app_letter_file" accept=".pdf,.doc,.docx">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" id="other_doc_attached" name="documents[]" value="other_doc" onchange="toggleDocUpload('other_doc')">
                                                <label class="form-check-label" for="other_doc_attached">Others</label>
                                            </div>
                                            <div id="other_doc_upload_div" class="d-none">
                                                <input type="text" class="form-control form-control-sm mb-1" id="other_doc_name" name="other_doc_name" placeholder="Document Name (e.g. Guarantee)">
                                                <input type="file" class="form-control form-control-sm" id="other_doc_file" name="other_doc_file">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="additional_notes" class="form-label">Additional Notes</label>
                                    <textarea class="form-control" id="additional_notes" name="additional_notes" rows="3" placeholder="Any additional notes or information"></textarea>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="position: relative; z-index: 2000; pointer-events: auto !important;">
                    <button type="button" class="btn btn-secondary me-auto" id="prevBtn" style="display:none;" onclick="console.log('Prev'); window.nextTab(-1);">
                        <i class="bi bi-arrow-left"></i> Previous
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="nextBtn" onclick="console.log('Next'); window.nextTab(1);">
                        Next <i class="bi bi-arrow-right"></i>
                    </button>
                    <button type="button" class="btn btn-success" id="saveBtn" style="display:none;" onclick="window.submitEmployeeForm();">
                        <i class="bi bi-check-circle"></i> Save Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Employees Modal -->
<div class="modal fade" id="importEmployeesModal" tabindex="-1" aria-labelledby="importEmployeesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="importEmployeesModalLabel">
                    <i class="bi bi-upload"></i> Import Employees
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="importEmployeesForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="import-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the employee data</li>
                            <li>Upload the completed file</li>
                            <li>File must be in CSV format</li>
                            <li>Maximum file size: 5MB</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="import_file" name="import_file" accept=".csv" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_action" class="form-label">Import Action</label>
                        <select class="form-select" id="import_action" name="import_action">
                            <option value="add_new">Add New Employees Only</option>
                            <option value="update_existing">Update Existing Employees</option>
                            <option value="add_update">Add New & Update Existing</option>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="skip_errors" name="skip_errors">
                        <label class="form-check-label" for="skip_errors">
                            Skip rows with errors and continue
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Import Employees
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Edit Modal -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-labelledby="editEmployeeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="editEmployeeModalLabel">
                    <i class="bi bi-pencil"></i> Quick Edit Employee
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editEmployeeForm">
                <div class="modal-body">
                    <div id="edit-employee-message" class="mb-3"></div>
                    <?php if ($proj_ctx_id > 0): ?>
                    <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 px-3 mb-3">
                        <span class="small mb-0"><i class="bi bi-diagram-3 me-1"></i>Editing within project: <strong><?= safe_output($proj_ctx_name) ?></strong></span>
                        <a href="<?= htmlspecialchars($proj_ctx_return) ?>" class="btn btn-outline-primary btn-sm text-nowrap">
                            <i class="bi bi-arrow-left me-1"></i> Back to Project
                        </a>
                    </div>
                    <?php endif; ?>
                    <input type="hidden" id="edit_employee_id" name="employee_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_phone" name="phone" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_employment_status" class="form-label">Status</label>
                            <select class="form-select select2-static" id="edit_employment_status" name="employment_status">
                                <option value="active">Active</option>
                                <option value="probation">Probation</option>
                                <option value="contract">Contract</option>
                                <option value="on_leave">On Leave</option>
                                <option value="resigned">Resigned</option>
                                <option value="terminated">Terminated</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_department_id" class="form-label">Department</label>
                        <select class="form-select select2-static" id="edit_department_id" name="department_id">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>"><?= safe_output($dept['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="edit_basic_salary" class="form-label">Basic Salary</label>
                        <input type="number" class="form-control" id="edit_basic_salary" name="basic_salary" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Update Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Include DataTables and other scripts -->
<!-- <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script src="<?= getUrl('assets/js/location_cascade.js') ?>"></script>
<script>
// Location cascade engine — Tanzania gets defined dropdowns (Region→District→
// Ward→Street/Village), other countries fall back to free text automatically.
// The Add modal's form doubles as the full-edit form, so ONE cascade serves both.
let employeeLocationCascade;

// Initialize Select2 on elements with select2-static class
function initEmpSelect2(context, dropdownParent) {
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

// --- Department → Designation cascade + "Other (specify)" (Step 2) ---------
// Master list of designations (id, name, department_id) so the Designation
// dropdown can be narrowed to the chosen Department without a round-trip.
window.EMP_DESIGNATIONS = <?= json_encode(array_map(function ($d) {
    return [
        'id'   => (int)$d['designation_id'],
        'name' => $d['designation_name'],
        'dept' => $d['department_id'] !== null ? (int)$d['department_id'] : null,
    ];
}, $designations), JSON_UNESCAPED_UNICODE) ?>;

// Rebuild the Designation <select> to only those under $departmentId, keeping
// the current selection if it still belongs. Re-inits Select2 on the modal.
function rebuildDesignationOptions(departmentId, keepValue) {
    var $sel = $('#designation_id');
    var current = (keepValue !== undefined) ? String(keepValue) : String($sel.val() || '');
    var isOther = String(departmentId) === 'other';
    var deptNum = parseInt(departmentId, 10);

    var html = '<option value="">Select Designation</option>';
    var currentStillValid = false;
    if (!isOther && !isNaN(deptNum)) {
        window.EMP_DESIGNATIONS.forEach(function (d) {
            if (d.dept === deptNum) {
                var sel = (String(d.id) === current) ? ' selected' : '';
                if (sel) currentStillValid = true;
                html += '<option value="' + d.id + '" data-department-id="' + d.dept + '"' + sel + '>' +
                        $('<div>').text(d.name).html() + '</option>';
            }
        });
    }
    html += '<option value="other">➕ Other (specify)…</option>';
    if (current === 'other') { html = html.replace('value="other">', 'value="other" selected>'); }

    if ($sel.hasClass('select2-hidden-accessible')) { $sel.select2('destroy'); }
    $sel.html(html);
    if (!currentStillValid && current !== 'other') { $sel.val(''); }

    var $modal = $sel.closest('.modal');
    $sel.select2({ theme: 'bootstrap-5', width: '100%', allowClear: true,
                   placeholder: 'Select Designation', dropdownParent: $modal.length ? $modal : undefined });
    // If Department is "Other" (brand-new), there are no designations to pick —
    // drop straight into the Designation "Other" text box.
    toggleEmpOther('designation_id', 'designation_other_box', 'designation_other', $sel.val() === 'other');
}

// Swap a Select2 dropdown for its free-text "Other" input (and back).
function toggleEmpOther(selectId, boxId, inputId, isOther) {
    var $sel = $('#' + selectId), $box = $('#' + boxId), $input = $('#' + inputId);
    if (isOther) {
        $sel.addClass('d-none').next('.select2-container').addClass('d-none');
        $box.removeClass('d-none');
        $input.attr('required', true).trigger('focus');
    } else {
        $sel.removeClass('d-none').next('.select2-container').removeClass('d-none');
        $box.addClass('d-none');
        $input.attr('required', false).val('').removeClass('is-invalid');
    }
}

// "Reporting To" is scoped to the chosen Department: if that department has a
// LEADER set, only the leader (+ assistant) can be picked; if it has NO leader,
// every employee in that department is offered. Submits reporting_to_id; the
// server dual-writes the legacy reporting_to varchar from the chosen name.
// (Replaces the old free-across-all-employees AJAX picker.)
function loadReportingToOptions(departmentId, selectedId, selectedName, dropdownParent) {
    const $el = $('#reporting_to_id');
    if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
    $el.empty().append(new Option('', '', false, false));
    $('#reporting_to_legacy_hint').text('');

    const initSelect2 = (placeholder) => $el.select2({
        theme: 'bootstrap-5', width: '100%', allowClear: true,
        placeholder: placeholder, dropdownParent: dropdownParent || undefined
    });

    // 'other' (brand-new department) or none → nothing to report to yet.
    if (!departmentId || departmentId === 'other') {
        initSelect2('Select a department first');
        return;
    }

    const editingId = $('#employee_id').val() || '';
    $.getJSON(APP_URL + '/api/get_reporting_to_options',
        { department_id: departmentId, exclude_id: editingId, _ts: Date.now() }, function (res) {
        const results = (res && res.results) || [];
        results.forEach(o => $el.append(new Option(o.text, o.id, false, false)));
        // Keep an already-set manager selectable even if outside the current list.
        if (selectedId && !results.some(o => String(o.id) === String(selectedId))) {
            $el.append(new Option(selectedName || ('#' + selectedId), selectedId, false, false));
        }
        const mode = res && res.mode;
        initSelect2(mode === 'leadership' ? 'Select leader / assistant…' : 'Select employee…');
        if (selectedId) { $el.val(String(selectedId)).trigger('change.select2'); }
        if (mode === 'leadership') $('#reporting_to_legacy_hint').text('This department has leadership set — pick the leader or assistant.');
        else if (mode === 'all') $('#reporting_to_legacy_hint').text('No leader set for this department — pick any employee in it.');
    });
}

// Edit mode: remember who this employee reports to; the modal's shown handler
// loads the department-scoped options and re-selects this person.
function populateReportingTo(emp) {
    window._editReportingToId = emp.reporting_to_id || '';
    window._editReportingToName = emp.reporting_to || '';
}

$(document).ready(function() {
    // Filter selects (outside any modal)
    $('.select2-static:not(.modal .select2-static)').each(function() {
        var $el = $(this);
        if ($el.hasClass('select2-hidden-accessible')) return;
        var placeholder = $el.find('option[value=""]').first().text() || 'Select...';
        $el.select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: placeholder });
    });

    // Modal selects — initialize when modal opens
    $('#addEmployeeModal').on('shown.bs.modal', function() {
        initEmpSelect2($(this), $(this));
        // Narrow designations to the (pre)selected department without clobbering
        // an already-chosen designation (edit mode populates before this fires).
        // With no department yet (fresh add) the list is empty until one is picked.
        var deptVal = $('#department_id').val() || '';
        rebuildDesignationOptions(deptVal, $('#designation_id').val());
        toggleEmpOther('department_id', 'department_other_box', 'department_other', deptVal === 'other');
        // Reporting To — department-scoped (leader/assistant, else dept employees).
        loadReportingToOptions(deptVal, window._editReportingToId || '', window._editReportingToName || '', $(this));
        window._editReportingToId = ''; window._editReportingToName = '';
    });
    $('#editEmployeeModal').on('shown.bs.modal', function() {
        initEmpSelect2($(this), $(this));
    });

    // Department → Designation cascade + "Other (specify)" wiring (delegated so
    // it survives the Designation select being rebuilt/re-init'd by Select2).
    $(document).on('change', '#department_id', function() {
        var val = $(this).val();
        toggleEmpOther('department_id', 'department_other_box', 'department_other', val === 'other');
        // New department picked → reset designation to that department's list.
        rebuildDesignationOptions(val, '');
        // …and refresh Reporting To to that department's leadership / employees.
        loadReportingToOptions(val, '', '', $('#addEmployeeModal'));
    });
    $(document).on('change', '#designation_id', function() {
        toggleEmpOther('designation_id', 'designation_other_box', 'designation_other', $(this).val() === 'other');
    });
    $(document).on('click', '#department_other_back', function() {
        toggleEmpOther('department_id', 'department_other_box', 'department_other', false);
        $('#department_id').val('').trigger('change');
    });
    $(document).on('click', '#designation_other_back', function() {
        var $sel = $('#designation_id');
        $sel.val('');
        if ($sel.hasClass('select2-hidden-accessible')) { $sel.trigger('change.select2'); }
        toggleEmpOther('designation_id', 'designation_other_box', 'designation_other', false);
    });

    // Initialize DataTable with server-side processing
    window.employeesTable = $('#employeesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: APP_URL + '/api/get_employees',
            type: 'GET',
            error: function(xhr, error, thrown) {
                console.error('DataTables AJAX error:', error, thrown);
                console.log('Response:', xhr.responseText);
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
                }
            },
            { data: 0, title: 'Employee #' },
            { data: 1, title: 'Name' },
            { data: 2, title: 'Department' },
            { data: 3, title: 'Designation' },
            { data: 4, title: 'Project' },
            { data: 5, title: 'Status' },
            { data: 6, title: 'Attendance' },
            { data: 7, title: 'Leaves' },
            { data: 8, title: 'Payrolls' },
            { data: 9, title: 'Joined' },
            { data: 10, title: 'Actions', orderable: false, className: 'text-end' }
        ],
        language: {
            search: "Search employees:",
            lengthMenu: "Show _MENU_ employees per page",
            info: "Showing _START_ to _END_ of _TOTAL_ employees",
            infoEmpty: "No employees found",
            infoFiltered: "(filtered from _MAX_ total employees)",
            loadingRecords: "Loading employees...",
            processing: '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>',
            zeroRecords: "No matching employees found",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        responsive: true,
        dom: '<"d-none"B>rtip',
        buttons: [
            {
                extend: 'excelHtml5',
                className: 'buttons-excel', // Class to target for trigger
                title: 'Employees_List_' + new Date().toISOString().slice(0,10),
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
                }
            }
        ],
        pageLength: 10,
        lengthChange: false, // Using custom selector
        order: [[2, 'asc']],
        autoWidth: false,
        columnDefs: [
            { orderable: false, targets: [11] } // Disable sorting on Actions column
        ]
    });

    // Add employee form submission
    $('#addEmployeeForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        const selectedProjectId = $('#project_id').val();   // capture before any reset

        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: APP_URL + '/api/add_employee',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        redirectAfterEmployeeSave(selectedProjectId);
                    });
                } else {
                    $('#add-employee-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Employee');
                }
            },
            error: function(xhr, status, error) {
                $('#add-employee-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Employee');
                console.error('Error:', error);
            }
        });
    });

    // Import form submission
    $('#importEmployeesForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...');

        $.ajax({
            url: APP_URL + '/api/import_employees',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#import-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    if (response.results) {
                        let resultsHtml = '<div class="mt-2 text-start"><small>';
                        resultsHtml += 'Total rows: ' + response.results.total_rows + '<br>';
                        resultsHtml += 'Successful: ' + response.results.successful + '<br>';
                        resultsHtml += 'Failed: ' + response.results.failed + '<br>';
                        resultsHtml += 'Skipped: ' + response.results.skipped + '<br>';
                        if (response.results.errors && response.results.errors.length > 0) {
                            resultsHtml += '<strong>Errors:</strong><ul>';
                            response.results.errors.forEach(function(error) {
                                resultsHtml += '<li>' + error + '</li>';
                            });
                            resultsHtml += '</ul>';
                        }
                        resultsHtml += '</small></div>';
                        $('#import-message').append(resultsHtml);
                    }
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Import failed.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Employees');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred during import.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Employees');
            }
        });
    });

    // Edit employee form submission
    $('#editEmployeeForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: APP_URL + '/api/update_employee',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message || 'Employee updated successfully.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        if (window.__projReturnUrl) { window.location.href = window.__projReturnUrl; }
                        else { location.reload(); }
                    });
                } else {
                    Swal.fire('Error', response.message || 'Update failed.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Employee');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred. Please try again.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Employee');
            }
        });
    });

    // Location cascade (OOP location engine): defined dropdowns for Tanzania,
    // free-text automatically for countries without imported subdivisions.
    employeeLocationCascade = initLocationCascade({
        endpoint: '<?= buildUrl('api/location/options.php') ?>',
        fields: { country: '#country', region: '#state', district: '#city', ward: '#ward', village: '#village' },
        dropdownParent: '#addEmployeeModal'
    });

    // Reset forms when modals are closed
    $('#addEmployeeModal').on('hidden.bs.modal', function() {
        $('#addEmployeeForm')[0].reset();
        $('#add-employee-message').html('');
        employeeLocationCascade.setValues({ country: 'Tanzania' }); // back to defaults
        $('#addEmployeeForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Employee');
        $('#payment_frequency_other_div').addClass('d-none');
        $('#payment_frequency_other').val('');
        $('#employeeTabs .nav-link:first').tab('show');
        if ($('#reporting_to_id').hasClass('select2-hidden-accessible')) {
            $('#reporting_to_id').empty().val(null).trigger('change');
        }
        $('#reporting_to_legacy_hint').text('');
    });
    
    $('#importEmployeesModal').on('hidden.bs.modal', function() {
        $('#importEmployeesForm')[0].reset();
        $('#import-message').html('');
        $('#importEmployeesForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Import Employees');
    });
    
    $('#editEmployeeModal').on('hidden.bs.modal', function() {
        $('#editEmployeeForm')[0].reset();
        $('#edit-employee-message').html('');
        $('#payment_frequency_other_div').addClass('d-none');
        $('#payment_frequency_other').val('');
        $('#editEmployeeForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Employee');
});
});
</script>

<script>
// Wizard Functions - Exposed to Global Scope for robustness
window.currentStep = 0;
window.totalSteps = 5;
window.stepTitles = [
    "Personal Info", 
    "Employment", 
    "Salary & Benefits", 
    "Contact Details", 
    "Bank & Documents"
];

// Log when Add Employee modal is opened
$('#addEmployeeModal').on('show.bs.modal', function() {
    $.post(APP_URL + '/api/log_audit', {
        action: 'open_add_modal',
        activity_type: 'view',
        entity_type: 'employee',
        description: 'Opened Add New Employee modal'
    });
});

// Log when Import modal is opened
$('#importEmployeesModal').on('show.bs.modal', function() {
    $.post(APP_URL + '/api/log_audit', {
        action: 'open_import_modal',
        activity_type: 'view',
        entity_type: 'employee',
        description: 'Opened Import Employees modal'
    });
});

// Define showStep BEFORE it is used
window.togglePaymentFrequencyOther = function(val) {
    const otherDiv = $('#payment_frequency_other_div');
    const otherInput = $('#payment_frequency_other');
    if (val === 'other') {
        otherDiv.removeClass('d-none');
        otherInput.attr('required', true).focus();
    } else {
        otherDiv.addClass('d-none');
        otherInput.attr('required', false).val('');
    }
};

window.toggleDocUpload = function(docType) {
    const mapping = {
        'intro_letter': '#intro_letter_attached',
        'app_letter': '#app_letter_attached',
        'other_doc': '#other_doc_attached'
    };
    
    const isChecked = $(mapping[docType]).is(':checked');
    const uploadDiv = $('#' + docType + '_upload_div');
    const fileInput = $('#' + docType + '_file');
    
    if (isChecked) {
        uploadDiv.removeClass('d-none');
        fileInput.attr('required', true);
        if (docType === 'other_doc') $('#other_doc_name').attr('required', true);
    } else {
        uploadDiv.addClass('d-none');
        fileInput.attr('required', false).val('').removeClass('is-invalid');
        if (docType === 'other_doc') $('#other_doc_name').attr('required', false).val('');
    }
};

window.showStep = function(n) {
    console.log("showStep called with n=" + n + ", current window.currentStep=" + window.currentStep);
    
    const steps = document.querySelectorAll(".wizard-step");
    if (!steps.length) return;
    
    // Update currentStep FIRST before any other operations
    window.currentStep = n;
    
    // Hide all steps
    steps.forEach(step => step.style.display = "none");
    
    // Show current step
    steps[n].style.display = "block";
    
    // Update Header Indicator (if element exists)
    const indicator = document.getElementById("stepIndicator");
    if (indicator) {
        indicator.innerText = `Step ${n + 1} of ${window.totalSteps}: ${window.stepTitles[n]}`;
    }
    
    // Update Progress Bar
    const percent = ((n + 1) / window.totalSteps) * 100;
    const progressBar = document.getElementById("wizardProgressBar");
    if (progressBar) {
        progressBar.style.width = `${percent}%`;
        progressBar.setAttribute("aria-valuenow", percent);
    }
    
    // Button Visibility Logic
    const prevBtn = document.getElementById("prevBtn");
    const nextBtn = document.getElementById("nextBtn");
    const saveBtn = document.getElementById("saveBtn");
    
    if (prevBtn && nextBtn && saveBtn) {
        // Previous Button: Hide on first step, show otherwise
        if (n === 0) {
            prevBtn.style.display = "none";
        } else {
            prevBtn.style.display = "inline-block";
        }
        
        // Next/Save Buttons:
        if (n === (window.totalSteps - 1)) {
            // Last step: Hide Next, Show Save
            nextBtn.style.display = "none";
            saveBtn.style.display = "inline-block";
        } else {
            // Middle steps: Show Next, Hide Save
            nextBtn.style.display = "inline-block";
            saveBtn.style.display = "none";
        }
    }
};

window.nextTab = function(n) {
    try {
        // If moving forward (n=1), validate current step first
        if (n === 1) {
            if (!window.validateForm()) {
                return false;
            }
        }
        
        // Update step index
        const nextStepIndex = window.currentStep + n;
        
        // Boundary checks
        if (nextStepIndex >= window.totalSteps) return false;
        if (nextStepIndex < 0) return false;
        
        window.showStep(nextStepIndex);
    } catch (err) {
        console.error("Wizard Error: " + err.message);
        Swal.fire('Error', 'An error occurred. Please try refreshing the page.', 'error');
    }
};

window.validateForm = function() {
    try {
        let valid = true;
        const steps = document.querySelectorAll(".wizard-step");
        if (!steps.length) return true; // Safety check
        
        const currentPane = steps[window.currentStep];
        if (!currentPane) return false;
        
        // Get required inputs in current pane
        const inputs = currentPane.querySelectorAll("input[required], select[required], textarea[required]");
        
        inputs.forEach(input => {
            if (!input.value.trim()) {
                input.classList.add("is-invalid");
                valid = false;
            } else {
                input.classList.remove("is-invalid");
            }
        });

        if (!valid) {
            let msgDiv = document.getElementById("add-employee-message");
            if (msgDiv) {
                msgDiv.innerHTML = '<div class="alert alert-warning py-2 mb-3"><i class="bi bi-exclamation-triangle-fill"></i> Please fill in all required fields.</div>';
                setTimeout(() => msgDiv.innerHTML = '', 5000);
            }
        }
        
        return valid;
    } catch (e) {
        console.error("Validation error:", e);
        return false;
    }
};

window.validateAllSteps = function() {
    try {
        let allValid = true;
        let firstInvalidStep = -1;
        const steps = document.querySelectorAll(".wizard-step");
        
        // Check each step for required fields
        steps.forEach((step, index) => {
            const inputs = step.querySelectorAll("input[required], select[required], textarea[required]");
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add("is-invalid");
                    allValid = false;
                    if (firstInvalidStep === -1) {
                        firstInvalidStep = index;
                    }
                } else {
                    input.classList.remove("is-invalid");
                }
            });
        });
        
        if (!allValid) {
            window.showStep(firstInvalidStep);
            
            // Check if missing items are in the documents step (last step)
            if (firstInvalidStep === (window.totalSteps - 1)) {
                let missingDocs = [];
                if (!$('#cv_file').val() && $('#cv_file').attr('required')) missingDocs.push('CV/Resume');
                if (!$('#id_file').val() && $('#id_file').attr('required')) missingDocs.push('ID Copy');
                if (!$('#certificates_file').val() && $('#certificates_file').attr('required')) missingDocs.push('Certificates');
                
                if (missingDocs.length > 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Missing Documents',
                        text: 'Allow the process by attaching' + missingDocs.join(', '),
                        confirmButtonColor: '#3085d6'
                    });
                }
            }
            return false;
        }
        
        return true;
    } catch (e) {
        console.error("Validation error:", e);
        return false;
    }
};

window.submitEmployeeForm = function() {
    if (!window.validateAllSteps()) return false;
    
    const employeeId = $('#employee_id').val();
    const isEditMode = employeeId && employeeId !== '';
    const apiUrl = isEditMode ? APP_URL + '/api/update_employee' : APP_URL + '/api/add_employee';
    
    let formData = new FormData(document.getElementById('addEmployeeForm'));
    const submitBtn = $('#saveBtn');
    const selectedProjectId = $('#project_id').val();   // capture before the modal resets the form

    $.ajax({
        url: apiUrl,
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        dataType: 'json',
        beforeSend: function() {
            submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status"></span> Saving...');
        },
        success: function(response) {
            if (response.success) {
                $('#addEmployeeModal').modal('hide');
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message || 'Employee record saved successfully.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#28a745'
                }).then(() => {
                    redirectAfterEmployeeSave(selectedProjectId);
                });
            } else {
                Swal.fire('Error', response.message, 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Employee');
            }
        },
        error: function() {
            Swal.fire('Error', 'An error occurred while saving.', 'error');
            submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Employee');
        }
    });
};

$(document).ready(function() {
    // Initialize others if needed
    // Reset wizard logic
    $('#addEmployeeModal').on('show.bs.modal', function () {
        if (!window.isEditingEmployee) {
            $('#addEmployeeForm')[0].reset();
            $('#employee_id').val('');
            $('#addEmployeeModalLabel').html('<i class="bi bi-plus-circle"></i> Add New Employee');
            
            // Reset Documents Wizards
            $('#intro_letter_upload_div, #app_letter_upload_div, #other_doc_upload_div').addClass('d-none');
            $('#cv_file, #id_file, #certificates_file, #intro_letter_file, #app_letter_file, #other_doc_file').val('').removeClass('is-invalid');
            $('#cv_file, #id_file, #certificates_file').attr('required', true);
            $('#intro_letter_file, #app_letter_file, #other_doc_file').attr('required', false);
            $('.existing-doc-link').remove();

            // Reset Department/Designation "Other (specify)" state
            toggleEmpOther('department_id', 'department_other_box', 'department_other', false);
            toggleEmpOther('designation_id', 'designation_other_box', 'designation_other', false);
        }
        window.currentStep = 0;
        window.showStep(0);
        $('#add-employee-message').html('');
    });
    
    $('#addEmployeeModal').on('hidden.bs.modal', function () {
        window.isEditingEmployee = false;
    });
});

function applyFilters() {
    const table = $('#employeesTable').DataTable();
    
    // Get filter values
    const status = $('#statusFilter').val();
    const department = $('#departmentFilter').val();
    const designation = $('#designationFilter').val();
    const empType = $('#employmentTypeFilter').val();
    const search = $('#searchEmployees').val().toLowerCase();

    // Log filter action


    // 1. Table Filtering
    if (status) {
        table.column(8).search('^' + status + '$', true, false);
    } else {
        table.column(8).search('');
    }
    
    if (department) {
        table.column(3).search(department);
    } else {
        table.column(3).search('');
    }
    
    if (designation) {
        // Find designation name from select
        const desText = $('#designationFilter option:selected').text();
        if (designation !== "") table.column(3).search(desText);
    }
    
    table.search(search).draw();

    // 2. Card Filtering
    $('.employee-card').each(function() {
        const card = $(this);
        const matchName = !search || card.data('name').includes(search);
        const matchStatus = !status || card.data('status') === status;
        const matchDept = !department || card.data('dept') == department;
        const matchDes = !designation || card.data('designation') == designation;
        const matchType = !empType || card.data('type') == empType;

        if (matchName && matchStatus && matchDept && matchDes && matchType) {
            card.fadeIn(200);
        } else {
            card.fadeOut(200);
        }
    });

    // Update statistics badge or message if no results in card view
    setTimeout(() => {
        const visibleCards = $('.employee-card:visible').length;
        if (visibleCards === 0 && $('#cardView:visible').length > 0) {
            if ($('#noCardsMsg').length === 0) {
                $('#cardView').after('<div id="noCardsMsg" class="text-center py-5"><i class="bi bi-search" style="font-size: 3rem; color: #ccc;"></i><h5 class="mt-3 text-muted">No matching employees found</h5></div>');
            }
        } else {
            $('#noCardsMsg').remove();
        }
    }, 300);
}

function clearFilters() {
    $('#statusFilter').val('');
    $('#departmentFilter').val('');
    $('#designationFilter').val('');
    $('#employmentTypeFilter').val('');
    $('#searchEmployees').val('');
    
    const table = $('#employeesTable').DataTable();
    table.search('').columns().search('').draw();

    // Reset cards
    $('.employee-card').fadeIn(200);
    $('#noCardsMsg').remove();
}

function editEmployee(employeeId) {
    // Log intent to edit


    // Set flag to prevent form reset
    window.isEditingEmployee = true;
    
    // Fetch employee data and populate wizard modal
    $.ajax({
        url: APP_URL + '/api/get_employee?id=' + employeeId,
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const emp = response.data;
                
                // Set employee ID for update mode
                $('#employee_id').val(emp.employee_id);
                
                // Change modal title
                $('#addEmployeeModalLabel').html('<i class="bi bi-pencil"></i> Edit Employee');
                
                // Step 0: Personal Information
                $('#first_name').val(emp.first_name || '');
                $('#middle_name').val(emp.middle_name || '');
                $('#last_name').val(emp.last_name || '');
                $('#gender').val(emp.gender || '');
                $('#date_of_birth').val(emp.date_of_birth || '');
                $('#marital_status').val(emp.marital_status || '');
                $('#national_id').val(emp.national_id || '');
                $('#passport_number').val(emp.passport_number || '');
                $('#notes').val(emp.notes || '');
                
                // Step 1: Employment Details
                $('#employee_number').val(emp.employee_number || '');
                $('#hire_date').val(emp.hire_date || '');
                $('#department_id').val(emp.department_id || '');
                $('#designation_id').val(emp.designation_id || '');
                $('#employment_type_id').val(emp.employment_type_id || '');
                $('#employment_status').val(emp.employment_status || '');
                populateReportingTo(emp);
                $('#work_location').val(emp.work_location || '');
                $('#project_id').val(emp.project_id || '');
                $('#basic_salary').val(emp.basic_salary || '');
                $('#probation_end_date').val(emp.probation_end_date || '');
                $('#contract_end_date').val(emp.contract_end_date || '');
                
                // Handle Payment Frequency (including "Other")
                const standardFrequencies = ['monthly', 'biweekly', 'weekly', 'daily', 'hourly'];
                if (emp.payment_frequency && !standardFrequencies.includes(emp.payment_frequency)) {
                    $('#payment_frequency').val('other');
                    $('#payment_frequency_other_div').removeClass('d-none');
                    $('#payment_frequency_other').val(emp.payment_frequency).attr('required', true);
                } else {
                    $('#payment_frequency').val(emp.payment_frequency || 'monthly');
                    $('#payment_frequency_other_div').addClass('d-none');
                    $('#payment_frequency_other').val('').attr('required', false);
                }

                $('#payment_method').val(emp.payment_method || 'bank');
                $('#currency').val(emp.currency || 'TZS');
                $('#hourly_rate').val(emp.hourly_rate || '');
                $('#social_security_number').val(emp.social_security_number || '');
                $('#tax_id').val(emp.tax_id || '');

                // Reset and Populate Benefits
                $('input[name="benefits[]"]').prop('checked', false);
                if (emp.benefits) {
                    try {
                        const benefits = JSON.parse(emp.benefits);
                        if (Array.isArray(benefits)) {
                            benefits.forEach(benefit => {
                                $(`input[name="benefits[]"][value="${benefit}"]`).prop('checked', true);
                            });
                        }
                    } catch (e) {
                        // Fallback if benefits is stored as comma string
                        emp.benefits.split(',').forEach(benefit => {
                            $(`input[name="benefits[]"][value="${benefit.trim()}"]`).prop('checked', true);
                        });
                    }
                }

                $('#email').val(emp.email || '');
                $('#phone').val(emp.phone || '');
                $('#alternate_phone').val(emp.alternate_phone || '');
                $('#emergency_contact').val(emp.emergency_contact || '');
                $('#emergency_contact_relationship').val(emp.emergency_contact_relationship || '');
                $('#emergency_contact_phone').val(emp.emergency_contact_phone || '');
                $('#emergency_contact_postal_address').val(emp.emergency_contact_postal_address || '');
                $('#emergency_contact_physical_address').val(emp.emergency_contact_physical_address || '');
                $('#emergency_contact_email').val(emp.emergency_contact_email || '');
                $('#physical_address').val(emp.physical_address || emp.address || '');
                $('#postal_address').val(emp.postal_address || '');

                // Location cascade prefill — matches stored names against the
                // defined lists; unmatched legacy values are kept as extra
                // options instead of being wiped.
                employeeLocationCascade.setValues({
                    country:  emp.country || 'Tanzania',
                    region:   emp.state || '',
                    district: emp.city || '',
                    ward:     emp.ward || '',
                    village:  emp.village || ''
                });
                
                // Step 4: Bank & Documents
                $('#bank_name').val(emp.bank_name || '');
                $('#bank_account').val(emp.bank_account || '');
                $('#bank_branch').val(emp.bank_branch || '');
                $('#mobile_money').val(emp.mobile_money || '');
                $('#additional_notes').val(emp.additional_notes || '');
                
                // --- Reset Documents Section ---
                $('#intro_letter_attached, #app_letter_attached, #other_doc_attached').prop('checked', false);
                $('#intro_letter_upload_div, #app_letter_upload_div, #other_doc_upload_div').addClass('d-none');
                $('#cv_file, #id_file, #certificates_file, #intro_letter_file, #app_letter_file, #other_doc_file').attr('required', false).val('');
                $('#other_doc_name').val('');
                $('.existing-doc-link').remove();

                // --- Populate Documents Section ---
                // Mandatory docs are always visible now, but in edit mode we only require them if not already there
                $('#cv_file, #id_file, #certificates_file').attr('required', false);

                if (emp.documents) {
                    try {
                        const docs = JSON.parse(emp.documents);
                        
                        // Handle mandatory displays
                        if (docs.cv) $('#cv_file').closest('.col-md-4').append(`<div class="existing-doc-link mt-1 small"><i class="bi bi-file-earmark-check text-success"></i> <a href="${APP_URL}/${docs.cv}" target="_blank">Current CV</a></div>`);
                        else $('#cv_file').attr('required', true); // Still missing in DB? Make required

                        if (docs.id) $('#id_file').closest('.col-md-4').append(`<div class="existing-doc-link mt-1 small"><i class="bi bi-file-earmark-check text-success"></i> <a href="${APP_URL}/${docs.id}" target="_blank">Current ID</a></div>`);
                        else $('#id_file').attr('required', true);

                        if (docs.certificates) $('#certificates_file').closest('.col-md-4').append(`<div class="existing-doc-link mt-1 small"><i class="bi bi-file-earmark-check text-success"></i> <a href="${APP_URL}/${docs.certificates}" target="_blank">Current Certificates</a></div>`);
                        else $('#certificates_file').attr('required', true);

                        // Handle optional displays
                        const optionalMappings = {
                            'intro_letter': { check: '#intro_letter_attached', div: '#intro_letter_upload_div', input: '#intro_letter_file' },
                            'app_letter': { check: '#app_letter_attached', div: '#app_letter_upload_div', input: '#app_letter_file' },
                            'other_doc': { check: '#other_doc_attached', div: '#other_doc_upload_div', input: '#other_doc_file', name: '#other_doc_name' }
                        };

                        if (emp.other_doc_name) $('#other_doc_name').val(emp.other_doc_name);

                        for (const key in docs) {
                            if (docs[key] && optionalMappings[key]) {
                                const map = optionalMappings[key];
                                $(map.check).prop('checked', true);
                                $(map.div).removeClass('d-none');
                                $(map.input).attr('required', false);
                                $(map.div).append(`<div class="existing-doc-link mt-1 small"><i class="bi bi-file-earmark-check text-success"></i> <a href="${APP_URL}/${docs[key]}" target="_blank">Current File</a></div>`);
                            }
                        }
                    } catch (e) { console.error("Error parsing documents JSON", e); }
                } else {
                    // No documents at all? Make mandatory ones required
                    $('#cv_file, #id_file, #certificates_file').attr('required', true);
                }
                // -------------------------------
                
                // Clear validation errors
                $('#addEmployeeForm').find('.is-invalid').removeClass('is-invalid');
                $('#add-employee-message').html('');
                
                // Show modal and reset to first step
                window.currentStep = 0;
                window.showStep(0);
                $('#addEmployeeModal').modal('show');
            } else {
                window.isEditingEmployee = false;
                Swal.fire({
                    icon: 'error',
                    title: 'Load Error',
                    text: 'Error loading employee data: ' + response.message
                });
            }
        },
        error: function(xhr, status, error) {
            window.isEditingEmployee = false;
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Server Error',
                text: 'Error loading employee data. Please try again.'
            });
        }
    });
}

function updateStatus(employeeId, status) {
    // Log intent to update status
    $.post(APP_URL + '/api/log_audit', {
        action: 'update_status_intent',
        activity_type: 'update',
        entity_type: 'employee',
        entity_id: employeeId,
        description: `User initiated status update for employee (ID: ${employeeId}, New Status: ${status})`
    });

    const actionMap = {
        'active': 'activate',
        'probation': 'move to probation',
        'contract': 'change to contract',
        'on_leave': 'mark as on leave',
        'resigned': 'mark as resigned',
        'terminated': 'terminate'
    };
    
    const action = actionMap[status] || 'update';
    
    Swal.fire({
        title: 'Are you sure?',
        text: 'Do you want to ' + action + ' this employee?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, ' + action + ' them!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/update_employee_status',
                type: 'POST',
                data: { 
                    employee_id: employeeId,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message || 'Employee status updated.',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#28a745'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Update Failed',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Server Error',
                        text: 'Error updating status. Please try again.'
                    });
                }
            });
        }
    });
}

function confirmDelete(employeeId) {
    // Log intent to delete


    Swal.fire({
        title: 'Delete Employee?',
        text: 'Are you sure you want to delete this employee? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, delete them!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/delete_employee',
                method: 'POST',
                data: { employee_id: employeeId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: 'Employee record has been deleted.',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#28a745'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Delete Failed',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Server Error',
                        text: 'Error deleting employee. Please try again.'
                    });
                }
            });
        }
    });
}

function toggleView(viewType) {
    const isMobile = window.innerWidth <= 767;
    // On mobile, always force card view
    if (isMobile) viewType = 'card';

    const tableView = $('#tableView');
    const cardView = $('#cardView');
    const tableBtn = $('#btn-table-view');
    const cardBtn = $('#btn-card-view');

    if (viewType === 'table') {
        tableView.removeClass('d-none');
        cardView.addClass('d-none');
        tableBtn.css({'background-color': '#f8f9fa', 'font-weight': 'bold'});
        cardBtn.css({'background-color': '#fff', 'font-weight': 'normal'});
    } else {
        tableView.addClass('d-none');
        cardView.removeClass('d-none');
        cardBtn.css({'background-color': '#f8f9fa', 'font-weight': 'bold'});
        tableBtn.css({'background-color': '#fff', 'font-weight': 'normal'});
    }

    // Only persist desktop preference
    if (!isMobile) localStorage.setItem('employeesView', viewType);
}

// Load view on page load
$(document).ready(function() {
    const savedView = window.innerWidth <= 767 ? 'card' : (localStorage.getItem('employeesView') || 'table');
    toggleView(savedView);
});

// Enforce card on mobile when orientation/size changes
$(window).on('resize', function() {
    if (window.innerWidth <= 767) toggleView('card');
});

function printEmployees() {
    $.post(APP_URL + '/api/log_audit', {
        action: 'print_list',
        activity_type: 'print',
        entity_type: 'employee',
        description: 'Printed employee list'
    }).always(function() {
        window.print();
    });
}

function exportEmployees() {
    $.post(APP_URL + '/api/log_audit', {
        action: 'export_list',
        activity_type: 'export',
        entity_type: 'employee',
        description: 'Exported employee list'
    });
    // Trigger DataTable export
    $('#employeesTable').DataTable().button('.buttons-excel').trigger();
}

function downloadTemplate() {
    // Log template download
    $.post(APP_URL + '/api/log_audit', {
        action: 'download_template',
        activity_type: 'export',
        entity_type: 'employee',
        description: 'Downloaded employee import template'
    });

    // Create a CSV template file
    const headers = [
        'employee_number', 'first_name', 'middle_name', 'last_name', 'gender',
        'date_of_birth', 'email', 'phone', 'emergency_contact', 'address',
        'city', 'country', 'national_id', 'hire_date', 'department_id',
        'designation_id', 'employment_type_id', 'employment_status',
        'basic_salary', 'currency', 'payment_frequency', 'payment_method'
    ];
    
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\nEMP-001,John,,Doe,male,1990-01-15,john.doe@company.com,+255123456789,Emergency: +255987654321,123 Main St,Dar es Salaam,Tanzania,123456789,2023-01-01,1,1,1,active,500000,TZS,monthly,bank";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "employees_import_template.csv");
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
.bg-success-soft { background-color: #d1e7dd !important; }

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

/* Premium Button Hover Effect */
.action-btn-premium {
    transition: all 0.3s ease !important;
}

.action-btn-premium:hover {
    background-color: #0d6efd !important;
    color: white !important;
    border-color: #0d6efd !important;
    box-shadow: 0 4px 8px rgba(13, 110, 253, 0.3) !important;
    transform: translateY(-2px);
}

.action-btn-premium:hover i {
    color: white !important;
}

.btn-primary:hover {
    background-color: #0b5ed7 !important;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.45) !important;
}

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}
#employeesTable {
    width: 100% !important;
}

.badge {
    font-size: 0.75em;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-info,
.card.bg-warning {
    border: none;
}

/* Card view styling */
#cardView .card {
    transition: all 0.3s cubic-bezier(.25,.8,.25,1);
    border: 1px solid rgba(0,0,0,0.08);
    border-radius: 15px;
    overflow: hidden;
}

#cardView .card:hover {
    transform: translateY(-8px);
    box-shadow: 0 14px 28px rgba(0,0,0,0.1), 0 10px 10px rgba(0,0,0,0.08);
}

#cardView .card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid rgba(0,0,0,0.05);
    padding: 1rem;
}

#cardView .card-footer {
    background-color: #fff;
    border-top: 1px solid rgba(0,0,0,0.05);
    padding: 0.75rem 1rem;
}

/* Avatar placeholder */
.avatar-placeholder {
    background: linear-gradient(45deg, #0d6efd, #0b5ed7);
    font-weight: bold;
}

/* Tab styling */
.nav-tabs .nav-link {
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
}

.nav-tabs .nav-link.active {
    font-weight: 600;
}

/* Print styles */
@media print {
    .navbar, .card-header, .btn, .dropdown, .dataTables_length, 
    .dataTables_filter, .dataTables_info, .dataTables_paginate, 
    .dt-buttons {
        display: none !important;
    }
    
    .card {
        border: none;
        box-shadow: none;
    }
    
    .card-body {
        padding: 0;
    }
    
    table {
        width: 100% !important;
    }
}

/* Responsive adjustments */
@media (max-width: 767px) {
    .page-top-navbar, .navbar {
        position: sticky;
        top: 0;
        z-index: 1020;
    }
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    #cardView .col-xl-3 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    
    .nav-tabs .nav-item {
        white-space: nowrap;
    }
}

@media (max-width: 576px) {
    .btn-group {
        width: 100%;
        margin-top: 0.5rem;
    }
    
    .btn-group .btn {
        flex: 1;
    }
    
    .table-responsive {
        font-size: 0.85rem;
    }
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}
.card {
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.table thead th {
    background-color: #f8f9fa !important;
    color: #212529 !important;
    border: 1px solid #dee2e6 !important;
    font-weight: 600;
}

/* Print styles */
@media print {
    #printHeader {
        display: block !important;
        margin-bottom: 10px;
        padding-bottom: 5px;
    }
    
    .container-fluid {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }
    
    .d-print-none, 
    .navbar, 
    .card-header, 
    .btn, 
    .dropdown, 
    .dataTables_length, 
    .dataTables_filter, 
    .dataTables_info, 
    .dataTables_paginate, 
    .dt-buttons,
    .breadcrumb {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    
    .card-body {
        padding: 0 !important;
    }
    
    .table {
        font-size: 11px;
        width: 100%;
    }
    
    .table thead th {
        background-color: #f8f9fa !important;
        border: 1px solid #000 !important;
        padding: 8px 4px !important;
        font-weight: bold;
    }
    
    .table td {
        border: 1px solid #ddd !important;
        padding: 6px 4px !important;
    }
    
    tr {
        page-break-inside: avoid;
    }

    .table th:last-child,
    .table td:last-child {
        display: none !important;
    }

    /* Page Margin & Footer Protection */
    @page {
        margin-bottom: 80px !important;
        margin-top: 30px !important;
    }

    .fixed-print-footer { 
        position: fixed; 
        bottom: 0; 
        left: 0; 
        right: 0; 
        width: 100%;
        text-align: center;
        background: white !important;
        padding-bottom: 15px;
        z-index: 9999;
    }

    tfoot {
        display: table-footer-group !important;
    }

    .table {
        border-collapse: collapse !important;
    }
}
</style>

<?php
// Include the footer
include("footer.php");

?>
<script>
// Check for edit_id in URL to auto-open modal
$(document).ready(function() {
    // Log View Action
    $.post(APP_URL + '/api/log_audit', {
        action: 'view_list',
        activity_type: 'view',
        entity_type: 'employee',
        description: 'Viewed employee list page'
    });

    const urlParams = new URLSearchParams(window.location.search);
    const editId = urlParams.get('edit_id');
    const action = urlParams.get('action');
    // Project context resolved server-side (clean URL: ?project=<id>&back=<tab>).
    const projLock  = <?= $proj_ctx_id ?: 'null' ?>;   // opened from inside a project → lock onto it
    const projName  = <?= json_encode($proj_ctx_name) ?>;
    window.__projReturnUrl = <?= $proj_ctx_return !== '' ? json_encode($proj_ctx_return) : 'null' ?>;  // return here after save

    // After a successful create/edit: if a project is selected on the form, go
    // straight to that project's Staff tab; otherwise return to the project we
    // came from (if any), else just refresh the list.
    window.redirectAfterEmployeeSave = function(pidArg) {
        // pidArg is captured before the modal resets the form; fall back to the
        // live field for handlers that don't reset before redirecting.
        const pid = ((pidArg != null ? pidArg : $('#project_id').val()) || '').toString().trim();
        if (pid) {
            window.location.href = '<?= getUrl('project_view') ?>?id=' + encodeURIComponent(pid) + '&tab=staff';
        } else if (window.__projReturnUrl) {
            window.location.href = window.__projReturnUrl;
        } else {
            location.reload();
        }
    };

    // When opened from a project, force the project field to that project and lock it.
    function lockEmpProject() {
        if (!projLock) return;
        const $sel = $('#project_id');
        if (!$sel.length) return;
        if ($sel.find('option[value="' + projLock + '"]').length === 0) {
            const label = projName || ('Project #' + projLock);
            $sel.append(new Option(label, projLock, true, true));
        }
        $sel.val(projLock).trigger('change');
        $sel.prop('disabled', true).trigger('change.select2');
        const $form = $sel.closest('form');
        if ($form.find('input[type=hidden][name="project_id"]').length === 0) {
            $('<input type="hidden" name="project_id">').val(projLock).appendTo($form);
        }
    }
    $('#addEmployeeModal').on('shown.bs.modal', lockEmpProject);

    if (editId) {
        // Small delay to ensure functions are loaded
        setTimeout(function() {
            if (typeof editEmployee === 'function') {
                editEmployee(editId);
            }
        }, 300);
    } else if (action === 'new') {
        setTimeout(function() {
            $('#addEmployeeModal').modal('show');
        }, 500);
    }

    // Delegate clicks for table actions (View, Attendance, Payroll)
    $('#employeesTable').on('click', '.dropdown-item', function() {
        const text = $(this).text().trim();
        const href = $(this).attr('href');
        if (href && href !== '#' && !href.startsWith('javascript:')) {
            $.post(APP_URL + '/api/log_audit', {
                action: 'click_action',
                activity_type: 'view',
                entity_type: 'employee',
                description: `User clicked '${text}' in employee list`
            });
  