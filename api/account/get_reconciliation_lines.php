<?php
/**
 * api/account/get_reconciliation_lines.php
 *
 * The matching worksheet's data source. For a given bank reconciliation, returns
 * every bank-statement register line (bank_transactions) that falls in the
 * reconciliation's account + period (or is already linked to it), each with its
 * current match state, plus the live reconciliation maths:
 *
 *   cleared_movement   = SUM of MATCHED lines  (deposit +, withdrawal -)
 *   uncleared_movement = SUM of UNMATCHED lines (deposit +, withdrawal -)
 *   reconciled_book    = book_balance - uncleared_movement
 *   difference         = statement_balance - reconciled_book
 *
 * When every line that has truly cleared the bank is ticked, the uncleared pool
 * holds only items the bank has not yet processed, and the difference is zero.
 *
 * Read-only and additive — does not change any existing reconciliation behaviour.
 */
error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('bank_reconciliation')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$rec_id = (int)($_GET['reconciliation_id'] ?? 0);
if ($rec_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid reconciliation ID']);
    exit;
}

try {
    $rstmt = $pdo->prepare("SELECT reconciliation_id, bank_account_id, period_start, period_end,
                                   statement_balance, book_balance, status
                              FROM bank_reconciliations WHERE reconciliation_id = ?");
    $rstmt->execute([$rec_id]);
    $rec = $rstmt->fetch(PDO::FETCH_ASSOC);
    if (!$rec) { echo json_encode(['success' => false, 'message' => 'Reconciliation not found']); exit; }

    $bank = (int)$rec['bank_account_id'];

    // Lines for this worksheet:
    //   • Linked to this reconciliation (matched/manual regardless of date), OR
    //   • Fall within the period AND are NOT already cleared in a DIFFERENT reconciliation
    $stmt = $pdo->prepare("
        SELECT transaction_id, transaction_date, value_date, description, reference_number,
               transaction_type, amount, balance_after,
               COALESCE(matching_status, 'unmatched') AS matching_status,
               reconciliation_id
          FROM bank_transactions
         WHERE bank_account_id = ?
           AND (
               reconciliation_id = ?
               OR (
                   transaction_date BETWEEN ? AND ?
                   AND (
                       reconciliation_id IS NULL
                       OR reconciliation_id = ?
                       OR COALESCE(matching_status,'unmatched') NOT IN ('matched','manual','reconciled')
                   )
               )
           )
         ORDER BY transaction_date ASC, transaction_id ASC
    ");
    $stmt->execute([$bank, $rec_id, $rec['period_start'], $rec['period_end'], $rec_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // A line is "cleared" for this worksheet when it is matched/manual AND tied to
    // this reconciliation (a finalized line keeps its link); 'ignored' is excluded
    // from the maths entirely; everything else counts as uncleared.
    $cleared = 0.0; $uncleared = 0.0; $clearedCount = 0; $unclearedCount = 0;
    $lines = [];
    foreach ($rows as $r) {
        $signed = ($r['transaction_type'] === 'deposit') ? (float)$r['amount'] : -(float)$r['amount'];
        $ms = $r['matching_status'];
        $isMatched = in_array($ms, ['matched', 'manual'], true) && (int)$r['reconciliation_id'] === $rec_id;
        $isIgnored = ($ms === 'ignored');

        if ($isIgnored) {
            // excluded from totals
        } elseif ($isMatched) {
            $cleared += $signed; $clearedCount++;
        } else {
            $uncleared += $signed; $unclearedCount++;
        }

        $lines[] = [
            'transaction_id'   => (int)$r['transaction_id'],
            'transaction_date' => $r['transaction_date'],
            'description'      => $r['description'],
            'reference_number' => $r['reference_number'],
            'transaction_type' => $r['transaction_type'],
            'amount'           => (float)$r['amount'],
            'signed_amount'    => $signed,
            'matched'          => $isMatched,
            'ignored'          => $isIgnored,
        ];
    }

    $statement = (float)$rec['statement_balance'];
    $book      = (float)$rec['book_balance'];
    $reconciled_book = round($book - $uncleared, 2);
    $difference      = round($statement - $reconciled_book, 2);

    echo json_encode([
        'success' => true,
        'reconciliation' => [
            'id'                => $rec_id,
            'status'            => $rec['status'],
            'statement_balance' => $statement,
            'book_balance'      => $book,
            'period_start'      => $rec['period_start'],
            'period_end'        => $rec['period_end'],
        ],
        'lines'   => $lines,
        'summary' => [
            'cleared_movement'   => round($cleared, 2),
            'uncleared_movement' => round($uncleared, 2),
            'cleared_count'      => $clearedCount,
            'uncleared_count'    => $unclearedCount,
            'reconciled_book'    => $reconciled_book,
            'difference'         => $difference,
            'balanced'           => abs($difference) < 0.01,
        ],
    ]);

} catch (Throwable $e) {
    error_log('get_reconciliation_lines error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
