<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/auto_post_hook.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!canEdit('expenses')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change expense status']);
    exit;
}

try {
    $expense_id = $_POST['expense_id'] ?? 0;
    $status = $_POST['status'] ?? '';

    if ($expense_id <= 0 || empty($status)) {
        throw new Exception('Missing required parameters');
    }

    // Phase C — block status changes against expenses on projects not in user scope
    assertScopeForRecord('expenses', 'expense_id', $expense_id);

    $allowed_statuses = ['pending', 'reviewed', 'approved', 'paid', 'rejected'];
    if (!in_array($status, $allowed_statuses)) {
        throw new Exception('Invalid status');
    }

    $actor       = workflowActorSnapshot();
    $extra_update = '';
    $action       = null;

    if ($status === 'reviewed') {
        $extra_update = ', reviewed_by = ' . intval($_SESSION['user_id']);
        $action       = 'reviewed';
    } elseif ($status === 'approved') {
        $extra_update = ', approved_by = ' . intval($_SESSION['user_id']);
        $action       = 'approved';
    }

    // Phase 4.5 — fetch expense snapshot BEFORE the UPDATE so we have the
    // amount / project / date for the auto-post call. Status check below
    // decides whether we actually post.
    $snap_stmt = $pdo->prepare("SELECT amount, expense_date, project_id, description
                                  FROM expenses WHERE expense_id = ?");
    $snap_stmt->execute([$expense_id]);
    $expense_snap = $snap_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense_snap) throw new Exception('Expense not found');

    // Wrap status change + signature + auto-post in one transaction so a
    // ledger-posting failure rolls back the status change too.
    $pdo->beginTransaction();

    $stmt   = $pdo->prepare("UPDATE expenses SET status = ?, updated_at = NOW(), updated_by = ? $extra_update WHERE expense_id = ?");
    $result = $stmt->execute([$status, $_SESSION['user_id'], $expense_id]);

    if (!$result) throw new Exception('Failed to update status');

    $sigResult = ['has_signature' => true];
    if ($action !== null) {
        $sigResult = workflowCaptureSignature($pdo, 'expense', $expense_id, $action,
            $_SESSION['user_id'], $actor['name'], $actor['role']);
    }

    // Phase 4.5 — auto-post to canonical ledger via journal_mappings.
    // Only the 'paid' transition writes to the ledger; pending/reviewed/
    // approved status changes are administrative and don't move money.
    // Quiet no-op while 'expense_paid' mapping is_active=0 (default).
    $post_result = ['posted' => false, 'reason' => 'status_not_paid'];
    if ($status === 'paid' && (float)$expense_snap['amount'] > 0) {
        $post_result = autoPostEvent(
            $pdo,
            'expense_paid',
            'expense',
            (int)$expense_id,
            (float)$expense_snap['amount'],
            $expense_snap['project_id'] !== null ? (int)$expense_snap['project_id'] : null,
            $expense_snap['expense_date'],
            (int)$_SESSION['user_id'],
            "Expense #{$expense_id} paid: " . substr((string)$expense_snap['description'], 0, 100)
        );
    }

    $pdo->commit();

    $log_note = "Updated expense status to '$status' for expense ID: $expense_id";
    if (!empty($post_result['posted'])) {
        $log_note .= " (journal entry #{$post_result['entry_id']})";
    } elseif (($post_result['reason'] ?? '') === 'already_posted') {
        $log_note .= " (already in ledger as entry #{$post_result['existing_entry_id']})";
    }
    logActivity($pdo, $_SESSION['user_id'], $log_note);

    $response = ['success' => true, 'message' => 'Expense status updated successfully'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    if (!empty($post_result['posted'])) {
        $response['journal_entry_id'] = $post_result['entry_id'];
    } elseif (($post_result['reason'] ?? '') === 'mapping_not_configured') {
        $response['ledger_warning'] = "Expense marked paid, but no ledger entry was created — admin has not "
                                    . "set both Dr/Cr accounts for 'expense_paid' in Journal Mappings.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Error in update_expense_status.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
