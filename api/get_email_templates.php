<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    // Basic pagination for DataTables
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';

    $params = [];
    $where = " WHERE 1=1 ";
    if (!empty($search)) {
        $where .= " AND (template_name LIKE ? OR subject LIKE ? OR content LIKE ?) ";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Total records
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM email_templates");
    $total_records = $total_stmt->fetchColumn();

    // Filtered records
    $filtered_stmt = $pdo->prepare("SELECT COUNT(*) FROM email_templates " . $where);
    $filtered_stmt->execute($params);
    $filtered_records = $filtered_stmt->fetchColumn();

    // Stats
    $stats_stmt = $pdo->query("SELECT 
        COUNT(*) as totalTemplates,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as activeTemplates
        FROM email_templates");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Data
    $sql = "SELECT * FROM email_templates " . $where . " ORDER BY created_at DESC LIMIT $start, $length";
    $data_stmt = $pdo->prepare($sql);
    $data_stmt->execute($params);
    $data = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $filtered_records,
        "data" => $data,
        "stats" => [
            "totalTemplates" => $stats['totalTemplates'] ?? 0,
            "activeTemplates" => $stats['activeTemplates'] ?? 0
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        "draw" => 1,
        "recordsTotal" => 0,
        "recordsFiltered" => 0,
        "data" => [],
        "error" => $e->getMessage()
    ]);
}
