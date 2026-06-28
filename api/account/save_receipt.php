<?php
/**
 * api/account/save_receipt.php
 *
 * Record ONE customer receipt and apply it across MANY outstanding invoices
 * (payment allocation). Additive companion to record_payment.php (single invoice),
 * which is left untouched.
 *
 * For each allocation it writes a payment_allocations row and reduces that
 * invoice's balance; the parent payments row carries the first invoice in
 * `invoice_id` for backward-compatibility with existing reports. The receipt also
 * writes a DEPOSIT line to the Bank Statement (recordBankTransaction) into the
 * chosen received-into account, and fires the same gated ledger hook
 * (autoPostEvent 'payment_received') the single-invoice flow uses — so no new
 * cash-accounting path is introduced.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/auto_post_hook.php';
require_once __DIR__ . '/../../core/payment_source.php';   // cashBankAccounts()
require_once __DIR__ . '/../../core/money_guard.php';      // requireCashBankAccount (money-safety)
require_once __DIR__ . '/../../core/bank_register.php';    // recordBankTransaction
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot record payments']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $customer_id   = (int)($_POST['customer_id'] ?? 0);
    $amount        = round((float)($_POST['amount'] ?? 0), 2);
    $payment_date  = $_POST['payment_date'] ?? date('Y-m-d');
    $method        = $_POST['payment_method'] ?? 'cash';
    $reference     = trim($_POST['reference_number'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $bank_acc      = (int)($_POST['received_into_account_id'] ?? 0) ?: null;
    $allocations   = $_POST['allocations'] ?? [];   // [ {invoice_id, amount}, ... ]

    if ($customer_id <= 0) throw new Exception('Select a customer.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) throw new Exception('A valid payment date is required.');
    if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
    if (!is_array($allocations) || count($allocations) === 0) throw new Exception('Allocate the receipt to at least one invoice.');

    // MONEY-SAFETY (Step 3): the received-into account is MANDATORY — a receipt must
    // land in a real cash/bank account or it never reaches the books. Throws the
    // specific reason (no account selected / not a cash-bank account); never silent.
    $bank_acc = requireCashBankAccount($pdo, $bank_acc, 'Received-Into');

    // Normalise + validate allocations against each invoice's live balance.
    $clean = [];
    $allocTotal = 0.0;
    foreach ($allocations as $a) {
        $iid = (int)($a['invoice_id'] ?? 0);
        $amt = round((float)($a['amount'] ?? 0), 2);
        if ($iid <= 0 || $amt <= 0) continue;
        $clean[$iid] = ($clean[$iid] ?? 0) + $amt;
    }
    if (!$clean) throw new Exception('No valid allocations supplied.');

    $pdo->beginTransaction();

    // Lock + verify each target invoice (belongs to the customer, in scope, has the balance).
    $primary_invoice = 0; $project_id = null; $currency = null;
    foreach ($clean as $iid => $amt) {
        assertScopeForRecord('invoices', 'invoice_id', $iid);
        $inv = $pdo->prepare("SELECT invoice_id, customer_id, grand_total, paid_amount, balance_due, status, project_id, currency
                                FROM invoices WHERE invoice_id = ? FOR UPDATE");
        $inv->execute([$iid]);
        $row = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Invoice #$iid not found.");
        if ((int)$row['customer_id'] !== $customer_id) throw new Exception("Invoice #$iid does not belong to this customer.");
        if ($amt > (float)$row['balance_due'] + 0.01) {
            throw new Exception("Allocation to invoice {$row['invoice_id']} ($amt) exceeds its balance (" . number_format((float)$row['balance_due'], 2) . ").");
        }
        $allocTotal += $amt;
        if (!$primary_invoice) { $primary_invoice = $iid; $project_id = $row['project_id'] !== null ? (int)$row['project_id'] : null; $currency = $row['currency']; }
    }

    if (abs($allocTotal - $amount) > 0.01) {
        throw new Exception('The allocated total (' . number_format($allocTotal, 2) . ') must equal the receipt amount (' . number_format($amount, 2) . ').');
    }

    // Receipt number — company-prefixed sequential (BFS-RCP-0001).
    require_once __DIR__ . '/../../core/code_generator.php';
    $payment_number = nextCode($pdo, 'RCP');

    // Parent payment row (invoice_id = primary, for back-compat).
    $pdo->prepare("
        INSERT INTO payments (payment_number, invoice_id, customer_id, payment_date, amount, currency,
                              payment_method, received_into_account_id, reference_number, notes, status, received_by, created_by, project_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?)
    ")->execute([
        $payment_number, $primary_invoice, $customer_id, $payment_date, $amount, $currency,
        $method, $bank_acc, ($reference !== '' ? $reference : null), ($notes !== '' ? $notes : null),
        $_SESSION['user_id'], $_SESSION['user_id'], $project_id,
    ]);
    $payment_id = (int)$pdo->lastInsertId();

    // Allocation rows + invoice balance updates.
    $allocIns = $pdo->prepare("INSERT INTO payment_allocations (payment_id, payment_kind, target_type, target_id, allocated_amount)
                               VALUES (?, 'customer', 'invoice', ?, ?)");
    foreach ($clean as $iid => $amt) {
        $allocIns->execute([$payment_id, $iid, $amt]);
        // Recompute the invoice from its true paid total (all completed payments + allocations).
        $newPaid = round((float)$pdo->query("SELECT COALESCE(SUM(allocated_amount),0)
                                               FROM payment_allocations pa
                                               JOIN payments p ON pa.payment_id = p.payment_id
                                              WHERE pa.target_type='invoice' AND pa.target_id=$iid AND p.status='completed'")->fetchColumn(), 2);
        // Include any legacy single-invoice payments not represented as allocations.
        $legacy = round((float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments
                                             WHERE invoice_id=$iid AND status='completed'
                                               AND payment_id NOT IN (SELECT payment_id FROM payment_allocations)")->fetchColumn(), 2);
        $totalPaid = round($newPaid + $legacy, 2);
        $grand = (float)$pdo->query("SELECT grand_total FROM invoices WHERE invoice_id=$iid")->fetchColumn();
        $newStatus = ($totalPaid >= $grand - 0.01) ? 'paid' : 'partial';
        $pdo->prepare("UPDATE invoices SET paid_amount=?, balance_due=GREATEST(grand_total-?,0), status=?, payment_date=?, updated_at=NOW() WHERE invoice_id=?")
            ->execute([$totalPaid, $totalPaid, $newStatus, $payment_date, $iid]);
    }

    // Bank Statement deposit — the received-into account is mandatory (validated above).
    recordBankTransaction($pdo, $bank_acc, $amount, 'deposit', $payment_date, $payment_number,
        "Receipt $payment_number from customer #$customer_id", (int)$_SESSION['user_id']);

    // IN-2 (money.md): post ONE balanced entry into the canonical ledger —
    //   Dr Received-Into / Cr Accounts Receivable (gross). AR via gl_accounts; idempotent.
    // MONEY-SAFETY (Step 3): FAIL LOUDLY — if the receipt cannot be posted (and it is
    // not an idempotent re-post), roll the WHOLE receipt back with the real reason,
    // rather than saving money that never reached the books.
    require_once __DIR__ . '/../../core/money_in_posting.php';
    $pr = postPaymentReceived($pdo, (int)$payment_id, (int)$bank_acc, (float)$amount,
        $payment_date, $payment_number, "Receipt $payment_number — " . count($clean) . " invoice(s)",
        $project_id !== null ? (int)$project_id : null, (int)$_SESSION['user_id']);
    if (empty($pr['posted']) && ($pr['reason'] ?? '') !== 'already_posted') {
        throw new Exception(depositPostReasonMessage($pr['reason'] ?? 'unknown'));
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Recorded receipt $payment_number (" . number_format($amount, 2) . ") across " . count($clean) . " invoice(s)");

    echo json_encode(['success' => true, 'message' => "Receipt $payment_number recorded.", 'payment_id' => $payment_id, 'payment_number' => $payment_number]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('save_receipt error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
