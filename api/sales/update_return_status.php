<?php
// File: api/sales/update_return_status.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('sales_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change sales return status']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

global $pdo;
$return_id = intval($_POST['return_id'] ?? 0);
$status = $_POST['status'] ?? '';

if ($return_id <= 0 || empty($status)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Whitelist — must match the sales_returns.status ENUM. Defence in depth
// against another silent-truncation bug like the 'refunded' one fixed by
// migrations/2026_05_24_sales_returns_refunded_status.php.
$valid_statuses = ['pending', 'approved', 'rejected', 'completed', 'cancelled', 'refunded'];
if (!in_array($status, $valid_statuses, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status. Allowed: ' . implode(', ', $valid_statuses)
    ]);
    exit;
}

// Returns three-approval slice: pending->reviewed and reviewed->approved
// transitions must go through the canonical endpoints
// (api/sales/review_return.php and api/sales/approve_return.php) so the
// workflow_signatures row is captured. This endpoint stays usable for
// post-approval transitions (refunded, completed, cancelled, rejected).
if (in_array($status, ['reviewed', 'approved'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Use the canonical Review/Approve buttons on the return view to perform this transition.'
    ]);
    exit;
}

// Phase 1H double-count guard: a return that already has an active credit note
// must NOT also be marked 'refunded' — the credit note carries the refund
// recognition (income statement + cash flow). Settle it from the credit note.
if ($status === 'refunded') {
    try {
        $cnChk = $pdo->prepare("SELECT credit_note_number FROM credit_notes
                                 WHERE sales_return_id = ? AND status NOT IN ('deleted','rejected','cancelled') LIMIT 1");
        $cnChk->execute([$return_id]);
        if ($cnNo = $cnChk->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => "This return is credited by note $cnNo — record the refund from that credit note instead."]);
            exit;
        }
    } catch (Throwable $e) { /* credit_notes table absent on older servers — allow legacy behaviour */ }
}

try {
    $stmt = $pdo->prepare("UPDATE sales_returns SET status = ? WHERE sales_return_id = ?");
    $stmt->execute([$status, $return_id]);

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    
    $stmt_num = $pdo->prepare("SELECT return_number FROM sales_returns WHERE sales_return_id = ?");
    $stmt_num->execute([$return_id]);
    $return_num = $stmt_num->fetchColumn();
    
    logActivity($pdo, $_SESSION['user_id'], 'Update Sales Return Status', "$user_name updated Sales Return #$return_num status to " . ucfirst($status));

    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
