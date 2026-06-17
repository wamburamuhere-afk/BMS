<?php
/**
 * api/account/add_bank_transfer.php
 *
 * Create a bank/cash transfer as 'pending'. NO money moves at create — the
 * double entry + balance moves + register rows happen only at the Posted step
 * (update_bank_transfer_status.php), mirroring the post-gated expense flow.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';   // cashBankAccounts()
require_once __DIR__ . '/../../core/gl_accounts.php';      // bankChargesAccountId()
require_once __DIR__ . '/../../core/account_balance.php';  // accountLedgerBalance()
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canCreate('bank_transfers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to create bank transfers']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $transfer_date  = $_POST['transfer_date'] ?? '';
    $from_id        = (int)($_POST['from_account_id'] ?? 0);
    $to_id          = (int)($_POST['to_account_id'] ?? 0);
    $amount         = round((float)($_POST['amount'] ?? 0), 2);
    $charges        = round((float)($_POST['charges'] ?? 0), 2);
    $charge_acc_id  = (int)($_POST['charge_account_id'] ?? 0) ?: null;
    $reference      = trim($_POST['reference_number'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $project_id     = (isset($_POST['project_id']) && $_POST['project_id'] !== '') ? (int)$_POST['project_id'] : null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transfer_date)) throw new Exception('A valid transfer date is required.');
    if ($from_id <= 0 || $to_id <= 0)  throw new Exception('Both a source and destination account are required.');
    if ($from_id === $to_id)           throw new Exception('The source and destination accounts must be different.');
    if ($amount <= 0)                  throw new Exception('Transfer amount must be greater than zero.');
    if ($charges < 0)                  throw new Exception('Charges cannot be negative.');
    // Bank charges default to the canonical Bank Charges finance-cost account so they
    // always land in the Income Statement's FINANCE COSTS section, even if the cashier
    // doesn't pick an account explicitly.
    if ($charges > 0 && !$charge_acc_id) {
        $charge_acc_id = bankChargesAccountId($pdo);
        if (!$charge_acc_id) throw new Exception('No Bank Charges account is configured (default_bank_charges_account_id / 6-1900).');
    }

    // Both accounts must be valid cash/bank accounts.
    $cash = [];
    foreach (cashBankAccounts($pdo) as $a) $cash[(int)$a['account_id']] = $a;
    if (!isset($cash[$from_id])) throw new Exception('The source account is not a valid cash/bank account.');
    if (!isset($cash[$to_id]))   throw new Exception('The destination account is not a valid cash/bank account.');

    // A chosen project must be in the user's scope.
    if ($project_id !== null && !userCan('project', $project_id)) {
        throw new Exception('The selected project is not in your assigned scope.');
    }

    // Sufficient balance in the source (amount + charges).
    $bal = accountLedgerBalance($pdo, $from_id);
    $total = round($amount + $charges, 2);
    if ($bal < $total) {
        throw new Exception('Insufficient balance in the source account (available ' . number_format($bal, 2) . ', needed ' . number_format($total, 2) . ').');
    }

    // Transfer number: TRF-YYYY-NNNN (per §18 / §UI-6).
    $year = date('Y', strtotime($transfer_date));
    $seq  = (int)$pdo->query("SELECT COUNT(*) FROM bank_transfers WHERE YEAR(transfer_date) = " . (int)$year)->fetchColumn() + 1;
    $transfer_number = 'TRF-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO bank_transfers
            (transfer_number, transfer_date, from_account_id, to_account_id, amount, charges,
             charge_account_id, reference_number, description, project_id, status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
    ");
    $stmt->execute([
        $transfer_number, $transfer_date, $from_id, $to_id, $amount, $charges,
        $charge_acc_id, ($reference !== '' ? $reference : null), ($description !== '' ? $description : null),
        $project_id, $_SESSION['user_id'],
    ]);
    $id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Created bank transfer $transfer_number ("
        . $cash[$from_id]['account_name'] . " → " . $cash[$to_id]['account_name'] . ", amount " . number_format($amount, 2) . ")");

    echo json_encode(['success' => true, 'message' => "Bank transfer $transfer_number created.", 'id' => $id, 'transfer_number' => $transfer_number]);

} catch (Exception $e) {
    error_log('add_bank_transfer error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
