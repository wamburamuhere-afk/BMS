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
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    
    $offset = ($page - 1) * $limit;
    
    // Build query conditions
    $whereClause = "WHERE 1=1";
    $params = [];
    
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

    if ($category_id > 0) {
        $whereClause .= " AND pt.category_id = ?";
        $params[] = $category_id;
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
        SELECT pt.*, u.username, ac.category_name 
        FROM petty_cash_transactions pt
        LEFT JOIN users u ON pt.user_id = u.user_id
        LEFT JOIN account_categories ac ON pt.category_id = ac.category_id
        $whereClause
        ORDER BY pt.transaction_date DESC, pt.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals (Overall Balance & Monthly Expenses) - Separate from pagination
    // Balance
    $balStmt = $pdo->query("SELECT SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END) as balance FROM petty_cash_transactions");
    $total_balance = $balStmt->fetchColumn() ?: 0;
    
    // Monthly Expenses
    $expStmt = $pdo->query("
        SELECT SUM(amount) FROM petty_cash_transactions 
        WHERE type = 'expense' 
        AND DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
    ");
    $total_expenses_month = $expStmt->fetchColumn() ?: 0;
    
    // Total Transactions count
    $totalTransStmt = $pdo->query("SELECT COUNT(*) FROM petty_cash_transactions");
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
