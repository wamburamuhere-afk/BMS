<?php
// File: api/get_payrolls.php
// Include root configuration
require_once __DIR__ . '/../roots.php';

// session_start() is handled in roots.php
// session_start(); 

header('Content-Type: application/json');

// Basic Authentication Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

try {
    // Get parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search_value = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
    
    // Filters
    $period = isset($_GET['period']) ? $_GET['period'] : date('Y-m');
    $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    $department_filter = isset($_GET['department']) ? intval($_GET['department']) : 0;
    // Employee_inactivation_plan.md Phase 4 — this list is employee-driven
    // (LEFT JOIN payroll), so hardcoding status='active' hid an inactivated
    // employee's ENTIRE payroll history, for every period, from this page —
    // even though the rows themselves were never touched. Opt-in only: an
    // inactive employee never needs payroll RUN, just occasionally looked up.
    $include_inactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] == '1';
    
    // Columns for sorting mapping (must match payroll.php 11-column layout)
    $columns = [
        0  => 'p.payroll_id',      // Checkbox
        1  => 'p.payroll_id',      // S/NO (not sortable)
        2  => 'e.first_name',      // Employee
        3  => 'd.department_name', // Department
        4  => 'p.basic_salary',    // Basic
        5  => 'p.allowances',      // Allowance
        6  => 'p.gross_salary',    // Gross
        7  => 'p.deductions',      // Deductions
        8  => 'p.net_salary',      // Net
        9  => 'p.payment_status',  // Status
        10 => 'p.payroll_id'       // Action
    ];
    
    $order_column_index = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 1;
    $order_dir = isset($_GET['order'][0]['dir']) && strtolower($_GET['order'][0]['dir']) == 'desc' ? 'DESC' : 'ASC';
    $order_column = $columns[$order_column_index] ?? 'e.first_name';

    // Base Query Logic: Active Employees LEFT JOIN Payroll
    // This ensures we show Unprocessed employees
    
    $where_sql = $include_inactive ? "1=1" : "e.status = 'active'";
    // Phase D — project-scope filter on the employee (nullable: global employees visible)
    if (function_exists('scopeFilterSqlNullable')) {
        $where_sql .= scopeFilterSqlNullable('project', 'e');
    }
    $params = [];
    
    // Filter by Payroll Period (Join Condition)
    // We bind this in the JOIN clause usually, but here we can just join on it.
    // However, for correct filtering, we want rows where (e.id = p.id AND period = X) OR p.id IS NULL (but standard left join logic applies)
    // LEFT JOIN payroll p ON e.employee_id = p.employee_id AND p.payroll_period = ?
    $join_period = $period;

    // Additional Filters
    if (!empty($status_filter)) {
        if ($status_filter == 'unprocessed') {
            $where_sql .= " AND p.payroll_id IS NULL";
        } else {
            $where_sql .= " AND p.payment_status = ?";
            $params[] = $status_filter;
        }
    }
    
    if ($department_filter > 0) {
        $where_sql .= " AND e.department_id = ?";
        $params[] = $department_filter;
    }
    
    if (!empty($search_value)) {
        $where_sql .= " AND (
            e.first_name LIKE ? OR 
            e.last_name LIKE ? OR 
            e.employee_number LIKE ? OR 
            p.payroll_number LIKE ?
        )";
        $term = "%$search_value%";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    // 1. Get Total Records (Active Employees, or all if Include Inactive is on) — scope-aware
    $scopeTotal = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'employees') : '';
    $totalWhere = $include_inactive ? '1=1' : "status = 'active'";
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE $totalWhere $scopeTotal");
    $recordsTotal = $total_stmt->fetchColumn();

    // 2. Get Filtered Records logic requires full joins
    $count_sql = "
        SELECT COUNT(*) 
        FROM employees e
        LEFT JOIN payroll p ON e.employee_id = p.employee_id AND p.payroll_period = ?
        WHERE $where_sql
    ";
    
    // Prepend period to params for the LEFT JOIN
    $count_params = array_merge([$join_period], $params);
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $recordsFiltered = $count_stmt->fetchColumn();

    // 3. Get Data
    $sql = "
        SELECT 
            p.*,
            COALESCE(p.payment_status, 'unprocessed') as payment_status_display,
            e.employee_id,
            e.first_name,
            e.last_name,
            e.employee_number,
            e.department_id,
            e.basic_salary as emp_basic_salary,
            d.department_name
        FROM employees e
        LEFT JOIN payroll p ON e.employee_id = p.employee_id AND p.payroll_period = ?
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE $where_sql
        ORDER BY $order_column $order_dir
        LIMIT :limit OFFSET :offset
    ";
    
    // Prepare params: JOIN period + where params
    // We cannot use array_merge easily with bindValue for limit/offset
    // So we'll use positional binding for the first part, and bindValue for limit/offset
    
    $stmt = $pdo->prepare($sql);
    
    // Bind Period (Pos 1)
    $stmt->bindValue(1, $join_period);
    
    // Bind WHERE params (Pos 2 to N)
    foreach ($params as $k => $v) {
        $stmt->bindValue($k + 2, $v);
    }
    
    // Bind Limit/Offset (Named? No, mixing is bad if we used ? above. But PDO allows binding by 1-based index)
    // Parameter count so far: 1 + count($params)
    $offset_pos = 1 + count($params) + 1; // Not working like that with LIMIT :limit.
    // If query has named placeholders for limit, we can mix if emulation is off?
    // Safer: Use standard int binding to the last placeholders if usage ? ? ? LIMIT ?, ?
    
    // Let's rewrite query to use strictly named params or strictly positional.
    // Positional is easier with dynamic WHERE array.
    
    $sql_positional = "
        SELECT 
            p.*,
            COALESCE(p.payment_status, 'unprocessed') as payment_status_display,
            e.employee_id,
            e.first_name,
            e.last_name,
            e.employee_number,
            e.department_id,
            e.basic_salary as emp_basic_salary,
            e.status as employee_status,
            d.department_name
        FROM employees e
        LEFT JOIN payroll p ON e.employee_id = p.employee_id AND p.payroll_period = ?
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE $where_sql
        ORDER BY $order_column $order_dir
    ";
    
    // Append LIMIT logic
    if ($length != -1) {
        $sql_positional .= " LIMIT ?, ?";
    }
    
    $stmt = $pdo->prepare($sql_positional);
    
    $bind_index = 1;
    $stmt->bindValue($bind_index++, $join_period);
    
    foreach ($params as $val) {
        $stmt->bindValue($bind_index++, $val);
    }
    
    if ($length != -1) {
        $stmt->bindValue($bind_index++, (int)$start, PDO::PARAM_INT); // Offset
        $stmt->bindValue($bind_index++, (int)$length, PDO::PARAM_INT); // Limit
    }
    
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Helper to get effective salary
    foreach ($data as &$row) {
        // Fallback for missing gross_salary or unprocessed rows
        $row['gross_salary'] = $row['gross_salary'] ?? ($row['basic_salary'] + ($row['allowances'] ?? 0));
        
        if (empty($row['payroll_id'])) {
            // Unprocessed reconstruction based on employee profile
            $row['basic_salary'] = $row['emp_basic_salary'];
            $row['allowances'] = 0; 
            $row['deductions'] = 0;
            $row['gross_salary'] = $row['emp_basic_salary']; // No allowances yet
            $row['net_salary'] = $row['emp_basic_salary']; 
            $row['payment_status'] = 'unprocessed';
            $row['payroll_period'] = $period;
        }
        
        // Full Name
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
    }

    // 4. Get Summary Stats for the selected period
    $summary_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_employees,
            SUM(CASE WHEN p.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN p.payment_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN p.payroll_id IS NULL THEN 1 ELSE 0 END) as unprocessed_count,
            SUM(COALESCE(p.net_salary, 0)) as total_payout
        FROM employees e
        LEFT JOIN payroll p ON e.employee_id = p.employee_id AND p.payroll_period = ?
        WHERE e.status = 'active' " . (function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'e') : '') . "
    ");
    $summary_stmt->execute([$join_period]);
    $stats = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'draw' => $draw,
        'recordsTotal' => intval($recordsTotal),
        'recordsFiltered' => intval($recordsFiltered),
        'data' => $data,
        'stats' => $stats
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
