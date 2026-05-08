<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('attendance');

// Fetch company settings for print header
$c_name = getSetting('company_name', 'BMS');
$c_logo = getSetting('company_logo', '');

// Include the header
includeHeader();

// Permission flags for UI elements
$can_edit_attendance = isAdmin() || canEdit('attendance');
$can_approve_attendance = isAdmin() || canEdit('attendance'); // Or specialized if needed


// Get filters
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'day'; // day, week, month
$current_date = date('Y-m-d');
$current_month = date('Y-m');

// Initialize date variables
$selected_date = isset($_GET['date']) ? $_GET['date'] : $current_date;
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month;
$selected_week = isset($_GET['week']) ? $_GET['week'] : date('Y-\nW');

// Calculate date range based on view mode
$start_date = $selected_date;
$end_date = $selected_date;

if ($view_mode == 'week') {
    // If week is YYYY-Www format
    if (strpos($selected_week, '-W') !== false) {
        $dto = new DateTime();
        $dto->setISODate(substr($selected_week, 0, 4), substr($selected_week, 6));
        $start_date = $dto->format('Y-m-d');
        $dto->modify('+6 days');
        $end_date = $dto->format('Y-m-d');
    } else {
        // Fallback to current week
        $start_date = date('Y-m-d', strtotime('monday this week', strtotime($selected_date)));
        $end_date = date('Y-m-d', strtotime('sunday this week', strtotime($selected_date)));
    }
} elseif ($view_mode == 'month') {
    $start_date = date('Y-m-01', strtotime($selected_month));
    $end_date = date('Y-m-t', strtotime($selected_month));
}

$selected_department = isset($_GET['department']) ? (int)$_GET['department'] : null;
$selected_status = isset($_GET['status']) ? $_GET['status'] : '';

// Get departments for filtering
$departments = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

// Get all active employees
$employees_query = "
    SELECT e.*, d.department_name 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.department_id 
    WHERE e.employment_status IN ('active', 'probation', 'contract') 
    AND e.status = 'active'
";

if ($selected_department) {
    $employees_query .= " AND e.department_id = ?";
    $employees_params = [$selected_department];
} elseif (isset($_GET['employee'])) {
    $employees_query .= " AND e.employee_id = ?";
    $employees_params = [$_GET['employee']];
} else {
    $employees_params = [];
}

$employees_query .= " ORDER BY d.department_name, e.first_name, e.last_name";
$employees_stmt = $pdo->prepare($employees_query);
$employees_stmt->execute($employees_params);
$employees = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function format_time($time) {
    return !empty($time) ? date('h:i A', strtotime($time)) : '--:--';
}


function calculate_hours($check_in, $check_out) {
    if (empty($check_in) || empty($check_out)) return '0.00';
    
    $check_in_time = strtotime($check_in);
    $check_out_time = strtotime($check_out);
    
    // Validate times
    if ($check_in_time === false || $check_out_time === false) {
        return '0.00';
    }
    
    // Check if check_out is before or equal to check_in (invalid)
    if ($check_out_time <= $check_in_time) {
        return '0.00';
    }
    
    $hours = ($check_out_time - $check_in_time) / 3600;
    
    // Ensure reasonable hours (max 24 hours per day)
    if ($hours > 24) {
        return '0.00';
    }
    
    return number_format($hours, 2);
}

// safe_output removed, now in helpers.php

function get_day_name($date) {
    return date('l', strtotime($date));
}

function is_weekend($date) {
    $day = date('N', strtotime($date));
    return ($day >= 6); // 6 = Saturday, 7 = Sunday
}

// Get attendance data for selected date
$attendance_data = [];
$attendance_summary = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'half_day' => 0,
    'leave' => 0,
    'holiday' => 0,
    'weekend' => 0,
    'total_employees' => 0
];

