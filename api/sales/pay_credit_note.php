<?php
// File: api/sales/pay_credit_note.php
// scope-audit: skip — the sales_orders read only resolves the project tag for the
// ledger entry; access is already gated by canApprove + the note's own lifecycle.
// Settles an APPROVED credit note as a cash REFUND OUT to the customer:
//   Dr "Sales Returns & Allowances" (contra-revenue) / Cr Cash/Bank (Paid From)
// via the shared postOutflow() ledger helper, then marks the note 'paid'.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/payment_source.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
// Payment is a senior, post-approval action — gated with canApprove (no separate
// can_post column exists in role_permissions).
if (!canApprove('credit_notes')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

global $pdo;
$id          = intval($_POST['credit_note_id'] ?? 0);
$paidFrom    = intval($_POST['paid_from_account_id'] ?? 0);
$reference   = trim($_POST['payment_reference'] ?? '');

if ($id <= 0)       { echo json_encode(['success' => false, 'message' => 'Invalid credit note ID']); exit; }
if ($paidFrom <= 0) { echo json_encode(['success' => false, 'message' => 'Select the Paid From account']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT cn.*, c.customer_name
          FROM credit_notes cn
          LEFT JOIN customers c ON cn.customer_id = c.customer_id
         WHERE cn.credit_note_id = ?
    ");
    $stmt->execute([$id]);
    $cn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cn || $cn['status'] === 'deleted') { echo json_encode(['success' => false, 'message' => 'Credit note not found']); exit; }
    if ($cn['status'] === 'paid') { echo json_encode(['success' => false, 'message' => 'This credit note is already refunded.']); exit; }
    if ($cn['status'] !== 'approved') { echo json_encode(['success' => false, 'message' => 'Only an approved credit note can be refunded.']); exit; }

    $amount = (float)$cn['grand_total'];
    if ($amount <= 0) { echo json_encode(['success' => false, 'message' => 'Credit note amount must be greater than zero.']); exit; }

    // Contra-revenue debit account (seeded by the foundation migration)
    $sraAccountId = (int)getSetting('default_sales_returns_account_id', 0);
    if ($sraAccountId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Refund account is not configured (default_sales_returns_account_id).']);
        exit;
    }

    // Resolve project tag via the linked sales return → sales order (if any)
    $projectId = null;
    if (!empty($cn['sales_return_id'])) {
        $p = $pdo->prepare("SELECT so.project_id FROM sales_returns sr
                              LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
                             WHERE sr.sales_return_id = ?");
        $p->execute([(int)$cn['sales_return_id']]);
        $projectId = ($v = $p->fetchColumn()) ? (int)$v : null;
    }

    $desc = "Credit note refund {$cn['credit_note_number']} — " . ($cn['customer_name'] ?? 'customer');
    $txnId = postOutflow($pdo, 'credit_note_refund', $paidFrom, $sraAccountId,
                         $amount, $cn['credit_date'], $cn['credit_note_number'], $desc, $projectId);

    if (!$txnId) {
        echo json_encode(['success' => false, 'message' => 'Could not post the refund to the ledger. Check the cash/bank and refund accounts.']);
        exit;
    }

    $pdo->prepare("
        UPDATE credit_notes
           SET status = 'paid', paid_by = ?, paid_at = NOW(),
               paid_from_account_id = ?, payment_transaction_id = ?, payment_reference = ?, updated_at = NOW()
         WHERE credit_note_id = ?
    ")->execute([$_SESSION['user_id'], $paidFrom, $txnId, ($reference !== '' ? $reference : null), $id]);

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Refund Credit Note',
        "$user_name refunded Credit Note #{$cn['credit_note_number']} (TZS " . number_format($amount, 2) . ")");
    if (function_exists('logAudit')) {
        logAudit($pdo, $_SESSION['user_id'], 'credit_note_paid', [
            'entity_type' => 'credit_note', 'entity_id' => $id,
            'old_values'  => ['status' => 'approved'],
            'new_values'  => ['status' => 'paid', 'amount' => $amount, 'paid_from_account_id' => $paidFrom, 'transaction_id' => $txnId],
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Refund recorded. Credit note marked as paid.']);
} catch (Exception $e) {
    error_log('pay_credit_note error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
