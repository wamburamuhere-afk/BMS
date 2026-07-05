<?php
// api/operations/get_project_notes.php — list a project's notes with optional
// filters (search / author / date range) + pagination.
// scope-audit: skip — project_id is validated and gated by userCan('project', ...) below.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}
if (!canView('projects')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

try {
    $project_id = intval($_GET['project_id'] ?? 0);
    if ($project_id <= 0) throw new Exception('Project is required');

    if (function_exists('userCan') && !userCan('project', $project_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope']);
        exit();
    }

    // Filters
    $search    = trim($_GET['search'] ?? '');
    $author    = intval($_GET['author'] ?? 0);
    $date_from = trim($_GET['date_from'] ?? '');
    $date_to   = trim($_GET['date_to'] ?? '');
    $limit     = intval($_GET['limit'] ?? 20);
    $offset    = intval($_GET['offset'] ?? 0);
    if ($limit <= 0 || $limit > 200) $limit = 20;
    if ($offset < 0) $offset = 0;

    $where  = ["n.project_id = ?", "(n.status IS NULL OR n.status != 'deleted')"];
    $params = [$project_id];

    if ($search !== '') {
        $where[] = "(n.note_content LIKE ? OR u.username LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($author > 0) {
        $where[] = "n.user_id = ?";
        $params[] = $author;
    }
    if ($date_from !== '') {
        $where[] = "DATE(n.created_at) >= ?";
        $params[] = $date_from;
    }
    if ($date_to !== '') {
        $where[] = "DATE(n.created_at) <= ?";
        $params[] = $date_to;
    }
    $whereSql = implode(' AND ', $where);

    // Total (for the current filter)
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM project_notes n LEFT JOIN users u ON u.user_id = n.user_id WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Page of notes
    $listStmt = $pdo->prepare("
        SELECT n.note_id, n.note_content AS note, n.created_at, n.user_id,
               COALESCE(NULLIF(TRIM(u.username), ''), 'System') AS author
        FROM project_notes n
        LEFT JOIN users u ON u.user_id = n.user_id
        WHERE $whereSql
        ORDER BY n.created_at DESC, n.note_id DESC
        LIMIT $limit OFFSET $offset
    ");
    $listStmt->execute($params);
    $data = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    // Distinct authors of this project's notes (for the filter dropdown)
    $authStmt = $pdo->prepare("
        SELECT DISTINCT n.user_id, COALESCE(NULLIF(TRIM(u.username), ''), 'System') AS username
        FROM project_notes n
        LEFT JOIN users u ON u.user_id = n.user_id
        WHERE n.project_id = ? AND (n.status IS NULL OR n.status != 'deleted') AND n.user_id IS NOT NULL
        ORDER BY username ASC
    ");
    $authStmt->execute([$project_id]);
    $authors = $authStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $data,
        'total'   => $total,
        'offset'  => $offset,
        'limit'   => $limit,
        'authors' => $authors,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
