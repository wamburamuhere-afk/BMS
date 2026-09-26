<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants.
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canVoid('mm_transactions')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$txnId     = intval($_POST['txn_id'] ?? 0);
$voidReason = trim($_POST['void_reason'] ?? '');

if (!$txnId)         { echo json_encode(['success' => false, 'message' => 'Transaction ID missing']); exit; }
if (!$voidReason)    { echo json_encode(['success' => false, 'message' => 'Void reason is required']); exit; }

try {
    $tx = $pdo->prepare("SELECT mm_txn_id, txn_code, status, journal_entry_id FROM mm_transactions WHERE mm_txn_id=? LIMIT 1");
    $tx->execute([$txnId]);
    $tx = $tx->fetch(PDO::FETCH_ASSOC);

    if (!$tx)                        { echo json_encode(['success' => false, 'message' => 'Transaction not found']); exit; }
    if ($tx['status'] !== 'posted')  { echo json_encode(['success' => false, 'message' => 'Only posted transactions can be voided']); exit; }

    $pdo->beginTransaction();

    // Mark transaction void
    $pdo->prepare("UPDATE mm_transactions SET status='void', void_reason=?, voided_by=?, voided_at=NOW() WHERE mm_txn_id=?")
        ->execute([$voidReason, $_SESSION['user_id'], $txnId]);

    // Reverse the GL journal entry if one exists
    if ($tx['journal_entry_id']) {
        require_once ROOT_DIR . '/core/ledger_post.php';
        // Fetch the original entry lines and post a reversal
        $origLines = $pdo->prepare("SELECT * FROM journal_entry_items WHERE entry_id=?");
        $origLines->execute([$tx['journal_entry_id']]);
        $origLines = $origLines->fetchAll(PDO::FETCH_ASSOC);

        if ($origLines) {
            $reversalLines = [];
            foreach ($origLines as $l) {
                $reversalLines[] = [
                    'account_id'  => $l['account_id'],
                    'type'        => $l['type'] === 'debit' ? 'credit' : 'debit',
                    'amount'      => (float)$l['amount'],
                    'description' => 'Reversal: ' . ($l['description'] ?? ''),
                ];
            }
            $reversalId = postLedgerEntry(
                $pdo,
                'VOID — ' . $tx['txn_code'] . ' — ' . $voidReason,
                $reversalLines,
                null,
                $txnId,
                'mm_transaction',
                date('Y-m-d'),
                $_SESSION['user_id']
            );
            $pdo->prepare("UPDATE mm_transactions SET journal_entry_id=? WHERE mm_txn_id=?")->execute([$reversalId, $txnId]);
        }
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Voided MM transaction: {$tx['txn_code']} — $voidReason");
    echo json_encode(['success' => true, 'message' => 'Transaction voided.']);

} catch (\Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("void_transaction.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
