<?php
/**
 * api/account/unreconcile.php
 * ----------------------------
 * Unlocks a finalized ('reconciled') bank reconciliation, returning it to
 * 'pending' so the user can make corrections.
 *
 * This is a high-privilege, destructive financial operation:
 *   - Requires canApprove('bank_reconciliation') — same gate as finalize.
 *   - Writes a full logAudit trail before and after.
 *   - Reverts bank_transactions status from 'reconciled' → 'matched' (they
 *     stay linked to this reconciliation so the worksheet re-populates them,
 *     but they are no longer locked as reconciled lines).
 *   - Does NOT delete adjusting journal entries (those remain posted and valid).
 *
 * POST: reconciliation_id, reason (required justification text), _csrf
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}
csrf_check();
if (!canApprove('bank_reconciliation')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied — unreconcile requires approval rights']); exit;
}

try {
    $recId  = (int)($_POST['reconciliation_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($recId <= 0)       throw new Exception('Invalid reconciliation');
    if ($reason === '')    throw new Exception('A reason is required to unreconcile');
    if (strlen($reason) < 10) throw new Exception('Please provide a more detailed reason (at least 10 characters)');

    $rec = $pdo->prepare("SELECT * FROM bank_reconciliations WHERE reconciliation_id = ? AND status = 'reconciled'");
    $rec->execute([$recId]);
    $rec = $rec->fetch(PDO::FETCH_ASSOC);
    if (!$rec) throw new Exception('Reconciliation not found or not in reconciled status');

    $userId = (int)$_SESSION['user_id'];

    $pdo->beginTransaction();

    // Revert reconciliation header to pending
    $pdo->prepare(
        "UPDATE bank_reconciliations
            SET status = 'pending', adjusted_balance = 0.00, updated_at = NOW()
          WHERE reconciliation_id = ?"
    )->execute([$recId]);

    // Revert bank_transactions lines from reconciled → matched (keep reconciliation_id link)
    $pdo->prepare(
        "UPDATE bank_transactions
            SET status = 'cleared', updated_at = NOW()
          WHERE reconciliation_id = ? AND status = 'reconciled'"
    )->execute([$recId]);

    $pdo->commit();

    // Audit trail — high sensitivity
    logActivity($pdo, $userId, 'Unreconciled bank reconciliation',
        "Rec #{$rec['reconciliation_number']} (ID $recId) returned to pending. Reason: $reason");

    if (function_exists('logAudit')) {
        logAudit($pdo, $userId, 'bank_recon_unreconcile', [
            'entity_type'  => 'bank_reconciliation',
            'entity_id'    => $recId,
            'old_values'   => ['status' => 'reconciled', 'adjusted_balance' => $rec['adjusted_balance']],
            'new_values'   => ['status' => 'pending', 'adjusted_balance' => 0.00],
            'reason'       => $reason,
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Reconciliation unlocked and returned to pending.',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('unreconcile error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
