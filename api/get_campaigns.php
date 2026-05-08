<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!hasPermission('view_campaigns')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

try {
    $draw = $_GET['draw'] ?? 1;
    $start = $_GET['start'] ?? 0;
    $length = $_GET['length'] ?? 10;
    $search = $_GET['search']['value'] ?? '';
    
    // Columns
    $columns = ['campaign_name', 'type', 'budget', 'spent', 'start_date', 'status'];
    $order_idx = $_GET['order'][0]['column'] ?? 0;
    $order_dir = $_GET['order'][0]['dir'] ?? 'asc';
    $order_col = $columns[$order_idx] ?? 'start_date';

    $query = "SELECT * FROM marketing_campaigns WHERE 1=1";
    $params = [];

    $type = $_GET['type'] ?? '';
    $status = $_GET['status'] ?? '';

    if (!empty($search)) {
        $query .= " AND (campaign_name LIKE ? OR type LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($type)) {
        $query .= " AND type = ?";
        $params[] = $type;
    }

    if (!empty($status)) {
        $query .= " AND status = ?";
        $params[] = $status;
    }

    // Total
    $total = $pdo->query("SELECT COUNT(*) FROM marketing_campaigns")->fetchColumn();
    
    // Filtered
    $stmt = $pdo->prepare(str_replace("SELECT *", "SELECT COUNT(*)", $query));
    $stmt->execute($params);
    $filtered = $stmt->fetchColumn();

    // Limit
    $query .= " ORDER BY $order_col $order_dir LIMIT $start, $length";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format data slightly if needed, but keep all raw fields for the edit modal
    $formatted = [];
    foreach ($data as $row) {
        $formatted[] = $row; // Send full row including campaign_id, target_audience, etc.
    }

    // Stats
    $stats_stmt = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
        SUM(budget) as budget,
        SUM(spent) as spent
        FROM marketing_campaigns");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw' => intval($draw),
        'recordsTotal' => $total,
        'recordsFiltered' => $filtered,
        'data' => $formatted,
        'stats' => [
            'total' => intval($stats['total'] ?? 0),
            'active' => intval($stats['active'] ?? 0),
            'budget' => floatval($stats['budget'] ?? 0),
            'spent' => floatval($stats['spent'] ?? 0)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
