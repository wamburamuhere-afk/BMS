<?php
// api/operations/get_project_notes.php — list a project's notes (newest first).
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

    $stmt = $pdo->prepare("
        SELECT n.note_id, n.note_content AS note, n.created_at, n.user_id,
               COALESCE(NULLIF(TRIM(u.username), ''), 'System') AS author
        FROM project_notes n
        LEFT JOIN users u ON u.user_id = n.user_id
        WHERE n.project_id = ? AND (n.status IS NULL OR n.status != 'deleted')
        ORDER BY n.created_at DESC, n.note_id DESC
    ");
    $stmt->execute([$project_id]);

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
