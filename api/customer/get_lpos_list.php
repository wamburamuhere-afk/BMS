<?php
// File: api/customer/get_lpos_list.php
// scope-audit: skip — scoped inline via scopeFilterSql('project','l') below
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('lpo')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to view LPOs']);
    exit;
}

try {
    global $pdo;

    $customer_id = intval($_GET['customer_id'] ?? 0);
    $status      = trim($_GET['status'] ?? '');
    $date_from   = trim($_GET['date_from'] ?? '');
    $date_to     = trim($_GET['date_to'] ?? '');

    $query = "
        SELECT l.*,
               CASE WHEN c.customer_type = 'business' AND c.company_name != '' AND c.company_name IS NOT NULL
                    THEN c.company_name ELSE c.customer_name END AS customer_display_name,
               (SELECT COUNT(*) FROM deliveries d WHERE d.customer_lpo_id = l.lpo_id AND d.status != 'cancelled') AS dn_count
        FROM customer_lpos l
        LEFT JOIN customers c ON l.customer_id = c.customer_id
        WHERE l.status != 'deleted'
    ";
    $params = [];

    if ($customer_id > 0) {
        $query .= " AND l.customer_id = ?";
        $params[] = $customer_id;
    }
    if ($status !== '') {
        $query .= " AND l.status = ?";
        $params[] = $status;
    }
    if ($date_from !== '') {
        $query .= " AND l.issue_date >= ?";
        $params[] = $date_from;
    }
    if ($date_to !== '') {
        $query .= " AND l.issue_date <= ?";
        $params[] = $date_to;
    }

    // Project-scope filter for non-admins (nullable — LPOs with no project stay visible)
    $query .= scopeFilterSqlNullable('project', 'l');

    $query .= " ORDER BY l.issue_date DESC, l.lpo_id DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $lpos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_lpos     = count($lpos);
    $total_amount   = array_sum(array_column($lpos, 'amount'));
    $pending_count  = count(array_filter($lpos, fn($l) => $l['status'] === 'pending'));
    $reviewed_count = count(array_filter($lpos, fn($l) => $l['status'] === 'reviewed'));
    $approved_list  = array_filter($lpos, fn($l) => in_array($l['status'], ['approved', 'open', 'partially_fulfilled', 'fulfilled'], true));
    $approved_count = count($approved_list);
    $approved_amount = array_sum(array_column($approved_list, 'amount'));

    echo json_encode([
        'success' => true,
        'data' => $lpos,
        'stats' => [
            'total_lpos'      => $total_lpos,
            'total_amount'    => $total_amount,
            'pending_count'   => $pending_count,
            'reviewed_count'  => $reviewed_count,
            'approved_count'  => $approved_count,
            'approved_amount' => $approved_amount,
        ]
    ]);
} catch (PDOException $e) {
    error_log("get_lpos_list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
