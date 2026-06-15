<?php
// File: api/purchase/pay_debit_note.php
// scope-audit: skip — the purchase_orders read only resolves the project tag for
// the ledger entry; access is already gated by canApprove + the note's lifecycle.
// Settles an APPROVED debit note as a cash REFUND IN received from the supplier:
//   Dr Cash/Bank (Received Into) / Cr Accounts Payable
// (purchase-side: nets the AP debit the linked purchase return raises; NOT income),
// via the shared postInflow() ledger helper, then marks the note 'paid'.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/payment_source.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canApprove('debit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

global $pdo;
$id           = intval($_POST['debit_note_id'] ?? 0);
$receivedInto = intval($_POST['received_into_account_id'] ?? 0);
$reference    = trim($_POST['payment_reference'] ?? '');

if ($id <= 0)           { echo json_encode(['success' => false, 'message' => 'Invalid debit note ID']); exit; }
if ($receivedInto <= 0) { echo json_encode(['success' => false, 'message' => 'Select the Received Into account']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT dn.*, s.supplier_name
          FROM debit_notes dn
          LEFT JOIN suppliers s ON dn.supplier_id = s.supplier_id
         WHERE dn.debit_note_id = ?
    ");
    $stmt->execute([$id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn || $dn['status'] === 'deleted') { echo json_encode(['success' => false, 'message' => 'Debit note not found']); exit; }
    if ($dn['status'] === 'paid') { echo json_encode(['success' => false, 'message' => 'This debit note is already settled.']); exit; }
    if ($dn['status'] !== 'approved') { echo json_encode(['success' => false, 'message' => 'Only an approved debit note can be settled.']); exit; }

    $amount = (float)$dn['grand_total'];
    if ($amount <= 0) { echo json_encode(['success' => false, 'message' => 'Debit note amount must be greater than zero.']); exit; }

    // A supplier cash refund settling a debit note is a PURCHASE-side event, not income.
    // Credit Accounts Payable: this nets the AP debit that the linked purchase return
    // raises (OUT-8: Dr AP / Cr Inventory), so the refund neither hits revenue nor
    // double-counts the cost reduction. Resolve AP by role (gl_accounts).
    require_once __DIR__ . '/../../core/gl_accounts.php';
    $creditAccountId = (int)(apAccountId($pdo) ?? 0);
    if ($creditAccountId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Accounts Payable account is not configured.']);
        exit;
    }

    // Resolve project tag via the linked purchase return → purchase order (if any)
    $projectId = null;
    if (!empty($dn['purchase_return_id'])) {
        $p = $pdo->prepare("SELECT po.project_id FROM purchase_returns pr
                              LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
                             WHERE pr.purchase_return_id = ?");
        $p->execute([(int)$dn['purchase_return_id']]);
        $projectId = ($v = $p->fetchColumn()) ? (int)$v : null;
    }

    $desc = "Debit note refund {$dn['debit_note_number']} — " . ($dn['supplier_name'] ?? 'supplier');
    $txnId = postInflow($pdo, 'debit_note_refund', $receivedInto, $creditAccountId,
                        $amount, $dn['debit_date'], $dn['debit_note_number'], $desc, $projectId);

    if (!$txnId) {
        echo json_encode(['success' => false, 'message' => 'Could not post the refund to the ledger. Check the cash/bank and income accounts.']);
        exit;
    }

    $pdo->prepare("
        UPDATE debit_notes
           SET status = 'paid', paid_by = ?, paid_at = NOW(),
               received_into_account_id = ?, payment_transaction_id = ?, payment_reference = ?, updated_at = NOW()
         WHERE debit_note_id = ?
    ")->execute([$_SESSION['user_id'], $receivedInto, $txnId, ($reference !== '' ? $reference : null), $id]);

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Settle Debit Note',
        "$user_name recorded refund for Debit Note #{$dn['debit_note_number']} (TZS " . number_format($amount, 2) . ")");
    if (function_exists('logAudit')) {
        logAudit($pdo, $_SESSION['user_id'], 'debit_note_paid', [
            'entity_type' => 'debit_note', 'entity_id' => $id,
            'old_values'  => ['status' => 'approved'],
            'new_values'  => ['status' => 'paid', 'amount' => $amount, 'received_into_account_id' => $receivedInto, 'transaction_id' => $txnId],
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Refund recorded. Debit note marked as paid.']);
} catch (Exception $e) {
    error_log('pay_debit_note error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
