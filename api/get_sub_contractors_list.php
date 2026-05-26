<?php
// scope-audit: skip — sub-contractors list; sub-contractor scope deferred to Phase G-2
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $search             = trim($_GET['search'] ?? '');
    $exclude_project_id = intval($_GET['exclude_project_id'] ?? 0);

    $params = [];
    $sql = "SELECT supplier_id, supplier_name, supplier_code, status, category_id
            FROM sub_contractors
            WHERE status = 'active'";

    if ($search !== '') {
        $sql .= " AND (supplier_name LIKE ? OR supplier_code LIKE ?)";
        $like = "%$search%";
        $params[] = $like;
        $params[] = $like;
    }

    if ($exclude_project_id > 0) {
        $sql .= " AND supplier_id NOT IN (
                    SELECT supplier_id FROM sub_contractor_projects WHERE project_id = ?
                  )";
        $params[] = $exclude_project_id;
    }

    $sql .= " ORDER BY supplier_name ASC LIMIT 80";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Select2 AJAX expects a `results` array with id/text keys
    $results = array_map(fn($s) => [
        'id'   => $s['supplier_id'],
        'text' => $s['supplier_name'] . ($s['supplier_code'] ? ' (' . $s['supplier_code'] . ')' : '')
    ], $data);

    echo json_encode(['success' => true, 'data' => $data, 'results' => $results]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
