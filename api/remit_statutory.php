<?php
/**
 * api/remit_statutory.php
 * -----------------------
 * Remit ONE statutory obligation (PAYE / NSSF / SDL) for a period to the authority.
 * Posts a balanced ledger entry that REDUCES the chosen bank/cash account and clears
 * the matching liability (or, for SDL, recognises the employer expense), then marks
 * the statutory_remittances row paid.
 *
 *   PAYE : Dr PAYE Payable  / Cr Bank   (clears the liability raised at payment)
 *   NSSF : Dr NSSF Payable  / Cr Bank   (clears the liability raised at payment)
 *   SDL  : Dr SDL Expense   / Cr Bank   (employer cost recognised on remittance)
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';   // recordGlobalTransaction(), applyAccountBalanceDelta()

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: you cannot remit statutory taxes']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
if (function_exists('csrf_check')) csrf_check();

$id        = (int)($_POST['remittance_id'] ?? 0);
$paid_from = (int)($_POST['paid_from_account_id'] ?? 0);

if (!$id)        { echo json_encode(['success' => false, 'message' => 'Remittance ID required']); exit; }
if (!$paid_from) { echo json_encode(['success' => false, 'message' => 'Please choose the account the tax is paid from (Paid From)']); exit; }

try {
    $stmt = $pdo->prepare("SELECT * FROM statutory_remittances WHERE remittance_id = ?");
    $stmt->execute([$id]);
    $rem = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rem)                       { echo json_encode(['success' => false, 'message' => 'Remittance not found']); exit; }
    if ($rem['status'] === 'paid')   { echo json_encode(['success' => false, 'message' => 'This obligation is already remitted']); exit; }
    if ($rem['status'] === 'cancelled') { echo json_encode(['success' => false, 'message' => 'This obligation is cancelled']); exit; }

    $amount = round((float)$rem['amount'], 2);
    if ($amount <= 0) { echo json_encode(['success' => false, 'message' => 'Nothing to remit — amount is zero']); exit; }

    // Debit side per tax type: clear the corresponding payable. SDL is accrued at
    // processing (Dr SDL Expense / Cr SDL Payable), so remittance clears SDL Payable.
    $debit_setting = [
        'paye' => 'default_paye_payable_account_id',
        'nssf' => 'default_nssf_payable_account_id',
        'sdl'  => 'default_sdl_payable_account_id',
    ][$rem['tax_type']] ?? '';
    $debit_account = (int)getSetting($debit_setting, 0);
    if (!$debit_account) {
        echo json_encode(['success' => false, 'message' => 'Statutory account not configured for ' . strtoupper($rem['tax_type']) . '. Run the payroll foundation migration.']);
        exit;
    }

    $label = strtoupper($rem['tax_type']) . ' ' . $rem['period'];

    // Dr payable/expense, Cr bank — money leaves the bank, liability is cleared.
    $res = recordGlobalTransaction([
        'transaction_date' => date('Y-m-d'),
        'amount'           => $amount,
        'transaction_type' => 'statutory_remittance',
        'reference_number' => 'REMIT-' . strtoupper($rem['tax_type']) . '-' . $rem['period'],
        'description'      => "Remitted {$label} to authority",
        'journal_items'    => [
            ['account_id' => $debit_account, 'type' => 'debit',  'amount' => $amount, 'description' => "Remit {$label}"],
            ['account_id' => $paid_from,     'type' => 'credit', 'amount' => $amount, 'description' => "Remit {$label}"],
        ],
    ], $pdo);
    if (empty($res['success'])) {
        echo json_encode(['success' => false, 'message' => 'Failed to post the remittance to the ledger']);
        exit;
    }
    $txn = (int)$res['transaction_id'];

    // Move stored balances: bank ↓; debit a credit-normal payable ↓ (or expense ↑).
    applyAccountBalanceDelta($pdo, $debit_account, 'debit',  $amount);
    applyAccountBalanceDelta($pdo, $paid_from,     'credit', $amount);

    $pdo->prepare("UPDATE statutory_remittances
                      SET status = 'paid', paid_date = CURDATE(),
                          paid_from_account_id = ?, transaction_id = ?, updated_at = NOW()
                    WHERE remittance_id = ?")
        ->execute([$paid_from, $txn, $id]);

    logActivity($pdo, $_SESSION['user_id'], "Remitted {$label}", "Amount: " . number_format($amount, 2));
    logAudit($pdo, $_SESSION['user_id'], 'remit_statutory', [
        'activity_type' => 'update',
        'entity_type'   => 'statutory_remittance',
        'entity_id'     => $id,
        'description'   => "Remitted {$label} = " . number_format($amount, 2),
    ]);

    echo json_encode(['success' => true, 'message' => "Remitted {$label} successfully."]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
