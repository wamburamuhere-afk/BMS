<?php
// api/operations/get_projects.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

try {
    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
    $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;

    $status = $_GET['status'] ?? '';
    $search_term = $_GET['search_term'] ?? '';

    $where = ["1=1"];
    $params = [];

    if ($status) {
        $where[] = "status = ?";
        $params[] = $status;
    }

    if ($search_term) {
        $where[] = "(project_name LIKE ? OR project_manager LIKE ? OR description LIKE ?)";
        $s = "%$search_term%";
        $params[] = $s; $params[] = $s; $params[] = $s;
    }

    $where_clause = implode(" AND ", $where);

    // Phase B — project-scope filter for non-admin users
    $scopeUnaliased = scopeFilterSql('project');         // " AND project_id IN (...) "
    $scopeAliasedP  = scopeFilterSql('project', 'p');    // " AND p.project_id IN (...) "

    // Total Records (the user's visible total — not the system total).
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE 1=1" . $scopeUnaliased);
    $total_stmt->execute();
    $total_records = $total_stmt->fetchColumn();

    // Filtered Records
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE $where_clause" . $scopeUnaliased);
    $count_stmt->execute($params);
    $filtered_records = $count_stmt->fetchColumn();
    
    // Data with financial calculations using subqueries for accuracy
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            (SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE project_id = p.project_id AND status NOT IN ('cancelled', 'void', 'draft', 'pending')) as total_revenue,
            (SELECT COALESCE(SUM(grand_total), 0) FROM sales_orders WHERE project_id = p.project_id AND status NOT IN ('cancelled') AND is_quote = 0) as total_orders,
            (
                (SELECT COALESCE(SUM(amount), 0) FROM payment_vouchers WHERE project_id = p.project_id AND status IN ('approved', 'paid')) +
                (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE project_id = p.project_id AND status IN ('approved', 'paid'))
            ) as total_expense,
            (SELECT COALESCE(SUM(grand_total), 0) FROM purchase_orders WHERE project_id = p.project_id AND status NOT IN ('cancelled')) as total_committed,
            (
                SELECT COALESCE(NULLIF(SUM(allocated_amount), 0), p.budget) 
                FROM budgets 
                WHERE project_id = p.project_id AND status = 'approved'
            ) as budget
        FROM projects p
        WHERE $where_clause $scopeAliasedP
        ORDER BY p.start_date DESC
        LIMIT $start, $length
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($data as &$project) {
        $project['budget'] = $project['budget'] ?: 0;
        $project['profit'] = $project['total_revenue'] - $project['total_expense'];
        $project['profit_margin'] = $project['total_revenue'] > 0 
            ? round(($project['profit'] / $project['total_revenue']) * 100, 2) 
            : 0;
        
        // projects.progress_percent is now strictly synchronized via syncProjectProgress
        $project['progress_percent'] = (float)$project['progress_percent'];
    }
    
    // Stats Summary — also scoped so non-admins see only their projects' totals
    $scopeAliasedP2 = scopeFilterSql('project', 'p2');
    $stats_stmt = $pdo->prepare("SELECT
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
        COUNT(CASE WHEN status = 'planning' THEN 1 END) as planning,
        COUNT(CASE WHEN status = 'on_hold' THEN 1 END) as on_hold,
        (
            SELECT SUM(COALESCE(NULLIF(item_total, 0), p2.budget))
            FROM projects p2
            LEFT JOIN (
                SELECT project_id, SUM(allocated_amount) as item_total
                FROM budgets
                WHERE status = 'approved'
                GROUP BY project_id
            ) b ON p2.project_id = b.project_id
            WHERE p2.status != 'cancelled' $scopeAliasedP2
        ) as total_budget,
        AVG(progress_percent) as avg_progress
        FROM projects WHERE 1=1 $scopeUnaliased");
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $filtered_records,
        "data" => $data,
        "stats" => $stats
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