if ($employees) {
    // Get attendance records based on view mode
    if ($view_mode == 'day') {
        $attendance_query = "
            SELECT a.*, e.first_name, e.last_name, e.employee_number, e.department_id, d.department_name 
            FROM attendance a 
            LEFT JOIN employees e ON a.employee_id = e.employee_id 
            LEFT JOIN departments d ON e.department_id = d.department_id 
            WHERE a.attendance_date = ?
        ";
        $attendance_params = [$selected_date];
    } else {
        // Aggregated query for week/month
        $attendance_query = "
            SELECT 
                a.employee_id,
                e.first_name, e.last_name, e.employee_number, e.department_id, d.department_name,
                SUM(CASE WHEN a.total_hours > 0 AND a.total_hours <= 24 THEN a.total_hours ELSE 0 END) as total_hours,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) as half_day_count,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
            FROM attendance a 
            LEFT JOIN employees e ON a.employee_id = e.employee_id 
            LEFT JOIN departments d ON e.department_id = d.department_id 
            WHERE a.attendance_date BETWEEN ? AND ?
        ";
        $attendance_params = [$start_date, $end_date];
        
        // Group by
        $attendance_query .= " GROUP BY a.employee_id";
    }

    if ($selected_department) {
        // Note: Logic allows appending AND clause. 
        // For simple handling, checking if WHERE exists is safer, but here we control the query structure.
        // The day query has WHERE. The aggregated query has WHERE.
        // But need to be careful with GROUP BY in aggregated query. 
        // Best to add department condition BEFORE group by.
        
        if ($view_mode == 'day') {
             $attendance_query .= " AND e.department_id = ?";
             $attendance_params[] = $selected_department;
        } else {
             // Re-inject department clause before GROUP BY
             $attendance_query = str_replace("GROUP BY", "AND e.department_id = ? GROUP BY", $attendance_query);
             $attendance_params[] = $selected_department;
        }
    }
    
    $attendance_stmt = $pdo->prepare($attendance_query);
    $attendance_stmt->execute($attendance_params);
    $existing_attendance = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Map attendance by employee_id for easy lookup
    $attendance_map = [];
    foreach ($existing_attendance as $record) {
        $attendance_map[$record['employee_id']] = $record;
    }
    
    // Prepare attendance data for display
    // Prepare attendance data for display
    // Calculate days in period for aggregated view logic
    $days_in_period = 1; 
    if ($view_mode == 'week') {
        $days_in_period = 7;
    } elseif ($view_mode == 'month') {
        $days_in_period = date('t', strtotime($start_date));
    }

    foreach ($employees as $employee) {
        $employee_id = $employee['employee_id'];
        
        if ($view_mode == 'day') {
            if (isset($attendance_map[$employee_id])) {
                // Use existing attendance record
                $attendance_record = $attendance_map[$employee_id];
                $status = $attendance_record['status'];
                $check_in = $attendance_record['check_in_time'];
                $check_out = $attendance_record['check_out_time'];
                $hours = calculate_hours($check_in, $check_out);
                $notes = $attendance_record['notes'];
            } else {
                // Create default record based on day type
                if (is_weekend($selected_date)) {
                    $status = 'weekend';
                    $check_in = null;
                    $check_out = null;
                    $hours = '0.00';
                    $notes = 'Weekend';
                } else {
                    // Check if employee is on leave for this date
                    $leave_check = $pdo->prepare("
                        SELECT * FROM leaves 
                        WHERE employee_id = ? 
                        AND start_date <= ? 
                        AND end_date >= ? 
                        AND status = 'approved'
                    ");
                    $leave_check->execute([$employee_id, $selected_date, $selected_date]);
                    $on_leave = $leave_check->fetch();
                    
                    if ($on_leave) {
                        $status = 'leave';
                        $check_in = null;
                        $check_out = null;
                        $hours = '0.00';
                        $notes = $on_leave['leave_type'] . ' leave';
                    } else {
                        $status = 'absent'; // Default status for work day
                        $check_in = null;
                        $check_out = null;
                        $hours = '0.00';
                        $notes = '';
                    }
                }
            }
            
            // Update summary
            if (isset($attendance_summary[$status])) {
                $attendance_summary[$status]++;
            }
            $attendance_summary['total_employees']++;
            
            $attendance_data[] = [
                'employee_id' => $employee_id,
                'employee_number' => $employee['employee_number'],
                'first_name' => $employee['first_name'],
                'last_name' => $employee['last_name'],
                'department_name' => $employee['department_name'],
                'attendance_date' => $selected_date,
                'check_in_time' => $check_in,
                'check_out_time' => $check_out,
                'total_hours' => $hours,
                'status' => $status,
                'notes' => $notes,
                'existing_record' => isset($attendance_map[$employee_id])
            ];
        } else {
            // Aggregated View Logic (Week/Month)
            if (isset($attendance_map[$employee_id])) {
                $record = $attendance_map[$employee_id];
                $total_hours = floatval($record['total_hours']);
                
                // Use explicit counts from query
                $present_c = intval($record['present_count']);
                $late_c = intval($record['late_count']);
                $half_day_c = intval($record['half_day_count']);
                $absent_c = intval($record['absent_count']);
                
                // Calculate average (Week: /7, Month: /30)
                $divisor = ($view_mode == 'week') ? 7 : 30;
                $avg_hours = $total_hours / $divisor;
                
                // Status for aggregated view is 'present' if they have any attendance activity
                $has_attendance = ($present_c + $late_c + $half_day_c) > 0;
                $status = $has_attendance ? 'present' : 'absent';
                
                // Update Summary for Aggregated View using explicit counts
                $attendance_summary['present'] += $present_c;
                $attendance_summary['late'] += $late_c;
                $attendance_summary['half_day'] += $half_day_c;
                
                // Calculate missing days (days in period with NO record) and treat as absent
                $total_recorded = $present_c + $late_c + $half_day_c + $absent_c;
                $missing_days = max(0, $days_in_period - $total_recorded);
                
                $attendance_summary['absent'] += ($absent_c + $missing_days);
                $attendance_summary['total_employees']++;

                $attendance_data[] = [
                    'employee_id' => $employee_id,
                    'employee_number' => $employee['employee_number'],
                    'first_name' => $employee['first_name'],
                    'last_name' => $employee['last_name'],
                    'department_name' => $employee['department_name'],
                    'total_hours' => number_format($total_hours, 2),
                    'days_present' => $present_c,
                    'avg_hours' => number_format($avg_hours, 2),
                    'status' => $status,
                    'late_count' => $late_c,
                    //'half_day_count' => $half_day_c,
                    'absent_count' => $absent_c + $missing_days // Include missing days in table display for consistency
                ];
            } else {
                // No attendance records found for this period
                // Use dynamic days_in_period (calculated before loop)
                $attendance_summary['absent'] += $days_in_period; 
                
                // Let's just track "Employees with NO attendance" vs "Employees with SOME attendance"?
                // For simplified metrics matching current cards:
                $attendance_summary['total_employees']++;
                
                $attendance_data[] = [
                    'employee_id' => $employee_id,
                    'employee_number' => $employee['employee_number'],
                    'first_name' => $employee['first_name'],
                    'last_name' => $employee['last_name'],
                    'department_name' => $employee['department_name'],
                    'total_hours' => '0.00',
                    'days_present' => 0,
                    'avg_hours' => '0.00',
                    'status' => 'absent',
                    'late_count' => 0,
                    'absent_count' => 0
                ];
            }
        }
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4" style="visibility: visible !important;">
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3 text-center">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;"><?= ($view_mode == 'day') ? 'DAILY ATTENDANCE REPORT' : strtoupper($view_mode) . ' ATTENDANCE SUMMARY' ?></h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Consolidated log of employee presence, punctuality, and working hours for administrative review.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> <?= ($view_mode != 'day') ? ' to ' . date('d M Y', strtotime($end_date)) : '' ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4" style="visibility: visible !important;">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 12px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 70px;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Present</p>
                <h3 style="color: #000 !important; font-weight: 800; margin: 0; font-size: 16pt;"><?= $attendance_summary['present'] ?></h3>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 12px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 70px;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Absent</p>
                <h3 style="color: #000 !important; font-weight: 800; margin: 0; font-size: 16pt;"><?= $attendance_summary['absent'] ?></h3>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 12px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 70px;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Late</p>
                <h3 style="color: #000 !important; font-weight: 800; margin: 0; font-size: 16pt;"><?= $attendance_summary['late'] ?></h3>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 12px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 70px;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Half Day</p>
                <h3 style="color: #000 !important; font-weight: 800; margin: 0; font-size: 16pt;"><?= $attendance_summary['half_day'] ?></h3>
            </div>
        </div>
    </div>
    <!-- Attendance Summary Cards -->
    <div class="row mb-4 d-print-none g-3">
        <?php
            // Calculate denominator based on user formula: Total Employees * Days in Period
            $days_in_period = 1; 
            if ($view_mode == 'week') {
                $days_in_period = 7;
            } elseif ($view_mode == 'month') {
                $days_in_period = date('t', strtotime($start_date));
            }
            
            $total_employees = $attendance_summary['total_employees'];
            $total_possible_man_days = $total_employees * $days_in_period;
            
            $denominator = ($total_possible_man_days > 0) ? $total_possible_man_days : 1;
        ?>
        <div class="col-6 col-xl-3 col-md-6">
            <div class="card custom-stat-card h-100 p-2 p-md-3">
                <div class="card-body p-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold">
                                <?php if ($view_mode == 'day'): ?>
                                    <?= $attendance_summary['present'] ?>
                                <?php else: ?>
                                    <?= round(($attendance_summary['present'] / $denominator) * 100, 1) ?>%
                                <?php endif; ?>
                            </h4>
                            <p class="mb-0 small text-uppercase fw-bold">Present</p>
                        </div>
                        <div class="align-self-center d-none d-sm-block">
                            <i class="bi bi-check-circle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3 col-md-6">
            <div class="card custom-stat-card h-100 p-2 p-md-3">
                <div class="card-body p-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold">
                                <?php if ($view_mode == 'day'): ?>
                                    <?= $attendance_summary['absent'] ?>
                                <?php else: ?>
                                    <?= round(($attendance_summary['absent'] / $denominator) * 100, 1) ?>%
                                <?php endif; ?>
                            </h4>
                            <p class="mb-0 small text-uppercase fw-bold">Absent</p>
                        </div>
                        <div class="align-self-center d-none d-sm-block">
                            <i class="bi bi-x-circle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3 col-md-6">
            <div class="card custom-stat-card h-100 p-2 p-md-3">
                <div class="card-body p-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold">
                                <?php if ($view_mode == 'day'): ?>
                                    <?= $attendance_summary['late'] ?>
                                <?php else: ?>
                                    <?= round(($attendance_summary['late'] / $denominator) * 100, 1) ?>%
                                <?php endif; ?>
                            </h4>
                            <p class="mb-0 small text-uppercase fw-bold">Late</p>
                        </div>
                        <div class="align-self-center d-none d-sm-block">
                            <i class="bi bi-clock-history" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3 col-md-6">
            <div class="card custom-stat-card h-100 p-2 p-md-3">
                <div class="card-body p-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold">
                                <?php if ($view_mode == 'day'): ?>
                                    <?= $attendance_summary['half_day'] ?>
                                <?php else: ?>
                                    <?= round(($attendance_summary['half_day'] / $denominator) * 100, 1) ?>%
                                <?php endif; ?>
                            </h4>
                            <p class="mb-0 small text-uppercase fw-bold">Half Day</p>
                        </div>
                        <div class="align-self-center d-none d-sm-block">
                            <i class="bi bi-hourglass-split" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Navigation    <!-- Filters Card -->
    <div class="card mb-4 d-print-none border-0 shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-calendar"></i> Attendance Date & Filters</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form id="attendanceFilterForm" method="GET">
                    <!-- View Mode Selection -->
                    
                    <input type="hidden" name="view" value="<?= $view_mode ?>">

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Select Period <span class="text-danger">*</span></label>
                            
                            <?php if ($view_mode == 'day'): ?>
                            <input type="date" class="form-control" id="date" name="date" value="<?= $selected_date ?>" max="<?= date('Y-m-d') ?>" onchange="this.form.submit()">
                            <?php elseif ($view_mode == 'week'): ?>
                            <input type="week" class="form-control" id="week" name="week" value="<?= $selected_week ?>" max="<?= date('Y') ?>-W<?= date('W') ?>" onchange="this.form.submit()">
                            <?php else: ?>
                            <input type="month" class="form-control" id="month" name="month" value="<?= $selected_month ?>" max="<?= date('Y-m') ?>" onchange="this.form.submit()">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" id="department" name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>" <?= ($selected_department == $dept['department_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($dept['department_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($view_mode == 'day'): ?>
                        <div class="col-md-3">
                            <label class="form-label">Attendance Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="present" <?= ($selected_status == 'present') ? 'selected' : '' ?>>Present</option>
                                <option value="absent" <?= ($selected_status == 'absent') ? 'selected' : '' ?>>Absent</option>
                                <option value="late" <?= ($selected_status == 'late') ? 'selected' : '' ?>>Late</option>
                                <option value="half_day" <?= ($selected_status == 'half_day') ? 'selected' : '' ?>>Half Day</option>
                                <option value="leave" <?= ($selected_status == 'leave') ? 'selected' : '' ?>>Leave</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-3 col-6">
                            <label class="form-label">Search Employee</label>
                            <input type="text" class="form-control" id="searchEmployee" name="search" placeholder="Name or ID...">
                        </div>
                        <?php if ($view_mode != 'day'): ?>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="btn-group w-100">
                                <button type="button" class="btn btn-outline-secondary" onclick="changeDate(-1)">
                                    <i class="bi bi-chevron-left"></i> Previous
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="goToToday()">
                                    <i class="bi bi-calendar-check"></i> Today
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="changeDate(1)">
                                    Next <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12 text-end">
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
    <div class="d-print-none mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
            <div class="d-flex flex-wrap align-items-center gap-2 w-100 w-md-auto">
                <div class="btn-group shadow-sm flex-grow-1 flex-md-grow-0" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                    <button type="button" class="btn btn-white fw-medium px-3 border-0 py-2" onclick="printAttendance()" style="background: #fff; color: #444;">
                        <i class="bi bi-printer text-primary me-1"></i> <span class="d-none d-sm-inline">Print</span>
                    </button>
                    <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                    <button type="button" class="btn btn-white fw-medium px-3 border-0 py-2" onclick="exportAttendance()" style="background: #fff; color: #444;">
                        <i class="bi bi-file-earmark-excel text-success me-1"></i> <span class="d-none d-sm-inline">Excel</span>
                    </button>
                </div>
                
                <div class="d-flex align-items-center bg-white shadow-sm px-2 py-1" style="border: 1px solid #dee2e6; border-radius: 8px; height: 41px;">
                    <span class="small text-muted me-1 d-none d-md-inline"><i class="bi bi-list-ol"></i></span>
                    <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 50px; box-shadow: none; background: transparent;" onchange="$('#attendanceTable').DataTable().page.len(this.value).draw();">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="-1">All</option>
                    </select>
                </div>

                <div class="input-group input-group-sm shadow-sm flex-grow-1 flex-md-grow-0" style="width: auto; max-width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                    <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control border-0 p-2" placeholder="Search..." onkeyup="$('#attendanceTable').DataTable().search(this.value).draw();">
                </div>
            </div>
            
            <div class="btn-group shadow-sm w-100 w-md-auto" role="group">
                <a href="?view=day&date=<?= $selected_date ?>" class="btn btn-sm py-2 <?= $view_mode == 'day' ? 'btn-primary' : 'btn-light border' ?>">Daily</a>
                <a href="?view=week&week=<?= $selected_week ?>" class="btn btn-sm py-2 <?= $view_mode == 'week' ? 'btn-primary' : 'btn-light border' ?>">Weekly</a>
                <a href="?view=month&month=<?= $selected_month ?>" class="btn btn-sm py-2 <?= $view_mode == 'month' ? 'btn-primary' : 'btn-light border' ?>">Monthly</a>
            </div>
        </div>
    </div>

    <!-- Attendance List -->
    <div class="card border-0 shadow-sm" id="attendanceReportCard">
        <div class="card-header bg-white py-3 border-bottom d-print-none">
            <h5 class="mb-0 fw-bold">
                <?php if ($view_mode == 'day'): ?>
                    Daily Attendance - <?= date('D, M j, Y', strtotime($selected_date)) ?>
                <?php elseif ($view_mode == 'week'): ?>
                    Weekly Attendance (<?= date('M j', strtotime($start_date)) ?> - <?= date('M j, Y', strtotime($end_date)) ?>)
                <?php else: ?>
                    Monthly Attendance - <?= date('F Y', strtotime($start_date)) ?>
                <?php endif; ?>
            </h5>
        </div>
        
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($attendance_data) > 0): ?>
                <div class="table-responsive">
                    <table id="attendanceTable" class="table table-hover align-middle mb-0" style="width:100%">
                        <thead>
                            <tr class="text-uppercase small text-muted">
                                <th style="width: 50px;">S/NO</th>
                                <th>Employee ID</th>
                                <th>(Name) Employee Name</th>
                                <th>Department</th>
                                
                                <?php if ($view_mode == 'day'): ?>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Total Hours</th>
                                <th class="text-center">Status</th>
                                <th>Notes</th>
                                <th class="text-end">Actions</th>
                                <?php else: ?>
                                <th>Total Hours</th>
                                <th>Avg Daily Hours</th>
                                <th>Days Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th class="text-end">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sn = 1;
                            foreach ($attendance_data as $record): 
                                // Apply status filter if selected
                                if ($selected_status && $record['status'] != $selected_status) {
                                    continue;
                                }
                            ?>
                            <tr>
                                <td><?= $sn++ ?></td>
                                <td>
                                    <strong><?= safe_output($record['employee_number']) ?></strong>
                                </td>
                                <td>
                                    <?= safe_output($record['first_name'] . ' ' . $record['last_name']) ?>
                                </td>
                                <td>
                                    <?= safe_output($record['department_name']) ?>
                                </td>
                                <?php if ($view_mode == 'day'): ?>
                                <td>
                                    <div class="time-input">
                                        <?php if ($can_edit_attendance): ?>
                                        <input type="time" class="form-control form-control-sm check-in-time" 
                                               data-employee-id="<?= $record['employee_id'] ?>"
                                               value="<?= $record['check_in_time'] ? date('H:i', strtotime($record['check_in_time'])) : '' ?>"
                                               onchange="updateAttendanceTime(<?= $record['employee_id'] ?>, 'check_in', this.value)">
                                        <?php else: ?>
                                        <span class="time-display"><?= format_time($record['check_in_time']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="time-input">
                                        <?php if ($can_edit_attendance): ?>
                                        <input type="time" class="form-control form-control-sm check-out-time" 
                                               data-employee-id="<?= $record['employee_id'] ?>"
                                               value="<?= $record['check_out_time'] ? date('H:i', strtotime($record['check_out_time'])) : '' ?>"
                                               onchange="updateAttendanceTime(<?= $record['employee_id'] ?>, 'check_out', this.value)">
                                        <?php else: ?>
                                        <span class="time-display"><?= format_time($record['check_out_time']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <strong><?= $record['total_hours'] ?></strong> hrs
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="status-buttons">
                                        <?php if ($can_edit_attendance): ?>
                                        <!-- Visible only for print -->
                                        <span class="status-text-print d-none d-print-inline-block text-dark fw-bold"><?= ucfirst(str_replace('_', ' ', $record['status'])) ?></span>
                                        <div class="btn-group btn-group-sm status-buttons-group" role="group">
                                            <button type="button" 
                                                    class="btn btn-<?= $record['status'] == 'present' ? 'success' : 'outline-success' ?> status-btn" 
                                                    data-employee-id="<?= $record['employee_id'] ?>"
                                                    data-status="present"
                                                    onclick="quickMarkAttendance(<?= $record['employee_id'] ?>, 'present', '09:00', '17:00')"
                                                    title="Mark Present (09:00-17:00)">
                                                <i class="bi bi-check-circle"></i> P
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-<?= $record['status'] == 'late' ? 'warning' : 'outline-warning' ?> status-btn" 
                                                    data-employee-id="<?= $record['employee_id'] ?>"
                                                    data-status="late"
                                                    onclick="quickMarkAttendance(<?= $record['employee_id'] ?>, 'late', '10:00', '17:00')"
                                                    title="Mark Late (10:00-17:00)">
                                                <i class="bi bi-clock"></i> L
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-<?= $record['status'] == 'absent' ? 'danger' : 'outline-danger' ?> status-btn" 
                                                    data-employee-id="<?= $record['employee_id'] ?>"
                                                    data-status="absent"
                                                    onclick="quickMarkAttendance(<?= $record['employee_id'] ?>, 'absent', '', '')"
                                                    title="Mark Absent">
                                                <i class="bi bi-x-circle"></i> A
                                            </button>
                                            <button type="button" 
                                                    class="btn btn-<?= $record['status'] == 'half_day' ? 'info' : 'outline-info' ?> status-btn" 
                                                    data-employee-id="<?= $record['employee_id'] ?>"
                                                    data-status="half_day"
                                                    onclick="quickMarkAttendance(<?= $record['employee_id'] ?>, 'half_day', '09:00', '13:00')"
                                                    title="Mark Half Day (09:00-13:00)">
                                                <i class="bi bi-dash-circle"></i> H
                                            </button>
                                        </div>
                                        <?php else: ?>
                                        <span class="badge bg-<?= get_attendance_badge($record['status']) ?>">
                                            <?= ucfirst(str_replace('_', ' ', $record['status'])) ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="notes-input">
                                        <?php if ($can_edit_attendance): ?>
                                        <input type="text" class="form-control form-control-sm attendance-notes" 
                                               data-employee-id="<?= $record['employee_id'] ?>"
                                               value="<?= safe_output($record['notes']) ?>"
                                               placeholder="Add notes"
                                               onchange="updateAttendanceNotes(<?= $record['employee_id'] ?>, this.value)">
                                        <?php else: ?>
                                        <small><?= safe_output($record['notes']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('attendance') ?>?employee=<?= $record['employee_id'] ?>">
                                                    <i class="bi bi-clock-history"></i> View History
                                                </a>
                                            </li>
                                            <?php if ($can_edit_attendance): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="quickMarkPresent(<?= $record['employee_id'] ?>)">
                                                    <i class="bi bi-check-circle"></i> Mark Present
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="quickMarkAbsent(<?= $record['employee_id'] ?>)">
                                                    <i class="bi bi-x-circle"></i> Mark Absent
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="quickMarkLate(<?= $record['employee_id'] ?>)">
                                                    <i class="bi bi-clock"></i> Mark Late
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="viewAttendanceDetails(<?= $record['employee_id'] ?>, '<?= $selected_date ?>')">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <?php if ($can_edit_attendance && $record['existing_record']): ?>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="deleteAttendanceRecord(<?= $record['employee_id'] ?>, '<?= $selected_date ?>')">
                                                    <i class="bi bi-trash"></i> Delete Record
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                                <?php else: ?>
                                <td><strong><?= $record['total_hours'] ?></strong></td>
                                <td class="text-center fw-bold text-primary"><?= $record['avg_hours'] ?></td>
                                <td class="text-center"><?= $record['days_present'] ?></td>
                                <td class="text-center text-warning fw-bold"><?= $record['late_count'] ?></td>
                                <td class="text-center text-danger fw-bold"><?= $record['absent_count'] ?></td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('attendance') ?>?employee=<?= $record['employee_id'] ?>">
                                                    <i class="bi bi-clock-history"></i> View History
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="d-none d-print-table-footer">
                            <tr style="height: 80px !important; border: none !important;">
                                <td colspan="10" style="border: none !important;">&nbsp;</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                

                
                <!-- Attendance Summary Bottom -->
                <div class="mt-4">
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-pie-chart"></i> 
                                <?= ucfirst($view_mode) ?> Attendance Summary
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Attendance Statistics</h6>
                                    <?php 
                                        // Calculate dynamic denominator for rates
                                        $total_emp = $attendance_summary['total_employees'];
                                        $denom = ($total_emp * $days_in_period) > 0 ? ($total_emp * $days_in_period) : 1;
                                    ?>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Total Employees
                                            <span class="badge bg-primary"><?= $total_emp ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Present Rate
                                            <span class="badge bg-success"><?= round(($attendance_summary['present'] / $denom) * 100, 1) ?>%</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Absence Rate
                                            <span class="badge bg-danger"><?= round(($attendance_summary['absent'] / $denom) * 100, 1) ?>%</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Late Arrivals
                                            <!-- Late is just count, usually we don't do % for late unless requested, strict late rate vs present -->
                                            <span class="badge bg-warning"><?= $attendance_summary['late'] ?></span>
                                        </li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6>Working Hours Summary</h6>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Standard Hours
                                            <span class="badge bg-info">8.00 hrs</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Average Hours
                                            <span class="badge bg-info"><?= calculate_average_hours($attendance_data) ?> hrs</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Early Departures
                                            <span class="badge bg-warning"><?= count_early_departures($attendance_data) ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Total Overtime
                                            <span class="badge bg-success"><?= calculate_overtime($attendance_data) ?> hrs</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-people" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Employees Found</h4>
                    <p class="text-muted">No active employees found for the selected filters.</p>
                    <button type="button" class="btn btn-primary mt-2" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Clear Filters
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Professional Branded Print Footer -->
    <div class="print-footer d-none d-print-block mt-5 pt-3 border-top text-center">
        <p class="mb-1 text-muted" style="font-size: 9pt;">
             This document was Printed by <span class="fw-bold text-dark"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= ucwords($_SESSION['user_role'] ?? 'Staff') ?></span> on <span class="fw-bold text-dark"><?= date('d M, Y \a\t h:i A') ?></span>
        </p>
        <p class="mb-0 fw-bold text-primary" style="font-size: 11pt; letter-spacing: 0.5px;">
            Powered By BJP Technologies  © 2026, All Rights Reserved
        </p>
    </div>
</div>

<!-- Mark Attendance Modal -->
<?php if ($can_edit_attendance): ?>
<div class="modal fade" id="markAttendanceModal" tabindex="-1" aria-labelledby="markAttendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="markAttendanceModalLabel">
                    <i class="bi bi-check-circle"></i> Mark Attendance
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="markAttendanceForm">
                <div class="modal-body">
                    <div id="mark-attendance-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="mark_date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="mark_date" name="attendance_date" value="<?= $selected_date ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_employee" class="form-label">Employee <span class="text-danger">*</span></label>
                            <select class="form-select" id="mark_employee" name="employee_id" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $employee): ?>
                                <option value="<?= $employee['employee_id'] ?>">
                                    <?= safe_output($employee['first_name'] . ' ' . $employee['last_name']) ?> (<?= safe_output($employee['employee_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_check_in" class="form-label">Check In Time</label>
                            <input type="time" class="form-control" id="mark_check_in" name="check_in_time">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_check_out" class="form-label">Check Out Time</label>
                            <input type="time" class="form-control" id="mark_check_out" name="check_out_time">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="mark_status" name="status" required>
                                <option value="present" selected>Present</option>
                                <option value="absent">Absent</option>
                                <option value="late">Late</option>
                                <option value="half_day">Half Day</option>
                                <option value="leave">Leave</option>
                                <option value="holiday">Holiday</option>
                                <option value="weekend">Weekend</option>
                            </select>
                        </div>
                        
                        <div class="col-12 mb-3">
                            <label for="mark_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="mark_notes" name="notes" rows="3" placeholder="Any notes about attendance"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Attendance Modal -->
<?php if ($can_edit_attendance): ?>
<div class="modal fade" id="bulkAttendanceModal" tabindex="-1" aria-labelledby="bulkAttendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="bulkAttendanceModalLabel">
                    <i class="bi bi-upload"></i> Bulk Attendance Update
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="bulkAttendanceForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="bulk-attendance-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the attendance data</li>
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
                            <option value="add_new">Add New Records Only</option>
                            <option value="update_existing">Update Existing Records</option>
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
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadAttendanceTemplate()">
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

<!-- Scripts -->
<script>
$(document).ready(function() {
    logReportAction('Viewed Attendance List', 'User viewed the attendance management page');
    // Get current view mode from PHP
    const viewMode = '<?= $view_mode ?>';
    const canEdit = <?= $can_edit_attendance ? 'true' : 'false' ?>;
    
    // Configure columns based on view mode
    let columnDefs = [];
    let orderColumn = 1; // Default to Employee ID column
    
    if (viewMode === 'day') {
        // Day view: Employee ID, Name, Dept, Check In, Check Out, Hours, Status, Notes, Actions
        columnDefs.push({ targets: -1, orderable: false }); // Actions
        orderColumn = 1; // Employee ID
    } else {
        // Week/Month view: Employee ID, Name, Dept, Total Hours, Avg Hours, Days Present, Late, Absent, Actions
        columnDefs.push({ targets: -1, orderable: false }); // Actions
        orderColumn = 1; // Employee ID
    }
    
    // Initialize DataTable
    let attendanceTable = $('#attendanceTable').DataTable({
        responsive: true,
        // Only show table (t), info (i), and pagination (p) - no buttons (B) or search (f) or length (l)
        dom: 'rtip', 
        columnDefs: columnDefs,
        paging: true,
        pageLength: 25,
        order: [[orderColumn, 'asc']],
        footerCallback: function(row, data, start, end, display) {
            // Update selected count
            if (viewMode === 'day' && canEdit) {
                updateSelectedCount();
            }
        }
    });

    // Log page view
    $.post(APP_URL + '/api/log_audit', {
        action: 'view_list',
        activity_type: 'view',
        entity_type: 'attendance',
        description: `User viewed the Attendance Management page (Mode: ${viewMode})`
    });

    // Log filter changes
    $('#department, #status').on('change', function() {
        const dept = $('#department option:selected').text().trim();
        const stat = $('#status').val() || 'All';
        $.post(APP_URL + '/api/log_audit', {
            action: 'filter_list',
            activity_type: 'view',
            entity_type: 'attendance',
            description: `User filtered attendance (Dept: ${dept}, Status: ${stat})`
        });
    });

    // Mark attendance form submission
    $('#markAttendanceForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: APP_URL + '/api/mark_attendance',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message || 'Attendance marked successfully.',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Saving failed.', 'error');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Attendance');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred. Please try again.', 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Attendance');
            }
        });
    });

    // Bulk attendance form submission
    $('#bulkAttendanceForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');

        $.ajax({
            url: APP_URL + '/api/import_attendance',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    let resultMsg = response.message || 'Attendance imported successfully.';
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

    // Checkbox selection handlers - use event delegation for DataTables
    $('#attendanceTable').on('change', '.attendance-checkbox', function() {
        updateSelectedCount();
    });
    
    // Update count when page changes
    $('#attendanceTable').on('page.dt', function() {
        setTimeout(updateSelectedCount, 100);
    });

    // Reset forms when modals are closed
    $('#markAttendanceModal').on('hidden.bs.modal', function() {
        $('#markAttendanceForm')[0].reset();
        $('#mark-attendance-message').html('');
        $('#markAttendanceForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Attendance');
    });
    
    $('#bulkAttendanceModal').on('hidden.bs.modal', function() {
        $('#bulkAttendanceForm')[0].reset();
        $('#bulk-attendance-message').html('');
        $('#bulkAttendanceForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
    });
});

function changeDate(offset) {
    const viewMode = '<?= $view_mode ?>';
    let inputId = 'date';
    if (viewMode === 'week') inputId = 'week';
    if (viewMode === 'month') inputId = 'month';
    
    const input = document.getElementById(inputId);
    if (input) {
        try {
            if (offset > 0) input.stepUp();
            else input.stepDown();
            
            // Trigger form submission
            document.getElementById('attendanceFilterForm').submit();
        } catch (e) {
            // Fallback for browsers that might not support stepUp on specific types
            console.error(e);
            // Revert to simple day based logic if day view
            if (viewMode === 'day') {
                const currentDate = new Date('<?= $selected_date ?>');
                currentDate.setDate(currentDate.getDate() + offset);
                const newDate = currentDate.toISOString().split('T')[0];
                window.location.href = `?view=day&date=${newDate}&department=<?= $selected_department ?>&status=<?= $selected_status ?>`;
            }
        }
    }
}

function goToToday() {
    const viewMode = '<?= $view_mode ?>';
    const baseUrl = `?view=${viewMode}&department=<?= $selected_department ?>&status=<?= $selected_status ?>`;
    
    if (viewMode === 'week') {
        window.location.href = baseUrl + `&week=<?= date('Y-\nW') ?>`;
    } else if (viewMode === 'month') {
        window.location.href = baseUrl + `&month=<?= date('Y-m') ?>`;
    } else {
        window.location.href = baseUrl + `&date=<?= date('Y-m-d') ?>`;
    }
}

function clearFilters() {
    window.location.href = 'attendance.php?view=<?= $view_mode ?>';
}

function exportAttendance() {
    // Collect checked IDs or clean search params
    const form = document.getElementById('attendanceFilterForm');
    const formData = new FormData(form);
    
    // Default GET params from existing URL or selected values
    const queryParams = new URLSearchParams(window.location.search);
    
    // Add form data to params if not present (although form implies inputs)
    // Actually simpler: just take current URL params and send to export script
    // Or construct manually
    
    const view = '<?= $view_mode ?>';
    const date = '<?= $selected_date ?>';
    const week = '<?= $selected_week ?>';
    const month = '<?= $selected_month ?>';
    const dept = '<?= $selected_department ?>';
    const status = '<?= $selected_status ?>'; // only for day view
    const search = $('#attendanceTable').DataTable().search(); // Get current search term
    
    let url = APP_URL + '/api/export_attendance?view=' + view;
    
    if (view === 'day') url += '&date=' + date + '&status=' + status;
    else if (view === 'week') url += '&week=' + week;
    else if (view === 'month') url += '&month=' + month;
    
    if (dept) url += '&department=' + dept;
    if (search) url += '&search=' + encodeURIComponent(search);
    
    // Log export action
    logReportAction('Exported Attendance List', `User exported attendance list (Period: ${date || week || month}, View: ${view})`);

    window.location.href = url;
}

function printAttendance() {
    logReportAction('Printed Attendance Report', 'User generated a printed attendance report');
    window.print();
}

function getSelectedEmployeeIds() {
    const selectedIds = [];
    // Use DataTables API to get all rows, not just visible ones
    const table = $('#attendanceTable').DataTable();
    table.$('.attendance-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });
    return selectedIds;
}

function updateSelectedCount() {
    const table = $('#attendanceTable').DataTable();
    const selectedCount = table.$('.attendance-checkbox:checked').length;
    $('#selectedCount').text(selectedCount);
}

function toggleSelectAll(checkbox) {
    const table = $('#attendanceTable').DataTable();
    if (checkbox.checked) {
        // Select all on current page
        table.$('.attendance-checkbox').prop('checked', true);
    } else {
        // Deselect all
        table.$('.attendance-checkbox').prop('checked', false);
    }
    updateSelectedCount();
}

function selectAllAttendance() {
    const table = $('#attendanceTable').DataTable();
    $('#selectAll').prop('checked', true);
    table.$('.attendance-checkbox').prop('checked', true);
    updateSelectedCount();
}


function bulkMarkStatus(status) {
    const selectedIds = getSelectedEmployeeIds();
    if (selectedIds.length === 0) {
        Swal.fire('Wait!', 'Please select at least one employee.', 'warning');
        return;
    }
    
    const statusName = status.replace('_', ' ');
    Swal.fire({
        title: 'Bulk Update?',
        text: `Are you sure you want to mark ${selectedIds.length} employee(s) as ${statusName}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Proceed',
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/bulk_mark_attendance',
                type: 'POST',
                data: { 
                    employee_ids: selectedIds,
                    attendance_date: '<?= $selected_date ?>',
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Log Audit
                        $.post(APP_URL + '/api/log_audit', {
                            action: 'bulk_update_attendance',
                            activity_type: 'update',
                            description: `Bulk updated attendance status to ${statusName} for ${selectedIds.length} employees`
                        });

                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    Swal.fire('Error', 'Error marking attendance. Please try again.', 'error');
                }
            });
        }
    });
}

function updateAttendanceTime(employeeId, field, value) {
    $.ajax({
        url: APP_URL + '/api/update_attendance_time',
        type: 'POST',
        data: { 
            employee_id: employeeId,
            attendance_date: '<?= $selected_date ?>',
            field: field,
            value: value
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Time Updated!',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#0d6efd'
                });
            } else {
                Swal.fire('Error', 'Error updating time: ' + response.message, 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.fire('Error', 'Error updating time. Please try again.', 'error');
            console.error('Error:', error);
        }
    });
}

function updateAttendanceStatus(employeeId, status) {
    $.ajax({
        url: APP_URL + '/api/update_attendance_status',
        type: 'POST',
        data: { 
            employee_id: employeeId,
            attendance_date: '<?= $selected_date ?>',
            status: status
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Status Updated!',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#0d6efd'
                });
            } else {
                Swal.fire('Error', 'Error updating status: ' + response.message, 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.fire('Error', 'Error updating status. Please try again.', 'error');
            console.error('Error:', error);
        }
    });
}

function updateAttendanceNotes(employeeId, notes) {
    $.ajax({
        url: APP_URL + '/api/update_attendance_notes',
        type: 'POST',
        data: { 
            employee_id: employeeId,
            attendance_date: '<?= $selected_date ?>',
            notes: notes
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Notes Updated!',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#0d6efd'
                });
            } else {
                Swal.fire('Error', 'Error updating notes: ' + response.message, 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.fire('Error', 'Error updating notes. Please try again.', 'error');
            console.error('Error:', error);
        }
    });
}

// Quick mark attendance with button click (auto-fills times)
function quickMarkAttendance(employeeId, status, checkInTime, checkOutTime) {
    // Visual feedback - disable button temporarily
    const buttons = document.querySelectorAll(`button[data-employee-id="${employeeId}"]`);
    buttons.forEach(btn => btn.disabled = true);
    
    $.ajax({
        url: APP_URL + '/api/quick_mark_attendance',
        type: 'POST',
        data: { 
            employee_id: employeeId,
            attendance_date: '<?= $selected_date ?>',
            status: status,
            check_in_time: checkInTime,
            check_out_time: checkOutTime
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Attendance Marked!',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#0d6efd'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', response.message, 'error');
                buttons.forEach(btn => btn.disabled = false);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error:', error);
            console.error('Response:', xhr.responseText);
            Swal.fire('Error', 'Error marking attendance. Please try again.', 'error');
            buttons.forEach(btn => btn.disabled = false);
        }
    });
}

function quickMarkPresent(employeeId) {
    Swal.fire({
        title: 'Mark Present?',
        text: 'Mark this employee as present for today?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Mark Present',
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/quick_mark_attendance',
                type: 'POST',
                data: { 
                    employee_id: employeeId,
                    attendance_date: '<?= $selected_date ?>',
                    status: 'present',
                    check_in_time: '09:00',
                    check_out_time: '17:00'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Log Audit
                        $.post(APP_URL + '/api/log_audit', {
                            action: 'mark_attendance_present',
                            activity_type: 'create',
                            entity_type: 'employee',
                            entity_id: employeeId,
                            description: `Quick marked employee ID ${employeeId} as Present on <?= $selected_date ?>`
                        });

                        Swal.fire({
                            icon: 'success',
                            title: 'Marked Present!',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error marking attendance. Please try again.', 'error');
                }
            });
        }
    });
}

function quickMarkAbsent(employeeId) {
    Swal.fire({
        title: 'Mark Absent?',
        text: 'Mark this employee as absent for today?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Mark Absent',
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/quick_mark_attendance',
                type: 'POST',
                data: { 
                    employee_id: employeeId,
                    attendance_date: '<?= $selected_date ?>',
                    status: 'absent'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Log Audit
                        $.post(APP_URL + '/api/log_audit', {
                            action: 'mark_attendance_absent',
                            activity_type: 'create',
                            entity_type: 'employee',
                            entity_id: employeeId,
                            description: `Quick marked employee ID ${employeeId} as Absent on <?= $selected_date ?>`
                        });

                        Swal.fire({
                            icon: 'success',
                            title: 'Marked Absent!',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error marking attendance. Please try again.', 'error');
                }
            });
        }
    });
}

function quickMarkLate(employeeId) {
    Swal.fire({
        title: 'Mark Late?',
        text: 'Mark this employee as late for today?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Mark Late',
        confirmButtonColor: '#ffc107',
        cancelButtonColor: '#6c757d'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/quick_mark_attendance',
                type: 'POST',
                data: { 
                    employee_id: employeeId,
                    attendance_date: '<?= $selected_date ?>',
                    status: 'late',
                    check_in_time: '10:00',
                    check_out_time: '18:00'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Log Audit
                        $.post(APP_URL + '/api/log_audit', {
                            action: 'mark_attendance_late',
                            activity_type: 'create',
                            entity_type: 'employee',
                            entity_id: employeeId,
                            description: `Quick marked employee ID ${employeeId} as Late on <?= $selected_date ?>`
                        });

                        Swal.fire({
                            icon: 'success',
                            title: 'Marked Late!',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error marking attendance. Please try again.', 'error');
                }
            });
        }
    });
}

function viewAttendanceDetails(employeeId, date) {
    window.location.href = APP_URL + '/attendance?employee=' + employeeId + '&date=' + date;
}

function deleteAttendanceRecord(employeeId, date) {
    Swal.fire({
        title: 'Delete Record?',
        text: 'Are you sure you want to delete this attendance record?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'No, Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/delete_attendance',
                type: 'POST',
                data: { 
                    employee_id: employeeId,
                    attendance_date: date
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Log Audit
                        $.post(APP_URL + '/api/log_audit', {
                            action: 'delete_attendance',
                            activity_type: 'delete',
                            entity_type: 'employee',
                            entity_id: employeeId,
                            description: `Deleted attendance record for employee ID ${employeeId} on ${date}`
                        });

                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: response.message,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire('Error', 'Error deleting record. Please try again.', 'error');
                }
            });
        }
    });
}

function downloadAttendanceTemplate() {
    // Create a CSV template file
    const headers = [
        'employee_id', 'attendance_date', 'check_in_time', 'check_out_time', 
        'status', 'notes'
    ];
    
    // Updated example data to match CSV format
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\n101,2023-10-01,09:00,17:00,present,On time";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "attendance_import_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

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
.card.bg-success,
.card.bg-danger,
.card.bg-warning,
.card.bg-info,
.card.bg-secondary,
.card.bg-dark {
    border: none;
}

.card.bg-primary { background: linear-gradient(45deg, #0d6efd, #0b5ed7); }
.card.bg-success { background: linear-gradient(45deg, #198754, #157347); }
.card.bg-danger { background: linear-gradient(45deg, #dc3545, #bb2d3b); }
.card.bg-warning { background: linear-gradient(45deg, #ffc107, #e0a800); }
.card.bg-info { background: linear-gradient(45deg, #0dcaf0, #0aa2c0); }
.card.bg-secondary { background: linear-gradient(45deg, #6c757d, #5a6268); }
.card.bg-dark { background: linear-gradient(45deg, #212529, #343a40); }

/* Progress bar customization */
.progress-bar {
    font-size: 0.75rem;
    font-weight: 600;
}

/* Checkbox styling */
.attendance-checkbox {
    cursor: pointer;
}

/* Time input styling */
.time-input input {
    min-width: 80px;
}

.time-display {
    font-family: monospace;
    font-weight: 600;
}

/* Status select styling */
.status-select select {
    min-width: 100px;
}

/* Notes input styling */
.notes-input input {
    min-width: 150px;
}

/* Status buttons styling */
.status-buttons .btn-group {
    white-space: nowrap;
}

.status-buttons .status-btn {
    font-weight: 600;
    min-width: 45px;
    transition: all 0.2s ease;
}

.status-buttons .status-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.status-buttons .status-btn:active {
    transform: translateY(0);
}

.status-buttons .status-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Aggregated View Styling */
.table tbody tr:hover {
    background-color: #f8f9fa;
    transition: background-color 0.2s ease;
}

/* Professional metric styling for aggregated views */
.fw-bold.text-primary {
    font-size: 1.05em;
    text-shadow: 0 1px 2px rgba(13, 110, 253, 0.1);
}

.text-warning.fw-bold {
    text-shadow: 0 1px 2px rgba(255, 193, 7, 0.2);
}

.text-danger.fw-bold {
    text-shadow: 0 1px 2px rgba(220, 53, 69, 0.2);
}

/* View switcher button styling */
.btn-group .btn {
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-group .btn-primary {
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.3);
}

.btn-group .btn-outline-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2);
}

/* Enhanced table header for aggregated views */
.table thead th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
}

/* Metric badges in aggregated view */
.badge {
    padding: 0.5em 0.75em;
    font-weight: 600;
    letter-spacing: 0.3px;
}

/* DataTables Pagination Styling */
.dataTables_wrapper .dataTables_paginate {
    padding-top: 1rem;
}

.dataTables_wrapper .dataTables_paginate .paginate_button {
    padding: 0.375rem 0.75rem;
    margin: 0 2px;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    background-color: #fff;
    color: #0d6efd !important;
    font-weight: 500;
    transition: all 0.2s ease;
}

.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    background-color: #0d6efd;
    color: #fff !important;
    border-color: #0d6efd;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.3);
}

.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background-color: #0d6efd;
    color: #fff !important;
    border-color: #0d6efd;
    font-weight: 600;
}

.dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.dataTables_wrapper .dataTables_length select {
    padding: 0.375rem 2rem 0.375rem 0.75rem;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
}

.dataTables_wrapper .dataTables_info {
    padding-top: 1rem;
    color: #6c757d;
    font-size: 0.875rem;
}

/* Print styles */
    @media print {
        body {
            visibility: hidden;
            background-color: white !important;
        }

        /* Target ONLY the essential report elements */
        #attendanceReportCard, 
        .print-header, 
        .print-footer, 
        #attendanceReportCard *, 
        .print-header *, 
        .print-footer * {
            visibility: visible !important;
        }

        #attendanceReportCard {
            display: block !important;
            position: relative !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            box-shadow: none !important;
        }

        .d-print-none, 
        .card-header, 
        .btn, 
        .btn-group, 
        .dropdown, 
        .dataTables_wrapper .dataTables_length, 
        .dataTables_wrapper .dataTables_filter, 
        .dataTables_wrapper .dataTables_info, 
        .dataTables_wrapper .dataTables_paginate {
            display: none !important;
            visibility: hidden !important;
        }

        /* Header & Footer Styling */
        .print-header {
            display: block !important;
            text-align: center;
            margin-bottom: 20px;
        }

        .print-footer {
            position: fixed !important;
            bottom: 0 !important;
            left: 0;
            right: 0;
            height: 1.5cm;
            display: flex !important;
            flex-direction: column;
            justify-content: center;
            text-align: center;
            background: white !important;
            border-top: 1px solid #ddd !important;
            z-index: 9999;
            -webkit-print-color-adjust: exact;
        }

        @page {
            size: auto;
            margin: 0.3in 0.2in 2.5cm 0.2in !important;
        }

        .d-print-table-footer {
            display: table-footer-group !important;
        }

        #attendanceTable tr {
            page-break-inside: avoid !important;
        }

        /* Table Width & Font Optimization for Portrait */
        table {
            width: 100% !important;
            border-collapse: collapse !important;
            font-size: 7.5pt !important;
            table-layout: auto !important;
        }
        
        table th, table td {
            border: 1px solid #000 !important;
            padding: 2px 1px !important;
            word-wrap: break-word !important;
        }
        
        table th {
            background-color: #f1f1f1 !important;
            font-weight: 800 !important;
            font-size: 7pt !important;
        }

        /* Hide Actions column explicitly */
        table th:last-child, table td:last-child {
            display: none !important;
        }

        .status-text-print {
            font-weight: bold !important;
            text-transform: uppercase;
            font-size: 7pt !important;
        }
    }

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
        text-shadow: none !important;
    }
    .card {
        border: 1px solid rgba(0, 0, 0, 0.125);
    }
</style>

<?php
// Helper functions for summary calculations
function calculate_average_hours($attendance_data) {
    $total_hours = 0;
    $count = 0;
    
    foreach ($attendance_data as $record) {
        if ($record['status'] == 'present' || $record['status'] == 'late' || $record['status'] == 'half_day') {
            $total_hours += (float)$record['total_hours'];
            $count++;
        }
    }
    
    return $count > 0 ? number_format($total_hours / $count, 2) : '0.00';
}

function count_early_departures($attendance_data) {
    $count = 0;
    
    foreach ($attendance_data as $record) {
        if (!empty($record['check_out_time'])) {
            $check_out = strtotime($record['check_out_time']);
            $standard_check_out = strtotime('17:00:00');
            
            if ($check_out < $standard_check_out && $record['status'] != 'half_day') {
                $count++;
            }
        }
    }
    
    return $count;
}

function calculate_overtime($attendance_data) {
    $total_overtime = 0;
    $standard_hours = 8;
    
    foreach ($attendance_data as $record) {
        if ($record['status'] == 'present' || $record['status'] == 'late') {
            $hours = (float)$record['total_hours'];
            if ($hours > $standard_hours) {
                $total_overtime += ($hours - $standard_hours);
            }
        }
    }
    
    return number_format($total_overtime, 2);
}

// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>