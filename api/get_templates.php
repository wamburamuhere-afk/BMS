<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $draw = $_GET['draw'] ?? 1;
    $start = $_GET['start'] ?? 0;
    $length = $_GET['length'] ?? 10;
    $search = $_GET['search']['value'] ?? '';
    
    // Filters
    $categoryId = $_GET['category_id'] ?? '';
    $type = $_GET['type'] ?? '';
    $status = $_GET['status'] ?? '';

    $query = "SELECT t.*, c.category_name, u.username as created_by_name 
              FROM document_templates t
              LEFT JOIN document_categories c ON t.category_id = c.id
              LEFT JOIN users u ON t.created_by = u.user_id
              WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $query .= " AND (t.template_name LIKE ? OR c.category_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($categoryId)) {
        $query .= " AND t.category_id = ?";
        $params[] = $categoryId;
    }

    if (!empty($type)) {
        $query .= " AND t.file_type = ?";
        $params[] = $type;
    }

    if (!empty($status) || $status === '0') {
        $query .= " AND t.is_active = ?";
        $params[] = $status;
    }

    // Total
    $total = $pdo->query("SELECT COUNT(*) FROM document_templates")->fetchColumn();
    
    // Filtered
    $stmt = $pdo->prepare(str_replace("SELECT t.*, c.category_name, u.username as created_by_name", "SELECT COUNT(*)", $query));
    $stmt->execute($params);
    $filtered = $stmt->fetchColumn();

    // Data
    $query .= " ORDER BY t.created_at DESC LIMIT $start, $length";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = [
        'totalTemplates' => intval($total),
        'activeTemplates' => intval($pdo->query("SELECT COUNT(*) FROM document_templates WHERE is_active = 1")->fetchColumn()),
        'totalUsage' => intval($pdo->query("SELECT SUM(usage_count) FROM document_templates")->fetchColumn()),
        'categoriesCount' => intval($pdo->query("SELECT COUNT(*) FROM document_categories")->fetchColumn())
    ];

    echo json_encode([
        'draw' => intval($draw),
        'recordsTotal' => $total,
        'recordsFiltered' => $filtered,
        'data' => $data,
        'stats' => $stats
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
