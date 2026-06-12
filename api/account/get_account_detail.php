<?php
/**
 * api/account/get_account_detail.php
 * ----------------------------------
 * Read-only feed for the Chart of Accounts "View" slide-in panel (Phase 9).
 * Returns, for one account:
 *   - account   : core record + type/category/normal side + parent code/name
 *   - children  : its direct sub-accounts (for the Sub-Accounts tab)
 *   - transactions : last 50 POSTED journal lines (Transactions tab)
 *   - balances  : opening, stored current, and the balance CALCULATED from
 *                 posted journal lines, plus an in_sync flag (Balance Check tab)
 *
 * GET only; no writes; no CSRF needed.
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/account_balance.php';
global $pdo;
header('Content-Type: application/json');

try {
    // 1. Auth + permission
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized');
    }
    if (!canView('chart_of_accounts')) {
        throw new Exception('Permission denied');
    }

    // 2. Input
    $account_id = intval($_GET['account_id'] ?? 0);
    if ($account_id <= 0) {
        throw new Exception('Invalid account id');
    }

    // 3. Account core (type + category + normal side + parent code/name)
    $stmt = $pdo->prepare("
        SELECT
            a.account_id,
            a.account_code,
            a.account_name,
            a.account_type_id,
            at.type_name      AS account_type,
            at.display_name   AS type_display,
            at.category       AS category,
            at.normal_side    AS type_normal_side,
            a.category_id,
            c.category_name,
            a.description,
            a.opening_balance,
            a.current_balance,
            a.parent_account_id,
            pa.account_code   AS parent_code,
            pa.account_name   AS parent_name,
            a.level,
            a.is_system,
            a.normal_balance,
            a.status
        FROM accounts a
        LEFT JOIN account_types at      ON a.account_type_id   = at.type_id
        LEFT JOIN account_categories c  ON a.category_id       = c.category_id
        LEFT JOIN accounts pa           ON a.parent_account_id = pa.account_id
        WHERE a.account_id = ?
    ");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception('Account not found');
    }

    // 4. Direct children (sub-accounts). Exclude self defensively.
    $childStmt = $pdo->prepare("
        SELECT account_id, account_code, account_name, current_balance, status, level
          FROM accounts
         WHERE parent_account_id = ?
           AND account_id <> ?
         ORDER BY account_code, account_name
    ");
    $childStmt->execute([$account_id, $account_id]);
    $children = $childStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Last 50 posted journal lines for this account
    $txnStmt = $pdo->prepare("
        SELECT
            je.entry_id,
            je.entry_date,
            je.reference_number,
            je.description AS entry_desc,
            jei.description AS item_desc,
            jei.type,
            jei.amount
        FROM journal_entry_items jei
        JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE jei.account_id = ?
          AND je.status = 'posted'
        ORDER BY je.entry_date DESC, je.entry_id DESC
        LIMIT 50
    ");
    $txnStmt->execute([$account_id]);
    $transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Calculated balance from ALL posted lines (not just the 50 shown)
    $sumStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN jei.type = 'debit'  THEN jei.amount ELSE 0 END), 0) AS total_debit,
            COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) AS total_credit
        FROM journal_entry_items jei
        JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE jei.account_id = ?
          AND je.status = 'posted'
    ");
    $sumStmt->execute([$account_id]);
    $sums = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_debit' => 0, 'total_credit' => 0];

    $totalDebit  = (float)$sums['total_debit'];
    $totalCredit = (float)$sums['total_credit'];
    $opening     = (float)$account['opening_balance'];
    $stored      = (float)$account['current_balance'];

    // Natural side: per-account normal_balance, else the type's normal_side, else debit.
    $side = $account['normal_balance'] ?: ($account['type_normal_side'] ?: 'debit');
    // Calculated balance via the shared ledger helper — unified source (item lines
    // where present, else the entry header) so it agrees with the reconcile + the
    // Chart/Bank pages and never falsely flags a header-only entry as "drift".
    $calculated = accountLedgerBalance($pdo, (int)$account_id);

    $balances = [
        'opening_balance'    => round($opening, 2),
        'current_balance'    => round($stored, 2),     // stored on the row
        'total_debit'        => round($totalDebit, 2),
        'total_credit'       => round($totalCredit, 2),
        'calculated_balance' => round($calculated, 2), // derived from posted ledger
        'normal_side'        => $side,
        'in_sync'            => (abs($stored - $calculated) < 0.01),
    ];

    echo json_encode([
        'success'      => true,
        'account'      => $account,
        'children'     => $children,
        'transactions' => $transactions,
        'balances'     => $balances,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
