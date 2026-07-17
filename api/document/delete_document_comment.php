<?php
// File: api/document/delete_document_comment.php
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

    $comment_id = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
    if ($comment_id <= 0) {
        throw new Exception('Invalid comment ID');
    }

    $stmt = $pdo->prepare("SELECT id, document_id, user_id FROM document_comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    $comment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$comment) {
        throw new Exception('Comment not found');
    }

    $currentUserId = (int)$_SESSION['user_id'];
    if ((int)$comment['user_id'] !== $currentUserId && !isAdmin()) {
        http_response_code(403);
        throw new Exception('Permission denied. You can only delete your own comments.');
    }

    $pdo->prepare("DELETE FROM document_comments WHERE id = ?")->execute([$comment_id]);

    logActivity($pdo, $currentUserId, 'Delete Document Comment',
        "Deleted a comment on document ID: {$comment['document_id']}");

    echo json_encode(['success' => true, 'message' => 'Comment deleted']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
