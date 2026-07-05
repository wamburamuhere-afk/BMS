<?php
// api/operations/add_project_note.php — add a note to a project.
// scope-audit: skip — project_id is validated and gated by userCan('project', ...) below.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}
if (!canCreate('projects') && !canEdit('projects')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: you cannot add project notes']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $project_id = intval($_POST['project_id'] ?? 0);
    $note       = trim($_POST['note'] ?? '');

    if ($project_id <= 0) throw new Exception('Project is required');
    if ($note === '')     throw new Exception('Note text is required');

    if (function_exists('userCan') && !userCan('project', $project_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope']);
        exit();
    }

    $chk = $pdo->prepare("SELECT project_id FROM projects WHERE project_id = ?");
    $chk->execute([$project_id]);
    if (!$chk->fetch()) throw new Exception('Project not found');

    $stmt = $pdo->prepare("INSERT INTO project_notes (project_id, note_content, user_id, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$project_id, $note, $_SESSION['user_id']]);
    $note_id = $pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Added note to project #$project_id");

    echo json_encode(['success' => true, 'message' => 'Note added.', 'note_id' => $note_id]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
