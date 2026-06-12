<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $from_date = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
    $to_date = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
    // Filter expenses by the EXPENSE ACCOUNT they were booked to (replaces the
    // retired account_categories "category" filter).
    $expense_account_id = isset($_GET['expense_account_id']) ? intval($_GET['expense_account_id']) : 0;
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $fund_account_id = isset($_GET['fund_account_id']) && $_GET['fund_account_id'] !== '' ? intval($_GET['fund_account_id']) : 0;

    require_once __DIR__ . '/../../core/payment_source.php';
    require_once __DIR__ . '/../../core/account_balance.php';
    $defaultFund = (int)(pettyCashAccountId($pdo) ?: 0);

    $offset = ($page - 1) * $limit;

    // Build query conditions
    $whereClause = "WHERE 1=1";
    $params = [];

    // Scope to the selected petty cash FUND. The default fund also shows legacy
    // (untagged) transactions so nothing is hidden after the multi-fund upgrade.
    if ($fund_account_id > 0) {
        if ($fund_account_id === $defaultFund) {
            $whereClause .= " AND (pt.fund_account_id = ? OR pt.fund_account_id IS NULL)";
            $params[] = $fund_account_id;
        } else {
            $whereClause .= " AND pt.fund_account_id = ?";
            $params[] = $fund_account_id;
        }
    }
    
    if (!empty($search)) {
        $whereClause .= " AND (pt.description LIKE ? OR pt.reference_number LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($from_date)) {
        $whereClause .= " AND pt.transaction_date >= ?";
        $params[] = $from_date;
    }

    if (!empty($to_date)) {
        $whereClause .= " AND pt.transaction_date <= ?";
        $params[] = $to_date;
    }

    if ($expense_account_id > 0) {
        $whereClause .= " AND pt.expense_account_id = ?";
        $params[] = $expense_account_id;
    }

    if (!empty($type)) {
        $whereClause .= " AND pt.type = ?";
        $params[] = $type;
    }
    
    // Count total records for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM petty_cash_transactions pt $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);
    
    // Fetch transactions with pagination
    $query = "
        SELECT pt.*, u.username,
               ea.account_name AS expense_account_name, ea.account_code AS expense_account_code,
               sa.account_name AS source_account_name
        FROM petty_cash_transactions pt
        LEFT JOIN users u ON pt.user_id = u.user_id
        LEFT JOIN accounts ea ON pt.expense_account_id = ea.account_id
        LEFT JOIN accounts sa ON pt.source_account_id = sa.account_id
        $whereClause
        ORDER BY pt.transaction_date DESC, pt.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Balance of the SELECTED fund — the ledger-true figure (opening + posted
    // movements), the same source the Chart of Accounts and Bank Accounts use, so
    // it can never drift. Falls back to the default fund when none is chosen.
    $balanceFund = $fund_account_id > 0 ? $fund_account_id : $defaultFund;
    if ($balanceFund > 0) {
        $total_balance = accountLedgerBalance($pdo, $balanceFund);
    } else {
        $total_balance = (float)($pdo->query("SELECT SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END) FROM petty_cash_transactions")->fetchColumn() ?: 0);
    }

    // Monthly Expenses + total count — scoped to the same fund filter as the list.
    $fundExpr = ''; $fundParams = [];
    if ($fund_account_id > 0) {
        if ($fund_account_id === $defaultFund) { $fundExpr = " AND (fund_account_id = ? OR fund_account_id IS NULL)"; $fundParams[] = $fund_account_id; }
        else                                   { $fundExpr = " AND fund_account_id = ?"; $fundParams[] = $fund_account_id; }
    }
    $expStmt = $pdo->prepare("SELECT SUM(amount) FROM petty_cash_transactions
        WHERE type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')" . $fundExpr);
    $expStmt->execute($fundParams);
    $total_expenses_month = $expStmt->fetchColumn() ?: 0;

    $totalTransStmt = $pdo->prepare("SELECT COUNT(*) FROM petty_cash_transactions WHERE 1=1" . $fundExpr);
    $totalTransStmt->execute($fundParams);
    $total_transactions = $totalTransStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => $totalRecords,
            'limit' => $limit
        ],
        'stats' => [
            'balance' => $total_balance,
            'monthly_expenses' => $total_expenses_month,
            'total_transactions' => $total_transactions
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
