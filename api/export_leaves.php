<?php
require_once __DIR__ . '/../roots.php';

// Check permissions
$user_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// Log export action
logAudit($pdo, $user_id, 'export_leaves', [
    'activity_type' => 'export',
    'entity_type' => 'leave',
    'description' => "Exported leaves report"
]);

$can_view_leaves = in_array(strtolower($user_role), ['admin', 'manager', 'hr', 'supervisor']);
$is_employee = (strtolower($user_role) == 'employee');

if (!$can_view_leaves && !$is_employee) {
    die("Access Denied");
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

// Get filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$status = isset($_GET['status']) ? $_GET['status'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$department_id = isset($_GET['department']) ? $_GET['department'] : '';
$employee_id = isset($_GET['employee']) ? $_GET['employee'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Restrict employee view
if ($is_employee && !$can_view_leaves) {
    $employee_id = $user_id;
}

// Build Query
$sql = "SELECT 
            l.leave_id,
            l.leave_type,
            l.start_date,
            l.end_date,
            l.total_days,
            l.reason,
            l.status,
            l.created_at,
            e.employee_number, 
            e.first_name, 
            e.last_name, 
            d.department_name,
            u.username as applied_by
        FROM leaves l 
        LEFT JOIN employees e ON l.employee_id = e.employee_id 
        LEFT JOIN departments d ON e.department_id = d.department_id 
        LEFT JOIN users u ON l.applied_by = u.user_id
        WHERE l.start_date BETWEEN ? AND ?
        ";

$params = [$start_date, $end_date];

if ($status) {
    $sql .= " AND l.status = ?";
    $params[] = $status;
}

if ($type) {
    $sql .= " AND l.leave_type = ?";
    $params[] = $type;
}

if ($department_id) {
    $sql .= " AND e.department_id = ?";
    $params[] = $department_id;
}

if ($employee_id) {
    $sql .= " AND l.employee_id = ?";
    $params[] = $employee_id;
}

if ($search) {
    $sql .= " AND (l.reason LIKE ? OR l.notes LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= scopeFilterSql('employee', 'e');
$sql .= " ORDER BY l.start_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare CSV Data
$csv_data = [];
$csv_data[] = ['ID', 'Employee', 'Department', 'Type', 'Start Date', 'End Date', 'Days', 'Reason', 'Status', 'Applied On'];

foreach ($results as $row) {
    $csv_data[] = [
        'LEV-' . $row['leave_id'],
        $row['first_name'] . ' ' . $row['last_name'] . ' (' . $row['employee_number'] . ')',
        $row['department_name'],
        $row['leave_type'],
        $row['start_date'],
        $row['end_date'],
        $row['total_days'],
        $row['reason'],
        ucfirst($row['status']),
        $row['created_at']
    ];
}

// Output
$filename = "leaves_export_" . date('Y-m-d') . ".csv";
outputCSV($csv_data, $filename);
?>
