<?php
// api/operations/delete_project_note.php — soft-delete a project note.
// scope-audit: skip — the note's project_id is looked up and gated by userCan('project', ...) below.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}
if (!canDelete('projects') && !canEdit('projects')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: you cannot delete project notes']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $note_id = intval($_POST['note_id'] ?? 0);
    if ($note_id <= 0) throw new Exception('Note is required');

    $st = $pdo->prepare("SELECT project_id FROM project_notes WHERE note_id = ? AND (status IS NULL OR status != 'deleted')");
    $st->execute([$note_id]);
    $project_id = $st->fetchColumn();
    if (!$project_id) throw new Exception('Note not found');

    if (function_exists('userCan') && !userCan('project', (int)$project_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope']);
        exit();
    }

    $pdo->prepare("UPDATE project_notes SET status = 'deleted' WHERE note_id = ?")->execute([$note_id]);
    logActivity($pdo, $_SESSION['user_id'], "Deleted note #$note_id from project #$project_id");

    echo json_encode(['success' => true, 'message' => 'Note deleted.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
