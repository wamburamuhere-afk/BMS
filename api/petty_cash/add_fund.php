<?php
/**
 * api/petty_cash/add_fund.php
 * ---------------------------
 * Registers a cash/bank account as a petty cash fund (the imprest registry).
 * The account must be an active, cash-nature asset. POST.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';
header('Content-Type: application/json');

try {
    if (!isAuthenticated())                         throw new Exception('Unauthorized');
    if (!canCreate('petty_cash') && !canEdit('petty_cash')) throw new Exception('Permission denied');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')      throw new Exception('Invalid request method');

    $account_id = (isset($_POST['account_id']) && $_POST['account_id'] !== '') ? (int)$_POST['account_id'] : 0;
    $label      = trim($_POST['label'] ?? '');
    if ($account_id <= 0) throw new Exception('Select a cash account to register as a fund.');

    // The account must be an active, cash-nature asset (same marker the bank/cash
    // surfaces use) — so a fund is always a real cash float.
    $chk = $pdo->prepare("
        SELECT a.account_name
          FROM accounts a
          LEFT JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
         WHERE a.account_id = ? AND a.status = 'active' AND a.account_type = 'asset'
           AND (st.is_bank = 1 OR a.cash_flow_category = 'cash')
    ");
    $chk->execute([$account_id]);
    $name = $chk->fetchColumn();
    if ($name === false) throw new Exception('That account is not an active cash/bank account, so it cannot be a petty cash fund.');

    $ins = $pdo->prepare("INSERT INTO petty_cash_funds (account_id, label, status)
                          VALUES (?, ?, 'active')
                          ON DUPLICATE KEY UPDATE label = VALUES(label), status = 'active'");
    $ins->execute([$account_id, $label !== '' ? $label : $name]);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Registered petty cash fund: " . ($label !== '' ? $label : $name) . " (account #$account_id)");

    echo json_encode(['success' => true, 'message' => 'Fund registered.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
