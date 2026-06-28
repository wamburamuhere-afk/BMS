<?php
/**
 * api/account/add_bank_transfer.php
 *
 * Create a bank/cash transfer and POST it immediately. An internal transfer
 * between our own cash/bank accounts is a low-risk move (the money never leaves
 * the business), so it no longer goes through a pending → reviewed → approved
 * workflow. On create the system posts the balanced double entry, moves BOTH
 * cash balances, writes the two bank-register rows, and marks the transfer
 * 'posted' (posted_by = the creator) — all in one transaction. A mistake is
 * undone with the single "Reverse" action (update_bank_transfer_status.php).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';   // cashBankAccounts(), applyAccountBalanceDelta()
require_once __DIR__ . '/../../core/gl_accounts.php';      // bankChargesAccountId()
require_once __DIR__ . '/../../core/account_balance.php';  // accountLedgerBalance()
require_once __DIR__ . '/../../core/bank_register.php';    // recordBankTransaction()
require_once __DIR__ . '/../../api/helpers/transaction_helper.php'; // recordGlobalTransaction()
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

    // MONEY-SAFETY (Step 10, I3 "warn but allow"): note a short balance, never block.
    // The money does not move until the transfer is POSTED, so this is informational
    // at create; the post step re-checks and also warns rather than blocking.
    $bal = accountLedgerBalance($pdo, $from_id);
    $total = round($amount + $charges, 2);
    $funds_warn = ($bal < $total)
        ? 'Note: the source account\'s available balance (' . number_format($bal, 2) . ') is less than the transfer total (' . number_format($total, 2) . '). The transfer was still created.'
        : null;

    // Transfer number: TRF-YYYY-NNNN (per §18 / §UI-6).
    $year = date('Y', strtotime($transfer_date));
    require_once __DIR__ . '/../../core/code_generator.php';
    $transfer_number = nextCode($pdo, 'TRF');   // company-prefixed sequential (BFS-TRF-0001)

    $uid  = (int)$_SESSION['user_id'];
    $desc = 'Transfer ' . $transfer_number . ($description !== '' ? ': ' . substr($description, 0, 100) : '');

    // Create + POST in ONE transaction. If the ledger post fails, the whole
    // create rolls back — we never leave a transfer recorded but not posted.
    $pdo->beginTransaction();

    // 1) Insert the transfer already as POSTED (posted_by = the creator).
    $stmt = $pdo->prepare("
        INSERT INTO bank_transfers
            (transfer_number, transfer_date, from_account_id, to_account_id, amount, charges,
             charge_account_id, reference_number, description, project_id, status,
             created_by, posted_by, posted_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?, NOW(), NOW(), NOW())
    ");
    $stmt->execute([
        $transfer_number, $transfer_date, $from_id, $to_id, $amount, $charges,
        $charge_acc_id, ($reference !== '' ? $reference : null), ($description !== '' ? $description : null),
        $project_id, $uid, $uid,
    ]);
    $id = (int)$pdo->lastInsertId();

    // 2) Balanced double entry: Dr destination (+ Dr charges) / Cr source (gross).
    $items = [['account_id' => $to_id, 'type' => 'debit', 'amount' => $amount, 'description' => $desc]];
    if ($charges > 0 && $charge_acc_id) {
        $items[] = ['account_id' => (int)$charge_acc_id, 'type' => 'debit', 'amount' => $charges, 'description' => 'Transfer charges'];
    }
    $items[] = ['account_id' => $from_id, 'type' => 'credit', 'amount' => $total, 'description' => $desc];

    $res = recordGlobalTransaction([
        'transaction_date' => $transfer_date,
        'amount'           => $total,
        'transaction_type' => 'transfer',
        'reference_number' => $transfer_number,
        'description'      => $desc,
        'project_id'       => $project_id,
        'journal_items'    => $items,
    ], $pdo);
    if (empty($res['success'])) {
        throw new Exception('Could not post the transfer to the ledger — nothing was saved.');
    }
    $txnId = (int)$res['transaction_id'];

    // 3) Move the cash balances: source down by gross, destination up by net, charge account up by charges.
    applyAccountBalanceDelta($pdo, $from_id, 'credit', $total);
    applyAccountBalanceDelta($pdo, $to_id,   'debit',  $amount);
    if ($charges > 0 && $charge_acc_id) {
        applyAccountBalanceDelta($pdo, $charge_acc_id, 'debit', $charges);
    }

    // 4) Bank-statement register: one withdrawal (source), one deposit (destination).
    recordBankTransaction($pdo, $from_id, $total,  'withdrawal', $transfer_date, $transfer_number, $desc, $uid);
    recordBankTransaction($pdo, $to_id,   $amount, 'deposit',    $transfer_date, $transfer_number, $desc, $uid);

    // 5) Link the ledger transaction back to the transfer.
    $pdo->prepare("UPDATE bank_transfers SET transaction_id = ? WHERE id = ?")->execute([$txnId, $id]);

    $pdo->commit();

    logActivity($pdo, $uid, "Created + posted bank transfer $transfer_number ("
        . $cash[$from_id]['account_name'] . " → " . $cash[$to_id]['account_name'] . ", amount " . number_format($amount, 2) . ")");

    $msg = "Bank transfer $transfer_number created and posted — the money has moved.";
    if ($funds_warn) $msg .= ' ' . $funds_warn;
    echo json_encode(['success' => true, 'message' => $msg, 'id' => $id, 'transfer_number' => $transfer_number, 'funds_warning' => $funds_warn]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('add_bank_transfer error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
