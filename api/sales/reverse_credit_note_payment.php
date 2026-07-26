<?php
// File: api/sales/reverse_credit_note_payment.php
// Reverses a paid credit note's refund — the counterpart to pay_credit_note.php.
// Posts a contra of the exact legs originally posted (Style B: never delete/edit
// a posted entry) and restores the note to 'approved' so it can be re-refunded
// if needed. Reachable only from the credit note's own page, never generically
// from the Journal Entries screen.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/payment_source.php';   // reverseOutflowContra
require_once __DIR__ . '/../../core/sales_posting.php';    // reverseCreditNoteRestock
require_once __DIR__ . '/../../core/recon_period_lock.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
// Reversing a payment is at least as sensitive as recording one — same gate as pay_credit_note.php.
if (!canApprove('credit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

global $pdo;
$id = intval($_POST['credit_note_id'] ?? 0);
if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid credit note ID']); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM credit_notes WHERE credit_note_id = ?");
    $stmt->execute([$id]);
    $cn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cn) { echo json_encode(['success' => false, 'message' => 'Credit note not found']); exit; }
    if ($cn['status'] !== 'paid') { echo json_encode(['success' => false, 'message' => 'Only a refunded credit note can have its payment reversed.']); exit; }
    if (empty($cn['payment_transaction_id'])) { echo json_encode(['success' => false, 'message' => 'No payment transaction is linked to this credit note.']); exit; }

    $txnId = (int)$cn['payment_transaction_id'];

    // Period lock: block reversal if the original entry falls in a finalized reconciliation period
    $mirrorEntry = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'books_transaction' AND entity_id = ? AND status = 'posted' LIMIT 1");
    $mirrorEntry->execute([$txnId]);
    if ($eid = $mirrorEntry->fetchColumn()) {
        assertNotInFinalizedReconPeriod($pdo, (int)$eid);
    }

    $pdo->beginTransaction();

    $rev = reverseOutflowContra($pdo, $txnId, (int)$_SESSION['user_id']);
    if (empty($rev['reversed'])) {
        throw new Exception('Could not reverse the refund ledger entry (' . ($rev['reason'] ?? 'unknown') . ').');
    }

    // Reverse the inventory/COGS restock leg too, if one was posted (a service /
    // price-adjustment note never posted one — reverseCreditNoteRestock no-ops then).
    reverseCreditNoteRestock($pdo, $id, (int)$_SESSION['user_id']);

    $pdo->prepare("
        UPDATE credit_notes
           SET status = 'approved', paid_by = NULL, paid_at = NULL,
               paid_from_account_id = NULL, payment_transaction_id = NULL, payment_reference = NULL,
               updated_at = NOW()
         WHERE credit_note_id = ?
    ")->execute([$id]);

    $pdo->commit();

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Reverse Credit Note Payment',
        "$user_name reversed the refund for Credit Note #{$cn['credit_note_number']} (TZS " . number_format((float)$cn['grand_total'], 2) . ")");
    if (function_exists('logAudit')) {
        logAudit($pdo, $_SESSION['user_id'], 'credit_note_payment_reversed', [
            'entity_type' => 'credit_note', 'entity_id' => $id,
            'old_values'  => ['status' => 'paid'],
            'new_values'  => ['status' => 'approved'],
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Refund reversed. The credit note is approved again and can be re-refunded if needed.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('reverse_credit_note_payment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
