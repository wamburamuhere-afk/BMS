<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';

header('Content-Type: application/json');


if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if user has permission to view invoices
if (!canView('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to view invoices']);
    exit;
}


error_reporting(0);
try {
    global $pdo;

    // Auto-mark invoices as overdue before querying.
    $pdo->exec("UPDATE invoices
                   SET status = 'overdue'
                 WHERE status IN ('approved','sent')
                   AND due_date IS NOT NULL
                   AND due_date < CURDATE()");

    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $customer_filter = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $payment_filter = $_GET['payment_status'] ?? '';
    $warehouse_filter = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;

    if ($warehouse_filter > 0 && !userCan('warehouse', $warehouse_filter)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse is not in your assigned scope.']);
        exit;
    }

    // Get DataTables parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search_value = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';

    // Base conditions
    $where_conditions = ["1=1"];
    $params = [];

    if ($customer_filter > 0) {
        $where_conditions[] = "i.customer_id = ?";
        $params[] = $customer_filter;
    }

    if (!empty($date_from)) {
        $where_conditions[] = "i.invoice_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $where_conditions[] = "i.invoice_date <= ?";
        $params[] = $date_to;
    }

    if (!empty($search_value)) {
        $where_conditions[] = "(i.invoice_number LIKE ? OR c.customer_name LIKE ?)";
        $search_term = "%$search_value%";
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if (!empty($status_filter)) {
        $where_conditions[] = "i.status = ?";
        $params[] = $status_filter;
    } else {
        // Default: hide cancelled invoices; user must explicitly filter for them
        $where_conditions[] = "i.status != 'cancelled'";
    }

    if (!empty($payment_filter)) {
        if ($payment_filter == 'paid') {
            $where_conditions[] = "(i.status = 'paid' OR i.paid_amount >= i.grand_total)";
        } elseif ($payment_filter == 'partial') {
            $where_conditions[] = "(i.status = 'partial' OR (i.paid_amount > 0 AND i.paid_amount < i.grand_total))";
        } elseif ($payment_filter == 'unpaid') {
            $where_conditions[] = "(i.paid_amount = 0 AND i.status != 'paid')";
        } elseif ($payment_filter == 'overdue') {
            $where_conditions[] = "(i.status NOT IN ('paid','cancelled','draft') AND i.due_date < CURDATE() AND i.paid_amount < i.grand_total)";
        }
    }

    if ($warehouse_filter > 0) {
        $where_conditions[] = "i.warehouse_id = ?";
        $params[] = $warehouse_filter;
    }

    $where_sql = implode(" AND ", $where_conditions);

    // Phase G — show invoices in assigned projects OR with no project (nullable)
    // Phase 6 (pos_upgrade_plan.md) — warehouse scope, same nullable convention.
    $scopeI = scopeFilterSqlNullable('project', 'i') . scopeFilterSqlNullable('warehouse', 'i');

    // 1. Stats — full counts including per-status breakdown
    $stats_query = "
        SELECT
            COUNT(*) as total_invoices,
            COALESCE(SUM(grand_total), 0) as total_amount,
            COALESCE(SUM(paid_amount), 0) as total_paid,
            SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) as cnt_paid,
            SUM(CASE WHEN i.status = 'pending' THEN 1 ELSE 0 END) as cnt_pending,
            SUM(CASE WHEN i.status = 'sent' THEN 1 ELSE 0 END) as cnt_sent,
            SUM(CASE WHEN i.status = 'partial' THEN 1 ELSE 0 END) as cnt_partial,
            SUM(CASE WHEN i.status = 'draft' THEN 1 ELSE 0 END) as cnt_draft,
            SUM(CASE WHEN i.status = 'cancelled' THEN 1 ELSE 0 END) as cnt_cancelled,
            SUM(CASE WHEN (i.status NOT IN ('paid','cancelled','draft') AND i.due_date < CURDATE() AND i.paid_amount < i.grand_total) THEN 1 ELSE 0 END) as cnt_overdue,
            COALESCE(SUM(CASE WHEN (i.status NOT IN ('paid','cancelled','draft') AND i.due_date < CURDATE() AND i.paid_amount < i.grand_total) THEN (i.grand_total - i.paid_amount) ELSE 0 END), 0) as overdue_amount
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        WHERE $where_sql $scopeI
    ";
    $stmt = $pdo->prepare($stats_query);
    $stmt->execute($params);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stats_result) {
        $stats_result = ['total_invoices' => 0, 'total_amount' => 0, 'total_paid' => 0,
                         'cnt_paid' => 0, 'cnt_pending' => 0, 'cnt_sent' => 0,
                         'cnt_partial' => 0, 'cnt_draft' => 0, 'cnt_cancelled' => 0, 'cnt_overdue' => 0];
    }
    
    // Scope-aware total: count what this user can actually see.
    $recordsTotal_stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE 1=1 " . scopeFilterSqlNullable('project'));
    $recordsTotal_stmt->execute();
    $recordsTotal    = $recordsTotal_stmt->fetchColumn();
    $recordsFiltered = $stats_result['total_invoices'];

    // 2. Data query - Optimized to avoid GROUP BY issues
    $query = "
        SELECT 
            i.*,
            c.customer_name,
            c.company_name,
            so.order_number,
            p.project_name,
            u1.username as created_by_name,
            (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.invoice_id) as total_items,
            (i.grand_total - i.paid_amount) as balance_due,
            CASE
                WHEN i.status = 'cancelled' THEN 'cancelled'
                WHEN i.status = 'paid'      THEN 'paid'
                WHEN i.status = 'partial'   THEN 'partial'
                WHEN i.status = 'overdue'   THEN 'overdue'
                WHEN i.status = 'approved'  THEN 'approved'
                WHEN i.status = 'reviewed'  THEN 'reviewed'
                WHEN i.status = 'sent'      THEN 'sent'
                WHEN i.status = 'pending'   THEN 'pending'
                ELSE 'draft'
            END as display_status,
            CASE
                WHEN EXISTS(
                    SELECT 1 FROM invoice_items ii2
                    JOIN products p2 ON ii2.product_id = p2.product_id
                    WHERE ii2.invoice_id = i.invoice_id AND (p2.is_service = 0 OR p2.is_service IS NULL)
                ) THEN 'Inventory'
                WHEN (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.invoice_id) > 0 THEN 'Non-Inventory'
                ELSE 'Inventory'
            END as invoice_type
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id
        LEFT JOIN projects p ON i.project_id = p.project_id
        LEFT JOIN users u1 ON i.created_by = u1.user_id
        WHERE $where_sql $scopeI
        ORDER BY i.invoice_date DESC, i.created_at DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $pdo->prepare($query);
    $param_index = 1;
    foreach ($params as $v) {
        $stmt->bindValue($param_index++, $v);
    }
    $stmt->bindValue($param_index++, (int)$length, PDO::PARAM_INT);
    $stmt->bindValue($param_index++, (int)$start, PDO::PARAM_INT);
    $stmt->execute();
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_due = $stats_result['total_amount'] - $stats_result['total_paid'];
    
    echo json_encode([
        'success'         => true,
        'draw'            => $draw,
        'recordsTotal'    => (int)$recordsTotal,
        'recordsFiltered' => (int)$recordsFiltered,
        'data'            => $invoices,
        'stats'           => [
            'total_invoices' => (int)$stats_result['total_invoices'],
            'total_amount'   => (float)$stats_result['total_amount'],
            'total_paid'     => (float)$stats_result['total_paid'],
            'total_due'      => (float)$total_due,
            'overdue_amount'  => (float)($stats_result['overdue_amount'] ?? 0),
            'status_counts'  => [
                'paid'      => (int)$stats_result['cnt_paid'],
                'pending'   => (int)$stats_result['cnt_pending'],
                'sent'      => (int)$stats_result['cnt_sent'],
                'partial'   => (int)$stats_result['cnt_partial'],
                'draft'     => (int)$stats_result['cnt_draft'],
                'cancelled' => (int)$stats_result['cnt_cancelled'],
                'overdue'   => (int)$stats_result['cnt_overdue'],
            ]
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
