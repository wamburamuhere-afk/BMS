<?php
// File: api/export_leave_applications.php
require_once __DIR__ . '/../roots.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$leave_ids_raw = $_POST['leave_ids'] ?? '[]';
$leave_ids = json_decode($leave_ids_raw, true);

if (empty($leave_ids) || !is_array($leave_ids)) {
    die("No leaves selected for export");
}

global $pdo;
$placeholders = implode(',', array_fill(0, count($leave_ids), '?'));
$query = "
    SELECT 
        l.leave_id as reference,
        e.first_name,
        e.last_name,
        e.employee_number,
        d.department_name,
        l.leave_type,
        l.start_date,
        l.end_date,
        l.total_days,
        l.reason,
        l.status,
        l.created_at
    FROM leaves l
    LEFT JOIN employees e ON l.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE l.leave_id IN ($placeholders)" . scopeFilterSql('employee', 'e') . "
    ORDER BY l.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($leave_ids);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$leaves) {
    die("No leaves found matching selection");
}

// Generate CSV
$filename = "leave_applications_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, [
    'Reference', 
    'Employee Name', 
    'Employee Number', 
    'Department', 
    'Leave Type', 
    'Start Date', 
    'End Date', 
    'Total Days', 
    'Reason', 
    'Status', 
    'Applied Date'
]);

foreach ($leaves as $leave) {
    fputcsv($output, [
        'LEV-' . $leave['reference'],
        $leave['first_name'] . ' ' . $leave['last_name'],
        $leave['employee_number'],
        $leave['department_name'],
        ucfirst($leave['leave_type']),
        $leave['start_date'],
        $leave['end_date'],
        $leave['total_days'],
        $leave['reason'],
        ucfirst($leave['status']),
        $leave['created_at']
    ]);
}

fclose($output);
exit();
