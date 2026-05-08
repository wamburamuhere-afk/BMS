<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $draw = isset($_GET['draw']) ? intval($_GET['draw']) : 1;
    $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
    $length = isset($_GET['length']) ? intval($_GET['length']) : 10;
    $search = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';

    $type = $_GET['type'] ?? '';
    $search_term = $_GET['search_term'] ?? '';

    $params = [];
    $where = " WHERE 1=1 ";
    
    if (!empty($type)) {
        $where .= " AND template_type = ? ";
        $params[] = $type;
    }

    if (!empty($search_term)) {
        $where .= " AND (template_name LIKE ? OR message_content LIKE ?) ";
        $s = "%$search_term%";
        $params[] = $s; $params[] = $s;
    }

    if (!empty($search)) {
        $where .= " AND (template_name LIKE ? OR message_content LIKE ?) ";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Total records
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM sms_templates");
    $total_records = $total_stmt->fetchColumn();

    // Filtered records
    $filtered_stmt = $pdo->prepare("SELECT COUNT(*) FROM sms_templates " . $where);
    $filtered_stmt->execute($params);
    $filtered_records = $filtered_stmt->fetchColumn();

    // Stats
    $stats_stmt = $pdo->query("SELECT 
        COUNT(*) as totalTemplates,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as activeTemplates
        FROM sms_templates");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Data - map column names for the JS
    $sql = "SELECT template_id as id, template_name, template_type, message_content as content, is_active, created_at 
            FROM sms_templates " . $where . " ORDER BY created_at DESC LIMIT $start, $length";
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
