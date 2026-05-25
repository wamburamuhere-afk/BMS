<?php
// File: api/get_leave_balance.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_leave.log');

require_once __DIR__ . '/../roots.php';

ob_clean();
header('Content-Type: application/json');

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : 0;
$leave_type = isset($_GET['leave_type']) ? trim($_GET['leave_type']) : '';

if (!$employee_id || !$leave_type) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Phase D — gate via the employee's project
if (function_exists('assertScopeForEmployee')) {
    assertScopeForEmployee($employee_id);
}

try {
    // Get leave type details
    $stmt = $pdo->prepare("SELECT max_days_per_year, requires_document FROM leave_types WHERE type_name = ? AND status = 'active'");
    $stmt->execute([$leave_type]);
    $type_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$type_info) {
        throw new Exception("Leave type not found or inactive");
    }

    // Get used days for the current year
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_days), 0) as used_days 
        FROM leaves 
        WHERE employee_id = ? 
        AND leave_type = ? 
        AND status = 'approved' 
        AND YEAR(start_date) = YEAR(CURDATE())
    ");
    $stmt->execute([$employee_id, $leave_type]);
    $used_days = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'balance' => [
            'used_days' => floatval($used_days)
        ],
        'max_days_per_year' => intval($type_info['max_days_per_year']),
        'requires_document' => intval($type_info['requires_document'])
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
