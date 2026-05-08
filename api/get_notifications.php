<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) {
        throw new Exception("Not authenticated");
    }

    $draw = intval($_GET['draw'] ?? 1);
    $start = intval($_GET['start'] ?? 0);
    $length = intval($_GET['length'] ?? 10);
    $search = $_GET['search']['value'] ?? '';
    $type = $_GET['type'] ?? '';
    $priority = $_GET['priority'] ?? '';
    $is_read = $_GET['is_read'] ?? '';

    $where = " WHERE user_id = :user_id ";
    $params = [':user_id' => $userId];

    if (!empty($search)) {
        $where .= " AND (title LIKE :search OR message LIKE :search_msg) ";
        $params[':search'] = "%$search%";
        $params[':search_msg'] = "%$search%";
    }

    if (!empty($type)) {
        $where .= " AND type = :type ";
        $params[':type'] = $type;
    }

    if (!empty($priority)) {
        $where .= " AND priority = :priority ";
        $params[':priority'] = $priority;
    }

    if ($is_read !== '') {
        $where .= " AND is_read = :is_read ";
        $params[':is_read'] = intval($is_read);
    }

    // Optimization: Get stats for the status bar
    $stats_stmt = $pdo->prepare("SELECT 
        COUNT(*) as total_notifications,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count,
        SUM(CASE WHEN is_read = 0 AND priority = 'high' THEN 1 ELSE 0 END) as high_priority_unread,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_count
        FROM notifications WHERE user_id = :user_id");
    $stats_stmt->execute([':user_id' => $userId]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Get total filtered records
    $filtered_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications " . $where);
    $filtered_stmt->execute($params);
    $recordsFiltered = $filtered_stmt->fetchColumn();

    // Get total records
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id");
    $total_stmt->execute([':user_id' => $userId]);
    $recordsTotal = $total_stmt->fetchColumn();

    // Get data - Using simple SELECT without JOIN since column names vary across environments
    // We will handle specific data mapping in PHP to avoid SQL errors
    $sql = "SELECT * FROM notifications " . $where . " ORDER BY created_at DESC LIMIT $start, $length";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Map customer name if needed and handle properties
    foreach ($data as &$row) {
        $row['customer_name'] = '';
        if (!empty($row['customer_id'])) {
            // Attempt to fetch customer name separately to avoid JOIN errors
            $c_stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
            $c_stmt->execute([$row['customer_id']]);
            $customer = $c_stmt->fetch(PDO::FETCH_ASSOC);
            if ($customer) {
                // Try different common column names
                $row['customer_name'] = $customer['customer_name'] ?? 
                                      (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) ?:
                                      ($customer['name'] ?? '');
            }
        }
        $row['related_loan_id'] = $row['loan_id'] ?? null;
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data,
        'stats' => [
            'total_notifications' => intval($stats['total_notifications'] ?? 0),
            'unread_count' => intval($stats['unread_count'] ?? 0),
            'high_priority_unread' => intval($stats['high_priority_unread'] ?? 0),
            'today_count' => intval($stats['today_count'] ?? 0)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'draw' => 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => $e->getMessage()
    ]);
}
