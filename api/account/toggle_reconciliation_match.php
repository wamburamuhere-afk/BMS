<?php
/**
 * api/account/toggle_reconciliation_match.php
 *
 * Drives the reconciliation matching worksheet. Actions:
 *   match    — tick a register line as cleared on the bank statement
 *   unmatch  — untick it
 *   ignore   — exclude a line from the reconciliation maths
 *   unignore — bring an ignored line back
 *   finalize — when the difference is zero, lock the reconciliation: set it
 *              'reconciled', stamp the matched lines 'reconciled', and persist
 *              the cleared figures back to bank_reconciliations.
 *
 * Additive: it only sets the (already-existing, previously-unused)
 * matching_status / reconciliation_id / status columns on bank_transactions, and
 * the reconciliation's own status — it never moves money or touches the ledger.
 */
require_once __DIR__ . '/../../roots.php';
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

if (!canEdit('bank_reconciliation')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you cannot edit bank reconciliations']);
    exit;
}

$rec_id = (int)($_POST['reconciliation_id'] ?? 0);
$action = $_POST['action'] ?? '';
$txn_id = (int)($_POST['transaction_id'] ?? 0);

if ($rec_id <= 0 || $action === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}
if (!in_array($action, ['match', 'unmatch', 'ignore', 'unignore', 'finalize'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

/** Recompute the live reconciliation maths from the register, same model as the read API. */
function recon_compute(PDO $pdo, array $rec): array {
    $bank = (int)$rec['bank_account_id'];
    $stmt = $pdo->prepare("
        SELECT transaction_type, amount, COALESCE(matching_status,'unmatched') AS matching_status, reconciliation_id
          FROM bank_transactions
         WHERE bank_account_id = ?
           AND ( (transaction_date BETWEEN ? AND ?) OR reconciliation_id = ? )
    ");
    $stmt->execute([$bank, $rec['period_start'], $rec['period_end'], (int)$rec['reconciliation_id']]);
    $cleared = 0.0; $uncleared = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $signed = ($r['transaction_type'] === 'deposit') ? (float)$r['amount'] : -(float)$r['amount'];
        $ms = $r['matching_status'];
        if ($ms === 'ignored') continue;
        if (in_array($ms, ['matched', 'manual'], true) && (int)$r['reconciliation_id'] === (int)$rec['reconciliation_id']) {
            $cleared += $signed;
        } else {
            $uncleared += $signed;
        }
    }
    $reconciled_book = round((float)$rec['book_balance'] - $uncleared, 2);
    $difference      = round((float)$rec['statement_balance'] - $reconciled_book, 2);
    return [
        'cleared_movement'   => round($cleared, 2),
        'uncleared_movement' => round($uncleared, 2),
        'reconciled_book'    => $reconciled_book,
        'difference'         => $difference,
        'balanced'           => abs($difference) < 0.01,
    ];
}

try {
    $pdo->beginTransaction();

    $rstmt = $pdo->prepare("SELECT reconciliation_id, bank_account_id, period_start, period_end,
                                   statement_balance, book_balance, status
                              FROM bank_reconciliations WHERE reconciliation_id = ? FOR UPDATE");
    $rstmt->execute([$rec_id]);
    $rec = $rstmt->fetch(PDO::FETCH_ASSOC);
    if (!$rec) throw new Exception('Reconciliation not found');

    // A finalized/cancelled reconciliation is locked from further matching.
    if (in_array($rec['status'], ['reconciled', 'cancelled'], true) && $action !== 'finalize') {
        throw new Exception('This reconciliation is ' . $rec['status'] . ' and can no longer be edited.');
    }

    if (in_array($action, ['match', 'unmatch', 'ignore', 'unignore'], true)) {
        if ($txn_id <= 0) throw new Exception('Missing transaction line');

        // The line must belong to THIS reconciliation's bank account (scope guard).
        $lstmt = $pdo->prepare("SELECT transaction_id FROM bank_transactions
                                 WHERE transaction_id = ? AND bank_account_id = ?");
        $lstmt->execute([$txn_id, (int)$rec['bank_account_id']]);
        if (!$lstmt->fetchColumn()) throw new Exception('That line does not belong to this reconciliation.');

        if ($action === 'match') {
            $pdo->prepare("UPDATE bank_transactions SET matching_status = 'matched', reconciliation_id = ?, status = 'cleared', updated_at = NOW()
                            WHERE transaction_id = ?")->execute([$rec_id, $txn_id]);
        } elseif ($action === 'unmatch') {
            $pdo->prepare("UPDATE bank_transactions SET matching_status = 'unmatched', reconciliation_id = NULL, status = 'pending', updated_at = NOW()
                            WHERE transaction_id = ?")->execute([$txn_id]);
        } elseif ($action === 'ignore') {
            $pdo->prepare("UPDATE bank_transactions SET matching_status = 'ignored', updated_at = NOW()
                            WHERE transaction_id = ?")->execute([$txn_id]);
        } elseif ($action === 'unignore') {
            $pdo->prepare("UPDATE bank_transactions SET matching_status = 'unmatched', updated_at = NOW()
                            WHERE transaction_id = ?")->execute([$txn_id]);
        }
    }

    $summary = recon_compute($pdo, $rec);

    if ($action === 'finalize') {
        if (!canApprove('bank_reconciliation')) {
            throw new Exception('You do not have permission to finalize a reconciliation.');
        }
        if (!$summary['balanced']) {
            throw new Exception('Cannot finalize: the difference is not yet zero (currently '
                . number_format($summary['difference'], 2) . ').');
        }
        // Lock: reconciliation reconciled, its matched lines stamped reconciled,
        // and the cleared figures persisted onto the header.
        $pdo->prepare("UPDATE bank_reconciliations
                          SET status = 'reconciled', adjusted_balance = ?, difference = 0, updated_at = NOW()
                        WHERE reconciliation_id = ?")
            ->execute([$summary['reconciled_book'], $rec_id]);
        $pdo->prepare("UPDATE bank_transactions SET status = 'reconciled', updated_at = NOW()
                        WHERE reconciliation_id = ? AND matching_status IN ('matched','manual')")
            ->execute([$rec_id]);
        logActivity($pdo, $_SESSION['user_id'], "Finalized Bank Reconciliation", "Reconciliation ID: $rec_id (balanced)");
        $rec['status'] = 'reconciled';
    } else {
        logActivity($pdo, $_SESSION['user_id'], "Reconciliation match $action", "Rec ID: $rec_id, line: $txn_id");
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Updated.', 'summary' => $summary, 'status' => $rec['status']]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('toggle_reconciliation_match error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
