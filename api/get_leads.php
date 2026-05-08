<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

// Check permission
if (!hasPermission('view_leads')) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

try {
    // DataTables parameters
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
    $order_column_index = isset($_GET['order'][0]['column']) ? intval($_GET['order'][0]['column']) : 0;
    $order_dir = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'desc';

    // Filters
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $source = isset($_GET['source']) ? $_GET['source'] : '';

    // Columns mapping
    $columns = [
        0 => 'first_name',
        1 => 'email',
        2 => 'source',
        3 => 'score',
        4 => 'status',
        5 => 'created_at'
    ];
    
    $order_column = isset($columns[$order_column_index]) ? $columns[$order_column_index] : 'created_at';

    // Base Query
    $query = "SELECT * FROM leads WHERE 1=1";
    $params = [];

    // Filters
    if (!empty($status)) {
        $query .= " AND status = ?";
        $params[] = $status;
    }

    if (!empty($source)) {
        $query .= " AND source = ?";
        $params[] = $source;
    }

    // Search
    if (!empty($search)) {
        $query .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    // Total Records
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM leads");
    $recordsTotal = $count_stmt->fetchColumn();

    // Filtered Records
    $count_filtered_sql = "SELECT COUNT(*) FROM ($query) as filtered_table"; // Simple wrap for count
    // Optimizing count for filtered:
    $count_filtered_query = "SELECT COUNT(*) FROM leads WHERE 1=1";
    if (!empty($status)) $count_filtered_query .= " AND status = '$status'"; // Caution: sanitize if direct variable, but here we used params.
    // Let's use the prepared statement approach correctly for filtered count
    $stmt = $pdo->prepare(str_replace("SELECT *", "SELECT COUNT(*)", $query));
    $stmt->execute($params);
    $recordsFiltered = $stmt->fetchColumn();

    // Order & Limit
    $query .= " ORDER BY $order_column $order_dir LIMIT $start, $length";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format Data
    $formatted_data = [];
    foreach ($data as $row) {
        $formatted_data[] = $row; // Send raw data including lead_id, phone, last_name, etc.
    }

    // Stats
    $stats_stmt = $pdo->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) as new,
        ROUND(AVG(score)) as avg_score,
        SUM(CASE WHEN status = 'Converted' THEN 1 ELSE 0 END) as converted
        FROM leads");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $formatted_data,
        'stats' => [
            'total' => intval($stats['total'] ?? 0),
            'new' => intval($stats['new'] ?? 0),
            'avg_score' => intval($stats['avg_score'] ?? 0),
            'converted' => intval($stats['converted'] ?? 0)
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
