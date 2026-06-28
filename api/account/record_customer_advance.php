<?php
/**
 * api/account/record_customer_advance.php  (money.md IN-7)
 *
 * Record a CUSTOMER ADVANCE / DEPOSIT — money received before an invoice exists.
 * The cash is held as a liability (Client Deposits, 2-1600) until applied to an
 * invoice via apply_customer_advance.php. Models WorkDo's "Retainer" receipt on
 * BMS's receipt + payment_allocations infrastructure.
 *
 *   Dr Received-Into bank/cash  /  Cr Client Deposits (2-1600)
 *
 * Writes: a `payments` row (invoice_id NULL), an 'advance' payment_allocations row
 * (target_id = customer), a Bank Statement deposit, and ONE balanced GL entry.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/payment_source.php';     // cashBankAccounts
require_once __DIR__ . '/../../core/bank_register.php';      // recordBankTransaction
require_once __DIR__ . '/../../core/customer_advance.php';   // postCustomerAdvanceReceipt
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot record receipts']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $customer_id  = (int)($_POST['customer_id'] ?? 0);
    $amount       = round((float)($_POST['amount'] ?? 0), 2);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $method       = $_POST['payment_method'] ?? 'cash';
    $reference    = trim($_POST['reference_number'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');
    $bank_acc     = (int)($_POST['received_into_account_id'] ?? 0);
    $project_id   = (isset($_POST['project_id']) && $_POST['project_id'] !== '') ? (int)$_POST['project_id'] : null;

    if ($customer_id <= 0) throw new Exception('Select a customer.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) throw new Exception('A valid payment date is required.');
    if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
    if ($bank_acc <= 0) throw new Exception('Choose the bank/cash account the advance was received into.');

    // Validate the customer exists.
    $cust = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ?");
    $cust->execute([$customer_id]);
    if (!$cust->fetchColumn()) throw new Exception('Customer not found.');

    // Validate the received-into account is a real cash/bank account.
    $cash = [];
    foreach (cashBankAccounts($pdo) as $a) $cash[(int)$a['account_id']] = true;
    if (!isset($cash[$bank_acc])) throw new Exception('The received-into account is not a valid cash/bank account.');

    if ($project_id !== null && !userCan('project', $project_id)) {
        throw new Exception('Access denied: this project is not in your assigned scope.');
    }

    $pdo->beginTransaction();

    // Advance number — company-prefixed sequential (BFS-ADV-0001), recognisable on statements.
    require_once __DIR__ . '/../../core/code_generator.php';
    $payment_number = nextCode($pdo, 'ADV');

    // Payment row — no invoice (this is an on-account deposit).
    $pdo->prepare("
        INSERT INTO payments (payment_number, invoice_id, customer_id, payment_date, amount, currency,
                              payment_method, received_into_account_id, reference_number, notes, status, received_by, created_by, project_id)
        VALUES (?, NULL, ?, ?, ?, 'TZS', ?, ?, ?, ?, 'completed', ?, ?, ?)
    ")->execute([
        $payment_number, $customer_id, $payment_date, $amount, $method, $bank_acc,
        ($reference !== '' ? $reference : null),
        ($notes !== '' ? $notes : null),
        $_SESSION['user_id'], $_SESSION['user_id'], $project_id,
    ]);
    $payment_id = (int)$pdo->lastInsertId();

    // Mark it as an advance (the deposit sub-ledger row, target = the customer).
    $pdo->prepare("INSERT INTO payment_allocations (payment_id, payment_kind, target_type, target_id, allocated_amount)
                   VALUES (?, 'customer', 'advance', ?, ?)")
        ->execute([$payment_id, $customer_id, $amount]);

    // Bank Statement deposit.
    recordBankTransaction($pdo, $bank_acc, $amount, 'deposit', $payment_date, $payment_number,
        "Advance $payment_number from customer #$customer_id", (int)$_SESSION['user_id']);

    // GL: Dr Bank / Cr Client Deposits (2-1600).
    $post = postCustomerAdvanceReceipt($pdo, $payment_id, $bank_acc, $amount, $payment_date,
        $payment_number, "Advance $payment_number — customer #$customer_id", $project_id, (int)$_SESSION['user_id']);
    if (empty($post['posted'])) {
        throw new Exception('Could not post the advance to the ledger (' . ($post['reason'] ?? 'unknown') . '). Check the Client Deposits account (2-1600) exists.');
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Recorded customer advance $payment_number (" . number_format($amount, 2) . ") for customer #$customer_id");

    echo json_encode([
        'success' => true,
        'message' => "Advance $payment_number recorded.",
        'payment_id' => $payment_id,
        'payment_number' => $payment_number,
        'available_balance' => customerAdvanceAvailable($pdo, $customer_id),
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('record_customer_advance error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
