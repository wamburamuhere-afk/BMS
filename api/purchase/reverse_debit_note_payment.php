<?php
// File: api/purchase/reverse_debit_note_payment.php
// Reverses a settled debit note's refund-received — the counterpart to
// pay_debit_note.php. Posts a contra of the exact legs originally posted
// (Style B: never delete/edit a posted entry) and restores the note to
// 'approved' so it can be re-settled if needed. Reachable only from the debit
// note's own page, never generically from the Journal Entries screen.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/payment_source.php';   // reverseInflowContra
require_once __DIR__ . '/../../core/recon_period_lock.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
// Reversing a payment is at least as sensitive as recording one — same gate as pay_debit_note.php.
if (!canApprove('debit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

global $pdo;
$id = intval($_POST['debit_note_id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid debit note ID']); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM debit_notes WHERE debit_note_id = ?");
    $stmt->execute([$id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn) { echo json_encode(['success' => false, 'message' => 'Debit note not found']); exit; }
    if ($dn['status'] !== 'paid') { echo json_encode(['success' => false, 'message' => 'Only a settled debit note can have its payment reversed.']); exit; }
    if (empty($dn['payment_transaction_id'])) { echo json_encode(['success' => false, 'message' => 'No payment transaction is linked to this debit note.']); exit; }

    $txnId = (int)$dn['payment_transaction_id'];

    // Period lock: block reversal if the original entry falls in a finalized reconciliation period
    $mirrorEntry = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ? AND status = 'posted' LIMIT 1");
    $mirrorEntry->execute([$txnId]);
    if ($eid = $mirrorEntry->fetchColumn()) {
        assertNotInFinalizedReconPeriod($pdo, (int)$eid);
    }

    $pdo->beginTransaction();

    $rev = reverseInflowContra($pdo, $txnId, (int)$_SESSION['user_id']);
    if (empty($rev['reversed'])) {
        throw new Exception('Could not reverse the settlement ledger entry (' . ($rev['reason'] ?? 'unknown') . ').');
    }

    $pdo->prepare("
        UPDATE debit_notes
           SET status = 'approved', paid_by = NULL, paid_at = NULL,
               received_into_account_id = NULL, payment_transaction_id = NULL, payment_reference = NULL,
               updated_at = NOW()
         WHERE debit_note_id = ?
    ")->execute([$id]);

    $pdo->commit();

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Reverse Debit Note Payment',
        "$user_name reversed the settlement for Debit Note #{$dn['debit_note_number']} (TZS " . number_format((float)$dn['grand_total'], 2) . ")");
    if (function_exists('logAudit')) {
        logAudit($pdo, $_SESSION['user_id'], 'debit_note_payment_reversed', [
            'entity_type' => 'debit_note', 'entity_id' => $id,
            'old_values'  => ['status' => 'paid'],
            'new_values'  => ['status' => 'approved'],
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Settlement reversed. The debit note is approved again and can be re-settled if needed.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('reverse_debit_note_payment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
