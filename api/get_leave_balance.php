<?php
// File: api/get_leave_balance.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_leave.log');

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/leave_balance.php';   // Plan H3 — drift-proof balance

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
    // Plan H3 — use the drift-proof engine. It accepts either the enum or the config
    // type_name and normalises internally, so `used_days` is now correct (the old query
    // compared the enum column against the type_name and silently returned ~0).
    $req_stmt = $pdo->prepare("SELECT requires_document FROM leave_types WHERE LOWER(type_name) LIKE ? AND status = 'active' ORDER BY type_id LIMIT 1");
    $req_stmt->execute(['%' . strtolower(leaveNormalizeEnum($leave_type)) . '%']);
    $requires_doc = (int)($req_stmt->fetchColumn() ?: 0);

    $b = leaveBalanceFor($pdo, $employee_id, $leave_type, (int)date('Y'));
    if (!$b['tracked']) {
        throw new Exception("Leave type not found or inactive");
    }

    echo json_encode([
        'success' => true,
        'balance' => [
            'used_days'    => $b['used'],
            'entitled'     => $b['entitled'],
            'carried_over' => $b['carried_over'],
            'available'    => $b['available'],
            'is_paid'      => $b['is_paid'],
        ],
        // Back-compat: the apply form reads these top-level fields.
        'max_days_per_year' => (int)round($b['entitled'] + $b['carried_over']),
        'requires_document' => $requires_doc,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
