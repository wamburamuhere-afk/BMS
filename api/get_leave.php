<?php
// File: api/get_leave.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Leave ID']);
    exit();
}

global $pdo;

try {
    $stmt = $pdo->prepare("
        SELECT 
            l.*,
            l.leave_type as leave_type_display,
            e.first_name,
            e.last_name,
            e.employee_number,
            d.department_name,
            u.username as applied_by_name
        FROM leaves l
        LEFT JOIN employees e ON l.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN users u ON l.applied_by = u.user_id
        WHERE l.leave_id = ?
    ");
    $stmt->execute([$id]);
    $leave = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($leave) {
        echo json_encode(['success' => true, 'data' => $leave]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Leave application not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
