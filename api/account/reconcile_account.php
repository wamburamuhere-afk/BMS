<?php
/**
 * api/account/reconcile_account.php
 * ---------------------------------
 * Re-syncs ONE account's stored current_balance to its ledger-true balance
 * (opening + posted movements). Used by the "Reconcile" button on the account
 * ledger page when the stored figure has drifted from the posted ledger.
 * POST { account_id }. Admin/edit-gated.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/account_balance.php';
header('Content-Type: application/json');

try {
    if (!isAuthenticated())                 throw new Exception('Unauthorized');
    if (!canEdit('chart_of_accounts'))      throw new Exception('Permission denied');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid request method');
    csrf_check();

    $account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
    if ($account_id <= 0) throw new Exception('Invalid account id');

    $sel = $pdo->prepare("SELECT account_code, account_name, current_balance FROM accounts WHERE account_id = ?");
    $sel->execute([$account_id]);
    $acc = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$acc) throw new Exception('Account not found');

    $stored = (float)$acc['current_balance'];
    $ledger = accountLedgerBalance($pdo, $account_id);

    if (abs($stored - $ledger) < 0.01) {
        echo json_encode([
            'success'  => true,
            'changed'  => false,
            'message'  => 'Already reconciled — the stored balance matches the ledger.',
            'balance'  => round($ledger, 2),
        ]);
        exit;
    }

    $pdo->prepare("UPDATE accounts SET current_balance = ?, updated_at = NOW() WHERE account_id = ?")
        ->execute([$ledger, $account_id]);

    logActivity($pdo, $_SESSION['user_id'] ?? 0,
        "Reconciled account {$acc['account_code']} {$acc['account_name']}: " .
        number_format($stored, 2) . " → " . number_format($ledger, 2));

    echo json_encode([
        'success'  => true,
        'changed'  => true,
        'message'  => 'Reconciled. Balance updated to match the posted ledger.',
        'was'      => round($stored, 2),
        'balance'  => round($ledger, 2),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
