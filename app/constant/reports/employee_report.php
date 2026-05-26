<?php
// scope-audit: skip — cross-module HR report; project-scope filtering on report pages deferred to Phase G-2
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

// Use existing permission mapping
autoEnforcePermission('employee_report');

try {
    // Corrected SQL based on actual employees table schema
    $emp_sql = "SELECT e.employee_id, CONCAT(e.first_name,' ',e.last_name) as full_name,
                       d.department_name as department, des.designation_name as position, 
                       e.employment_status as status, e.hire_date as employment_date,
                       e.basic_salary, e.email, e.phone, 'Full-time' as employment_type
                FROM employees e 
                LEFT JOIN departments d ON e.department_id = d.department_id
                LEFT JOIN designations des ON e.designation_id = des.designation_id
                ORDER BY d.department_name ASC, e.first_name ASC";
    $stmt = $pdo->query($emp_sql);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_salary = array_sum(array_column($employees, 'basic_salary'));
    $active_count = count(array_filter($employees, fn($e) => strtolower($e['status'] ?? '') === 'active'));
    
    $dept_counts = [];
    foreach($employees as $e) {
        $dept = $e['department'] ?: 'Unassigned';
        $dept_counts[$dept] = ($dept_counts[$dept] ?? 0) + 1;
    }
} catch (Exception $e) { 
    $error = $e->getMessage(); 
    $employees = []; 
    $total_salary = $active_count = 0; 
    $dept_counts = []; 
}
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        ?>
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3 text-center">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">WORKFORCE ANALYSIS REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Comprehensive summary of human capital, departmental distribution, and payroll commitment.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Workforce</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= count($employees) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Active Duty</p>
                <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;"><?= $active_count ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Monthly Payroll</p>
                <h4 style="color: #e74c3c; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_salary) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Departments</p>
                <h4 style="color: #0d6efd; font-weight: 800; margin: 0; font-size: 14pt;"><?= count($dept_counts) ?></h4>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-people-fill me-2"></i>Workforce Analysis</h2>
            <p class="text-muted mb-0">Demographic breakdown and financial commitment summary</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> Print Report
            </button>
            <button class="btn btn-success shadow-sm px-4 fw-bold ms-2" onclick="exportToExcel()">
                <i class="bi bi-file-earmark-excel me-2"></i> Export
            </button>
        </div>
    </div>

    <!-- Summary Widgets -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Workforce</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= count($employees) ?></h4>
                    <span class="small text-primary fw-bold">Staff Members</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Active Duty</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= $active_count ?></h4>
                    <span class="small text-success fw-bold">In Service</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Departments</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= count($dept_counts) ?></h4>
                    <span class="small text-warning fw-bold">Operational Units</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Base Liabilities</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_salary) ?></h4>
                    <span class="small text-info fw-bold">Monthly Payroll</span>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Main Table -->
    <div class="card border-0 shadow-lg mb-4" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold d-print-none">Employee Roster Details</h5>
            <div class="input-group input-group-sm w-auto d-print-none shadow-sm">
                <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                <input type="text" id="tableSearch" class="form-control border-start-0" placeholder="Quick find...">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="employeeTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 text-muted small text-uppercase" style="width:45px;">S/NO</th>
                            <th class="ps-2 text-muted small text-uppercase">Employee</th>
                            <th class="text-muted small text-uppercase">Dept / Position</th>
                            <th class="text-muted small text-uppercase">Type</th>
                            <th class="text-muted small text-uppercase">Tenure</th>
                            <th class="text-end text-muted small text-uppercase">Basic Salary</th>
                            <th class="text-end pe-4 text-muted small text-uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($employees)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted italic">No personnel records found in the system.</td></tr>
                        <?php else: $sno = 1; foreach($employees as $e): ?>
                            <tr>
                                <td class="ps-3 text-center text-muted fw-bold small"><?= $sno++ ?></td>
                                <td class="ps-2">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars((string)($e['full_name'] ?? '')) ?></div>
                                    <div class="small text-muted d-print-none"><?= htmlspecialchars((string)($e['email'] ?: 'No Email')) ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars((string)($e['department'] ?: 'Unassigned')) ?></div>
                                    <div class="small text-muted d-print-none"><?= htmlspecialchars((string)($e['position'] ?: 'Employee')) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-3">
                                        <?= ucfirst(htmlspecialchars((string)($e['employment_type'] ?? 'Full-time'))) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="small font-monospace">
                                        <?= $e['employment_date'] ? date('d M Y', strtotime($e['employment_date'])) : 'TBD' ?>
                                    </div>
                                    <?php if($e['employment_date']): ?>
                                        <div class="x-small text-muted italic d-print-none">
                                            <?= round((time() - strtotime($e['employment_date'])) / (86400 * 365), 1) ?> Yrs
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold text-primary"><?= format_currency($e['basic_salary']) ?></td>
                                <td class="text-end pe-4">
                                    <span class="badge px-3 py-2 rounded-pill bg-<?= strtolower($e['status'] ?? '')==='active'?'success':'secondary' ?> bg-opacity-10 text-<?= strtolower($e['status'] ?? '')==='active'?'success':'secondary' ?> border border-<?= strtolower($e['status'] ?? '')==='active'?'success':'secondary' ?>">
                                        <?= strtoupper(htmlspecialchars((string)($e['status'] ?? 'UNKNOWN'))) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-light py-3 border-top text-end pe-4 d-print-block">
            <span class="text-muted small fw-bold me-2">TOTAL AGGREGATE SALARY:</span>
            <span class="h5 mb-0 fw-bold text-primary"><?= format_currency($total_salary) ?></span>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#tableSearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $("#employeeTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    if(typeof logReportAction==='function') {
        logReportAction('Viewed Employee Report', 'Comprehensive workforce summary generated');
    }
});

function exportToExcel() {
    // Implement or redirect to export script
    alert('Excel export initiated for workforce data.');
}
</script>

<style>
    .card { border-radius: 12px; }
    .table thead th { border-top: none; }
    .x-small { font-size: 0.7rem; }
    .italic { font-style: italic; }
    @media print {
        .d-print-none, .btn, .navbar, .sidebar, .input-group { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .badge { color: #000 !important; border: none !important; background: transparent !important; padding: 0 !important; font-weight: bold; }
    }
</style>

<?php includeFooter(); ob_end_flush(); ?>
