<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Date parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Calculate previous period
$prev_start_date = date('Y-m-01', strtotime($start_date . ' -1 month'));
$prev_end_date = date('Y-m-t', strtotime($end_date . ' -1 month'));

try {
    global $pdo;

    // Fetch Revenue
    $revenue_sql = "
        SELECT 
            ca.account_id,
            ca.account_name,
            ca.account_code,
            COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) as current_period,
            COALESCE((
                SELECT SUM(CASE WHEN jei2.type = 'credit' THEN jei2.amount ELSE 0 END)
                FROM journal_entries je2
                JOIN journal_entry_items jei2 ON je2.entry_id = jei2.entry_id
                WHERE jei2.account_id = ca.account_id 
                AND je2.entry_date BETWEEN ? AND ?
                AND je2.status = 'posted'
            ), 0) as previous_period
        FROM accounts ca
        LEFT JOIN journal_entries je ON je.entry_date BETWEEN ? AND ? AND je.status = 'posted'
        LEFT JOIN journal_entry_items jei ON je.entry_id = jei.entry_id AND jei.account_id = ca.account_id
        WHERE ca.account_type = 'income' 
        AND ca.status = 'active'
        GROUP BY ca.account_id, ca.account_name, ca.account_code
        ORDER BY ca.account_code
    ";
    
    $stmt = $pdo->prepare($revenue_sql);
    $stmt->execute([$prev_start_date, $prev_end_date, $start_date, $end_date]);
    $revenue_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Expenses and COGS
    $expense_sql = "
        SELECT 
            ca.account_id,
            ca.account_name,
            ca.account_code,
            ca.account_type,
            COALESCE(SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END), 0) as current_period,
            COALESCE((
                SELECT SUM(CASE WHEN jei2.type = 'debit' THEN jei2.amount ELSE 0 END)
                FROM journal_entries je2
                JOIN journal_entry_items jei2 ON je2.entry_id = jei2.entry_id
                WHERE jei2.account_id = ca.account_id 
                AND je2.entry_date BETWEEN ? AND ?
                AND je2.status = 'posted'
            ), 0) as previous_period
        FROM accounts ca
        LEFT JOIN journal_entries je ON je.entry_date BETWEEN ? AND ? AND je.status = 'posted'
        LEFT JOIN journal_entry_items jei ON je.entry_id = jei.entry_id AND jei.account_id = ca.account_id
        WHERE (ca.account_type = 'expense' OR ca.account_type = 'cost_of_sales' OR ca.account_name LIKE '%Salaries%')
        AND ca.status = 'active'
        GROUP BY ca.account_id, ca.account_name, ca.account_code, ca.account_type
        ORDER BY ca.account_code
    ";

    $stmt = $pdo->prepare($expense_sql);
    $stmt->execute([$prev_start_date, $prev_end_date, $start_date, $end_date]);
    $expense_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'revenue_accounts' => $revenue_accounts,
            'expense_accounts' => $expense_accounts,
            'meta' => [
                'current_start' => $start_date,
                'current_end' => $end_date,
                'prev_start' => $prev_start_date,
                'prev_end' => $prev_end_date
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("Income Statement Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
