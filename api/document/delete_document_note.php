<?php
// File: api/document/delete_document_note.php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $note_id = isset($_POST['note_id']) ? (int)$_POST['note_id'] : 0;
    if ($note_id <= 0) {
        throw new Exception('Invalid note ID');
    }

    $stmt = $pdo->prepare("SELECT id, document_id, user_id FROM document_notes WHERE id = ?");
    $stmt->execute([$note_id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$note) {
        throw new Exception('Note not found');
    }

    $currentUserId = (int)$_SESSION['user_id'];
    if ((int)$note['user_id'] !== $currentUserId && !isAdmin()) {
        http_response_code(403);
        throw new Exception('Permission denied. You can only delete your own notes.');
    }

    $pdo->prepare("DELETE FROM document_notes WHERE id = ?")->execute([$note_id]);

    logActivity($pdo, $currentUserId, 'Delete Document Note',
        "Deleted a note on document ID: {$note['document_id']}");

    echo json_encode(['success' => true, 'message' => 'Note deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
