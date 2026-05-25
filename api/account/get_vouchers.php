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
    
    $offset = ($page - 1) * $limit;
    
    // Build query conditions
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $whereClause .= " AND (pv.payee_name LIKE ? OR pv.voucher_number LIKE ? OR pv.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Phase C — project-scope filter
    $whereClause .= scopeFilterSql('project', 'pv');

    // Count total records (scope-aware)
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM payment_vouchers pv $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);
    
    // Fetch vouchers
    $query = "
        SELECT pv.*, u.username as prepared_by_name, ac.category_name, p.project_name 
        FROM payment_vouchers pv
        LEFT JOIN users u ON pv.prepared_by = u.user_id
        LEFT JOIN account_categories ac ON pv.expense_category_id = ac.category_id
        LEFT JOIN projects p ON pv.project_id = p.project_id
        $whereClause
        ORDER BY pv.vouch_date DESC, pv.created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate Stats — scope-aware
    $scopeBare = scopeFilterSql('project');
    $s1 = $pdo->prepare("SELECT SUM(amount) FROM payment_vouchers WHERE status = 'paid' $scopeBare"); $s1->execute();
    $total_paid = $s1->fetchColumn() ?: 0;
    $s2 = $pdo->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE status = 'draft' $scopeBare"); $s2->execute();
    $pending_approval = $s2->fetchColumn() ?: 0;
    $s3 = $pdo->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE 1=1 $scopeBare"); $s3->execute();
    $total_vouchers = $s3->fetchColumn() ?: 0;

    echo json_encode([
        'success' => true,
        'vouchers' => $vouchers,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => $totalRecords,
            'limit' => $limit
        ],
        'stats' => [
            'total_paid' => $total_paid,
            'pending_approval' => $pending_approval,
            'total_vouchers' => $total_vouchers
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
