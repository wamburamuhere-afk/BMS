<?php
// API endpoint for fetching employees with server-side pagination
header('Content-Type: application/json');

// Ensure error reporting is captured for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Ensure no previous output
if (ob_get_length()) ob_clean();

// Include core logic which handles session and database
require_once __DIR__ . '/../roots.php'; 

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("get_employees API: Unauthorized - No user_id in session");
    echo json_encode([
        'error' => 'Unauthorized access',
        'data' => [],
        'recordsTotal' => 0,
        'recordsFiltered' => 0
    ]);
    exit();
}

// Log API call for debugging
error_log("get_employees API called by user: " . $_SESSION['user_id']);

try {
    // DataTables parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $searchValue = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
    
    // Order parameters
    $orderColumnIndex = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 0;
    $orderDir = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'asc';
    
    // Column mapping for ordering
    $columns = [
        0 => 'e.employee_number',
        1 => 'e.first_name',
        2 => 'd.department_name',
        3 => 'des.designation_name',
        4 => 'e.employment_status',
        5 => 'attendance_count', // Alias for count
        6 => 'leaves_count',
        7 => 'payrolls_count',
        8 => 'e.created_at'
    ];
    
    $sortCol = isset($columns[$orderColumnIndex]) ? $columns[$orderColumnIndex] : 'e.first_name';
    
    // Phase D — project-scope filter (nullable: global employees visible to all assigned users)
    $scopeE = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'e') : '';

    // Base Table Joins
    $baseFrom = "
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN designations des ON e.designation_id = des.designation_id
        LEFT JOIN employment_types et ON e.employment_type_id = et.type_id
        LEFT JOIN projects pr ON e.project_id = pr.project_id
        LEFT JOIN (
            SELECT employee_id, COUNT(*) as attendance_count FROM attendance GROUP BY employee_id
        ) a ON e.employee_id = a.employee_id
        LEFT JOIN (
            SELECT employee_id, COUNT(*) as leaves_count FROM leaves GROUP BY employee_id
        ) l ON e.employee_id = l.employee_id
        LEFT JOIN (
            SELECT employee_id, COUNT(*) as payrolls_count FROM payroll GROUP BY employee_id
        ) p ON e.employee_id = p.employee_id
        WHERE e.status != 'terminated'
        $scopeE
    ";
    
    // Search Filter
    $searchQuery = "";
    $searchParams = [];
    
    if (!empty($searchValue)) {
        $searchQuery = " AND (
            e.employee_number LIKE :search OR
            e.first_name LIKE :search OR
            e.last_name LIKE :search OR
            e.email LIKE :search OR
            e.phone LIKE :search OR
            d.department_name LIKE :search OR
            des.designation_name LIKE :search OR
            e.employment_status LIKE :search
        )";
        $searchParams[':search'] = "%$searchValue%";
    }
    
    // 1. Get total records (scope-aware: respect project assignment)
    $scopeTotal = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'employees') : '';
    $stmtTotal = $pdo->query("SELECT COUNT(*) as total FROM employees WHERE status != 'terminated' $scopeTotal");
    $totalRecords = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 2. Get filtered records count
    $filteredSql = "SELECT COUNT(*) as total " . $baseFrom . $searchQuery;
    $stmtFiltered = $pdo->prepare($filteredSql);
    $stmtFiltered->execute($searchParams);
    $filteredRecords = $stmtFiltered->fetch(PDO::FETCH_ASSOC)['total'];
    
    // 3. Get actual data
    $sql = "
        SELECT 
            e.employee_id,
            e.employee_number,
            e.first_name,
            e.last_name,
            e.middle_name,
            e.employment_status,
            e.hire_date,
            d.department_name,
            des.designation_name,
            pr.project_name,
            COALESCE(a.attendance_count, 0) as total_attendance,
            COALESCE(l.leaves_count, 0) as total_leaves,
            COALESCE(p.payrolls_count, 0) as total_payrolls
        " . $baseFrom . $searchQuery . "
        ORDER BY $sortCol $orderDir
        LIMIT :res_limit OFFSET :res_offset
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // Bind search params
    foreach ($searchParams as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    
    // Bind Limit/Offset using integers
    $stmt->bindValue(':res_limit', (int)$length, PDO::PARAM_INT);
    $stmt->bindValue(':res_offset', (int)$start, PDO::PARAM_INT);
    
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format JSON Response
    $data_rows = [];
    foreach ($employees as $emp) {
        
        // Status Badge Logic
        $statusClass = 'secondary';
        $statusLabel = ucfirst($emp['employment_status']);
        
        switch ($emp['employment_status']) {
            case 'active': $statusClass = 'success'; break;
            case 'probation': $statusClass = 'warning'; break;
            case 'contract': $statusClass = 'info'; break;
            case 'on_leave': $statusClass = 'primary'; break;
            case 'resigned': $statusClass = 'danger'; break;
            case 'terminated': $statusClass = 'dark'; break;
            default: $statusClass = 'secondary'; break;
        }
        $statusHtml = "<span class='badge bg-{$statusClass}'>{$statusLabel}</span>";
        
        // Date Logic
        $joined = ($emp['hire_date']) ? date('d M Y', strtotime($emp['hire_date'])) : '-';
        
        // Actions Logic (using getUrl if possible, or fallback manually)
        // We assume getUrl is available via helpers.php -> which is required via roots.php
        $detailsUrl = function_exists('getUrl') ? getUrl('employee_details') . "?id=" . $emp['employee_id'] : "employee_details.php?id=" . $emp['employee_id'];
        $attendanceUrl = function_exists('getUrl') ? getUrl('attendance') . "?employee=" . $emp['employee_id'] : "attendance.php?employee=" . $emp['employee_id'];
        $payrollUrl = function_exists('getUrl') ? getUrl('payroll') . "?employee=" . $emp['employee_id'] : "payroll.php?employee=" . $emp['employee_id'];
        
        $actions = "
            <div class='dropdown'>
                <button class='btn btn-sm btn-light border shadow-sm dropdown-toggle action-btn-premium' type='button' data-bs-toggle='dropdown' aria-expanded='false'>
                    <i class='bi bi-gear-fill text-primary'></i>
                </button>
                <ul class='dropdown-menu dropdown-menu-end shadow border-0 py-2' style='border-radius: 12px;'>
                    <li>
                        <a class='dropdown-item py-2' href='{$detailsUrl}'>
                            <i class='bi bi-person-badge-fill text-primary me-2'></i> <strong>View Details</strong>
                        </a>
                    </li>
                    <li><hr class='dropdown-divider'></li>
                    <li>
                        <a class='dropdown-item py-2' href='#' onclick='editEmployee({$emp['employee_id']}); return false;'>
                            <i class='bi bi-pencil-square text-warning me-2'></i> Edit Profile
                        </a>
                    </li>
                    <li>
                        <a class='dropdown-item py-2' href='{$attendanceUrl}'>
                            <i class='bi bi-calendar-check text-success me-2'></i> Attendance
                        </a>
                    </li>
                    <li>
                        <a class='dropdown-item py-2' href='{$payrollUrl}'>
                            <i class='bi bi-cash-stack text-info me-2'></i> Payroll Records
                        </a>
                    </li>
                     <li><hr class='dropdown-divider'></li>
                    <li>
                        <a class='dropdown-item py-2 text-danger' href='#' onclick='confirmDelete({$emp['employee_id']}); return false;'>
                            <i class='bi bi-trash me-2'></i> Delete
                        </a>
                    </li>
                </ul>
            </div>
        ";
        
        $data_rows[] = [
            $emp['employee_number'],
            safe_output($emp['first_name'] . ' ' . $emp['last_name']),
            safe_output($emp['department_name'] ?? '-'),
            safe_output($emp['designation_name'] ?? '-'),
            safe_output($emp['project_name'] ?? 'General'),
            $statusHtml,
            $emp['total_attendance'],
            $emp['total_leaves'],
            $emp['total_payrolls'],
            $joined,
            $actions
        ];
    }
    
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data_rows
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
