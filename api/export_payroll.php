<?php
// File: api/export_payroll.php
require_once __DIR__ . '/../roots.php';

// session_start() is handled in roots.php

// Basic Authentication Check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// Check permissions
$user_role = strtolower($_SESSION['user_role'] ?? '');
if (!in_array($user_role, ['admin', 'accountant', 'hr', 'manager'])) {
    http_response_code(403);
    exit('Forbidden');
}

// Helper function for outputting CSV
function outputCSV($data, $filename = "export.csv") {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}

try {
    // Get parameters
    $period = isset($_GET['period']) ? $_GET['period'] : date('Y-m');
    $department_filter = isset($_GET['department']) ? intval($_GET['department']) : 0;
    $search_value = isset($_GET['search']) ? $_GET['search'] : '';
    
    $where_sql = "e.status = 'active'";
    $params = [];
    $join_period = $period;

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

    $where_sql .= scopeFilterSql('employee', 'e');

    $sql = "
        SELECT 
            e.employee_number,
            e.first_name,
            e.last_name,
            d.department_name,
            COALESCE(p.basic_salary, e.basic_salary) as basic_salary,
            COALESCE(p.allowances, 0) as allowances,
            COALESCE(p.deductions, 0) as deductions,
            COALESCE(p.tax_amount, 0) as tax_amount,
            COALESCE(p.net_salary, e.basic_salary) as net_salary,
            COALESCE(p.payment_status, 'unprocessed') as payment_status,
            p.payroll_date,
            p.payment_method
        FROM employees e
        LEFT JOIN payroll p ON e.employee_id = p.employee_id AND p.payroll_period = ?
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE $where_sql
        ORDER BY e.first_name ASC, e.last_name ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $join_period);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k + 2, $v);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare CSV Data
    $csv_data = [];
    $csv_data[] = [
        'Staff ID', 
        'First Name', 
        'Last Name', 
        'Department', 
        'Basic Salary', 
        'Allowances', 
        'Deductions', 
        'Tax', 
        'Net Salary', 
        'Status', 
        'Date', 
        'Method'
    ];

    foreach ($results as $row) {
        $csv_data[] = [
            $row['employee_number'],
            $row['first_name'],
            $row['last_name'],
            $row['department_name'],
            number_format($row['basic_salary'], 2, '.', ''),
            number_format($row['allowances'], 2, '.', ''),
            number_format($row['deductions'], 2, '.', ''),
            number_format($row['tax_amount'], 2, '.', ''),
            number_format($row['net_salary'], 2, '.', ''),
            ucfirst($row['payment_status']),
            $row['payroll_date'] ?: '',
            ucfirst($row['payment_method'] ?: '')
        ];
    }

    // Log export action
    logAudit($pdo, $_SESSION['user_id'], 'export_payroll', [
        'activity_type' => 'export',
        'entity_type' => 'payroll',
        'description' => "Exported payroll list to Excel for period $period" . ($department_filter ? " (Dept ID: $department_filter)" : "")
    ]);

    // Output
    $filename = "payroll_export_" . $period . "_" . date('Ymd_His') . ".csv";
    outputCSV($csv_data, $filename);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
