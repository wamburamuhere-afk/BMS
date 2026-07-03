<?php
// API: List an employee's documents (Tier 2 — new system rows only;
// legacy JSON slots stay rendered server-side on employee_details per D9)
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('employee_documents')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$employee_id = intval($_GET['employee_id'] ?? 0);
if (!$employee_id) {
    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
    exit;
}

if (function_exists('assertScopeForEmployee')) {
    assertScopeForEmployee($employee_id);
}

try {
    $stmt = $pdo->prepare("
        SELECT ed.*, dt.type_name, dt.requires_expiry,
               u.username AS uploaded_by_name,
               DATEDIFF(ed.expire_date, CURDATE()) AS days_to_expiry
        FROM employee_documents ed
        JOIN employee_document_types dt ON dt.doc_type_id = ed.doc_type_id
        LEFT JOIN users u ON u.user_id = ed.created_by
        WHERE ed.employee_id = ? AND ed.status = 'active'
        ORDER BY ed.created_at DESC
    ");
    $stmt->execute([$employee_id]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    error_log("get_employee_documents error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
