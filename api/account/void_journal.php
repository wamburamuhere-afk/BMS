<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
require_once __DIR__ . '/../../core/recon_period_lock.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!canEdit('journals')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to void journal entries']);
    exit;
}

try {
    $entry_id = (int)($_POST['entry_id'] ?? 0);

    if ($entry_id <= 0) {
        throw new Exception('Invalid journal entry ID');
    }

    // Period lock: block void if the entry falls in a finalized reconciliation period
    assertNotInFinalizedReconPeriod($pdo, $entry_id);

    $stmt = $pdo->prepare("UPDATE journal_entries SET status = 'void', updated_at = NOW(), updated_by = ? WHERE entry_id = ?");
    $result = $stmt->execute([$_SESSION['user_id'], $entry_id]);

    if ($result) {
        $getTxn = $pdo->prepare("SELECT transaction_id FROM journal_entries WHERE entry_id = ?");
        $getTxn->execute([$entry_id]);
        $transactionId = $getTxn->fetchColumn();

        if ($transactionId) {
            $delRes = deleteGlobalTransaction($transactionId, $pdo);
            if (!$delRes['success']) {
                throw new Exception("Global Transaction Deletion Failed: " . $delRes['error']);
            }
        }

        logActivity($pdo, $_SESSION['user_id'], "Voided journal entry ID: $entry_id");
            
        if (isset($_POST['redirect'])) {
            header("Location: /" . $_POST['redirect']);
            exit;
        }
        
        echo json_encode(['success' => true, 'message' => 'Journal entry voided successfully']);
    } else {
        throw new Exception('Failed to void journal entry');
    }

} catch (Exception $e) {
    error_log("Error in void_journal.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
