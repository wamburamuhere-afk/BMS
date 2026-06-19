<?php
/**
 * api/account/update_bank_transfer_status.php
 *
 * Drives the bank-transfer workflow: pending → reviewed → approved → posted, plus
 * (any pre-post) → rejected (cancel) and posted → rejected (VOID).
 *
 * The money moves ONLY at the Posted step: a balanced entry
 *     Dr destination (amount) [+ Dr charge account (charges)]  /  Cr source (amount+charges)
 * is written to the consolidated ledger, both cash balances are moved, and TWO
 * bank-register rows are appended (source withdrawal, destination deposit). Posting
 * is idempotent (only if not already posted). posted → rejected reverses all of it.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/payment_source.php';   // applyAccountBalanceDelta
require_once __DIR__ . '/../../core/bank_register.php';    // recordBankTransaction / reverse
require_once __DIR__ . '/../../api/helpers/transaction_helper.php'; // recordGlobalTransaction
require_once __DIR__ . '/../../core/account_balance.php';  // accountLedgerBalance()
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id <= 0 || $status === '') throw new Exception('Missing required parameters');

    $allowed = ['reviewed', 'approved', 'posted', 'rejected'];
    if (!in_array($status, $allowed, true)) throw new Exception('Invalid status');

    // Per-transition permission gate (mirrors the expense workflow gating).
    $gateOk = ($status === 'reviewed') ? canReview('bank_transfers')
            : (($status === 'approved') ? canApprove('bank_transfers')
            : canEdit('bank_transfers'));   // posted / rejected
    if (!$gateOk) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to ' . $status . ' a bank transfer']);
        exit;
    }

    $snap = $pdo->prepare("SELECT transfer_number, transfer_date, from_account_id, to_account_id, amount,
                                  charges, charge_account_id, reference_number, description, project_id,
                                  status AS old_status, transaction_id
                             FROM bank_transfers WHERE id = ?");
    $snap->execute([$id]);
    $t = $snap->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new Exception('Bank transfer not found');
    $old = $t['old_status'];

    // Valid transitions.
    $transitions = [
        'pending'  => ['reviewed', 'rejected'],
        'reviewed' => ['approved', 'rejected'],
        'approved' => ['posted', 'rejected'],
        'posted'   => ['rejected'],     // void
    ];
    if (!isset($transitions[$old]) || !in_array($status, $transitions[$old], true)) {
        throw new Exception("Cannot move a $old transfer to $status");
    }

    $amount  = (float)$t['amount'];
    $charges = (float)$t['charges'];
    $total   = round($amount + $charges, 2);
    $from    = (int)$t['from_account_id'];
    $to      = (int)$t['to_account_id'];
    $chargeAcc = $t['charge_account_id'] !== null ? (int)$t['charge_account_id'] : 0;
    $ref     = $t['transfer_number'];
    $proj    = $t['project_id'] !== null ? (int)$t['project_id'] : null;
    $desc    = 'Transfer ' . $ref . ($t['description'] ? ': ' . substr((string)$t['description'], 0, 100) : '');

    $actor = workflowActorSnapshot();

    $pdo->beginTransaction();

    // Status column + actor stamps.
    $extra = '';
    if ($status === 'reviewed') $extra = ', reviewed_by = ' . (int)$_SESSION['user_id'] . ", reviewed_by_name = " . $pdo->quote($actor['name']) . ", reviewed_by_role = " . $pdo->quote($actor['role']) . ", reviewed_at = NOW()";
    elseif ($status === 'approved') $extra = ', approved_by = ' . (int)$_SESSION['user_id'] . ", approved_by_name = " . $pdo->quote($actor['name']) . ", approved_by_role = " . $pdo->quote($actor['role']) . ", approved_at = NOW()";
    elseif ($status === 'posted') $extra = ', posted_by = ' . (int)$_SESSION['user_id'] . ', posted_at = NOW()';

    $pdo->prepare("UPDATE bank_transfers SET status = ?, updated_by = ?, updated_at = NOW() $extra WHERE id = ?")
        ->execute([$status, $_SESSION['user_id'], $id]);

    // E-signature on the workflow steps.
    $sigResult = ['has_signature' => true];
    $sigAction = ['reviewed' => 'reviewed', 'approved' => 'approved', 'posted' => 'approved'][$status] ?? null;
    if ($sigAction !== null) {
        $sigResult = workflowCaptureSignature($pdo, 'bank_transfer', $id, $sigAction,
            $_SESSION['user_id'], $actor['name'], $actor['role']);
    }

    $posted = false;
    $funds_warn = null;
    if ($status === 'posted') {
        if (!empty($t['transaction_id'])) {
            // Already posted — never double-post.
        } else {
            if ($amount <= 0) throw new Exception('Transfer amount must be greater than zero.');
            // MONEY-SAFETY (Step 10, I3 "warn but allow"): a short balance warns but does
            // NOT block the post — the transfer still records (consistent with every other
            // money-out flow). The warning is surfaced in the response.
            $bal = accountLedgerBalance($pdo, $from);
            if ($bal < $total) {
                $funds_warn = 'Note: the source account\'s available balance (' . number_format($bal, 2) . ') is less than the transfer total (' . number_format($total, 2) . '). The transfer was still posted.';
            }

            // Balanced double entry: Dr destination (+ Dr charges) / Cr source (gross).
            $items = [
                ['account_id' => $to,   'type' => 'debit',  'amount' => $amount, 'description' => $desc],
            ];
            if ($charges > 0 && $chargeAcc > 0) {
                $items[] = ['account_id' => $chargeAcc, 'type' => 'debit', 'amount' => $charges, 'description' => 'Transfer charges'];
            }
            $items[] = ['account_id' => $from, 'type' => 'credit', 'amount' => $total, 'description' => $desc];

            $res = recordGlobalTransaction([
                'transaction_date' => $t['transfer_date'],
                'amount'           => $total,
                'transaction_type' => 'transfer',
                'reference_number' => $ref,
                'description'      => $desc,
                'project_id'       => $proj,
                'journal_items'    => $items,
            ], $pdo);
            if (empty($res['success'])) throw new Exception('Ledger posting failed for the transfer.');
            $txnId = (int)$res['transaction_id'];

            // Move the two cash balances (charge expense leg lives in the ledger only,
            // matching how postOutflow leaves the expense contra balance untouched).
            applyAccountBalanceDelta($pdo, $from, 'credit', $total);   // source down by gross
            applyAccountBalanceDelta($pdo, $to,   'debit',  $amount);  // destination up by net

            // Bank-statement register: one withdrawal (source), one deposit (destination).
            recordBankTransaction($pdo, $from, $total,  'withdrawal', $t['transfer_date'], $ref, $desc, (int)$_SESSION['user_id']);
            recordBankTransaction($pdo, $to,   $amount, 'deposit',    $t['transfer_date'], $ref, $desc, (int)$_SESSION['user_id']);

            $pdo->prepare("UPDATE bank_transfers SET transaction_id = ? WHERE id = ?")->execute([$txnId, $id]);
            $posted = true;
        }
    } elseif ($status === 'rejected' && $old === 'posted' && !empty($t['transaction_id'])) {
        // VOID a posted transfer: restore both balances, drop the ledger + both
        // register rows, and unlink the transaction so it could be re-posted.
        applyAccountBalanceDelta($pdo, $from, 'debit',  $total);    // restore source
        applyAccountBalanceDelta($pdo, $to,   'credit', $amount);   // restore destination
        $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id = ?")->execute([(int)$t['transaction_id']]);
        $pdo->prepare("DELETE FROM transactions WHERE transaction_id = ?")->execute([(int)$t['transaction_id']]);
        reverseBankTransaction($pdo, $from, $ref, 'withdrawal');
        reverseBankTransaction($pdo, $to,   $ref, 'deposit');
        $pdo->prepare("UPDATE bank_transfers SET transaction_id = NULL WHERE id = ?")->execute([$id]);
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Bank transfer $ref: $old → $status" . ($posted ? " (posted, txn #$posted)" : ''));

    $msg = "Transfer updated to $status.";
    if ($funds_warn) $msg .= ' ' . $funds_warn;
    $response = ['success' => true, 'message' => $msg, 'funds_warning' => $funds_warn];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('update_bank_transfer_status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
