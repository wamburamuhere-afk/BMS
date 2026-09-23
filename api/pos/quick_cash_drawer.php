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

// Self-heal: ensure cash_register_transactions.client_uuid exists (DDL outside any transaction).
try {
    if (!$pdo->query("SHOW COLUMNS FROM cash_register_transactions LIKE 'client_uuid'")->fetch()) {
        $pdo->exec("ALTER TABLE cash_register_transactions ADD COLUMN client_uuid VARCHAR(36) NULL");
        try { $pdo->exec("ALTER TABLE cash_register_transactions ADD UNIQUE KEY ux_crt_client_uuid (client_uuid)"); } catch (PDOException $_ddlE) {}
    }
} catch (PDOException $_ddlE) {}

$type   = trim($_POST['type']   ?? '');
$amount = (float)($_POST['amount'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$userId = (int)$_SESSION['user_id'];

// Parse idempotency key.
$crt_client_uuid = '';
$rawCrtUuid = trim($_POST['client_uuid'] ?? '');
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rawCrtUuid)) {
    $crt_client_uuid = $rawCrtUuid;
}

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

// Idempotency pre-check.
if ($crt_client_uuid !== '') {
    try {
        $crtDupChk = $pdo->prepare("SELECT shift_id FROM cash_register_transactions WHERE client_uuid = ? LIMIT 1");
        $crtDupChk->execute([$crt_client_uuid]);
        if ($crtDup = $crtDupChk->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                'success'   => true,
                'idempotent'=> true,
                'message'   => 'Transaction already recorded.',
                'shift_id'  => (int)$crtDup['shift_id'],
            ]);
            exit;
        }
    } catch (PDOException $_crtIdemp) {
        $crt_client_uuid = '';
    }
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
           (client_uuid, shift_id, transaction_type, amount, payment_method, reason, created_by, created_at)
         VALUES (?, ?, ?, ?, 'cash', ?, ?, NOW())"
    )->execute([$crt_client_uuid ?: null, $shiftId, $type, $amount, $reason ?: null, $userId]);

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
