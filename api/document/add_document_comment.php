<?php
// File: api/document/add_document_comment.php
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
    if (!canView('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied');
    }

    $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
    $comment     = trim($_POST['comment'] ?? '');

    if ($document_id <= 0 || $comment === '') {
        throw new Exception('A comment is required');
    }

    $stmt = $pdo->prepare("SELECT id, document_name, access_level, uploaded_by FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) {
        throw new Exception('Document not found');
    }

    $currentUserId = (int)$_SESSION['user_id'];
    $isOwner = (int)$document['uploaded_by'] === $currentUserId;
    if ($document['access_level'] !== 'public' && !isAdmin() && !$isOwner) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM document_assignees WHERE document_id = ? AND user_id = ?");
        $chk->execute([$document_id, $currentUserId]);
        if (!$chk->fetchColumn()) {
            http_response_code(403);
            throw new Exception('Access Denied: this document is not shared with you');
        }
    }

    $ins = $pdo->prepare("INSERT INTO document_comments (document_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
    $ins->execute([$document_id, $currentUserId, $comment]);
    $newCommentId = (int)$pdo->lastInsertId();

    logActivity($pdo, $currentUserId, 'Comment on Document',
        "Commented on document \"{$document['document_name']}\" (ID: $document_id)");

    echo json_encode(['success' => true, 'message' => 'Comment added', 'id' => $newCommentId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
