<?php
require_once __DIR__ . '/../roots.php';

// Log export action
logAudit($pdo, $_SESSION['user_id'], 'export_attendance', [
    'activity_type' => 'export',
    'entity_type' => 'attendance',
    'description' => "Exported attendance report (Mode: " . ($_GET['view'] ?? 'day') . ")"
]);

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

// Get filters
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'day';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$department_id = isset($_GET['department']) ? $_GET['department'] : '';

// Build Query
$params = [];
if ($view_mode == 'day') {
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    
    $sql = "SELECT e.employee_number, e.first_name, e.last_name, d.department_name, 
            a.check_in_time, a.check_out_time, a.total_hours, a.status, a.notes 
            FROM employees e 
            LEFT JOIN departments d ON e.department_id = d.department_id 
            LEFT JOIN attendance a ON e.employee_id = a.employee_id AND a.attendance_date = ? 
            WHERE 1=1";
    $params[] = $date;
    
    if ($status) {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }
} else {
    // Week or Month View
    if ($view_mode == 'week') {
        $week = isset($_GET['week']) ? $_GET['week'] : date('Y-\nW');
        // Calculate start/end of week
        $dto = new DateTime();
        if (strpos($week, '-W') !== false) {
             $parts = explode('-W', $week);
             $dto->setISODate($parts[0], $parts[1]);
        } else {
             $dto->setISODate(substr($week, 0, 4), substr($week, 6));
        }
        $start_date = $dto->format('Y-m-d');
        $dto->modify('+6 days');
        $end_date = $dto->format('Y-m-d');
    } else {
        $month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
        $start_date = date('Y-m-01', strtotime($month));
        $end_date = date('Y-m-t', strtotime($month));
    }
    
    $sql = "SELECT e.employee_number, e.first_name, e.last_name, d.department_name,
            SUM(CASE WHEN a.total_hours > 0 AND a.total_hours <= 24 THEN a.total_hours ELSE 0 END) as total_hours,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.department_id
            LEFT JOIN attendance a ON e.employee_id = a.employee_id AND a.attendance_date BETWEEN ? AND ?
            WHERE 1=1";
    $params[] = $start_date;
    $params[] = $end_date;
}

// Common filters
if ($department_id) {
    $sql .= " AND e.department_id = ?";
    $params[] = $department_id;
}

if ($search) {
    $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($view_mode != 'day') {
    $sql .= " GROUP BY e.employee_id";
}

$sql .= " ORDER BY e.first_name, e.last_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare CSV Data
$csv_data = [];

if ($view_mode == 'day') {
    $csv_data[] = ['Employee ID', 'First Name', 'Last Name', 'Department', 'Check In', 'Check Out', 'Hours', 'Status', 'Notes'];
    foreach ($results as $row) {
        $csv_data[] = [
            $row['employee_number'],
            $row['first_name'],
            $row['last_name'],
            $row['department_name'],
            $row['check_in_time'] ? date('H:i', strtotime($row['check_in_time'])) : '',
            $row['check_out_time'] ? date('H:i', strtotime($row['check_out_time'])) : '',
            $row['total_hours'],
            ucfirst($row['status'] ?: 'absent'),
            $row['notes']
        ];
    }
} else {
    $csv_data[] = ['Employee ID', 'First Name', 'Last Name', 'Department', 'Total Hours', 'Present Days', 'Late Days', 'Absent Days'];
    foreach ($results as $row) {
        $csv_data[] = [
            $row['employee_number'],
            $row['first_name'],
            $row['last_name'],
            $row['department_name'],
            number_format($row['total_hours'], 2),
            $row['present_count'],
            $row['late_count'],
            $row['absent_count']
        ];
    }
}

// Output
$filename = "attendance_export_" . date('Y-m-d') . ".csv";
outputCSV($csv_data, $filename);
