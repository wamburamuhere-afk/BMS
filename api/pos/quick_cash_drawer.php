<?php
/**
 * API: Record cash in / cash out against the current user's open shift.
 * POST body (form-encoded):
 *   type   — 'cash_in' | 'cash_out'  (required)
 *   amount — positive decimal         (required)
 *   reason — short description        (optional)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php'; mobileBearerAuth();

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

global $pdo;

$type   = trim($_POST['type']   ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$userId = (int)$_SESSION['user_id'];

if (!in_array($type, ['cash_in', 'cash_out'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'type must be cash_in or cash_out']);
    exit;
}
if ($amount <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit;
}

try {
    // Find the user's currently open shift
    $shiftStmt = $pdo->prepare(
        "SELECT shift_id FROM cash_register_shifts
          WHERE user_id = ? AND status = 'active'
          ORDER BY start_time DESC LIMIT 1"
    );
    $shiftStmt->execute([$userId]);
    $shiftId = $shiftStmt->fetchColumn();

    if (!$shiftId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No open shift found. Open a shift first.']);
        exit;
    }

    $pdo->prepare(
        "INSERT INTO cash_register_transactions
           (shift_id, transaction_type, amount, payment_method, reason, created_by, created_at)
         VALUES (?, ?, ?, 'cash', ?, ?, NOW())"
    )->execute([$shiftId, $type, $amount, $reason ?: null, $userId]);

    $label = $type === 'cash_in' ? 'Cash in' : 'Cash out';
    echo json_encode([
        'success'  => true,
        'message'  => "$label of TZS " . number_format($amount, 2) . " recorded.",
        'shift_id' => (int)$shiftId,
    ]);

} catch (Throwable $e) {
    error_log('quick_cash_drawer error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
