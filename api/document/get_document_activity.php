<?php
// File: api/document/get_document_activity.php
// Feeds includes/document_activity_modal.php — one round trip returning the
// document's comments, notes, and assignee list, plus what the current user
// is allowed to do with them.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/document_access.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized');
    }
    if (!canView('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied');
    }

    $document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
    if ($document_id <= 0) {
        throw new Exception('Invalid document ID');
    }

    $stmt = $pdo->prepare("SELECT id, document_name, access_level, uploaded_by FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) {
        throw new Exception('Document not found');
    }

    $currentUserId = (int)$_SESSION['user_id'];
    $isOwner = (int)$document['uploaded_by'] === $currentUserId;

    // Defense in depth: the row only appears in the caller's list once
    // get_documents.php's own visibility filter already applies, but never
    // trust the client — re-check here too. Shared with document_library.php's
    // download/view gate (core/document_access.php) so the rule can't drift.
    if (!userCanAccessDocument($pdo, $document_id, $document)) {
        http_response_code(403);
        throw new Exception('Access Denied: this document is not shared with you');
    }

    $stmtC = $pdo->prepare("
        SELECT dc.id, dc.user_id, dc.comment, dc.created_at, dc.updated_at,
               TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS user_name,
               u.username
        FROM document_comments dc
        LEFT JOIN users u ON dc.user_id = u.user_id
        WHERE dc.document_id = ?
        ORDER BY dc.created_at ASC
    ");
    $stmtC->execute([$document_id]);
    $comments = array_map(function ($row) use ($currentUserId) {
        $row['user_name']  = trim($row['user_name']) ?: $row['username'];
        $row['can_delete'] = (int)$row['user_id'] === $currentUserId || isAdmin();
        unset($row['username']);
        return $row;
    }, $stmtC->fetchAll(PDO::FETCH_ASSOC));

    $stmtN = $pdo->prepare("
        SELECT dn.id, dn.user_id, dn.note, dn.created_at, dn.updated_at,
               TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS user_name,
               u.username
        FROM document_notes dn
        LEFT JOIN users u ON dn.user_id = u.user_id
        WHERE dn.document_id = ?
        ORDER BY dn.created_at ASC
    ");
    $stmtN->execute([$document_id]);
    $notes = array_map(function ($row) use ($currentUserId) {
        $row['user_name']  = trim($row['user_name']) ?: $row['username'];
        $row['can_delete'] = (int)$row['user_id'] === $currentUserId || isAdmin();
        unset($row['username']);
        return $row;
    }, $stmtN->fetchAll(PDO::FETCH_ASSOC));

    $stmtA = $pdo->prepare("SELECT user_id FROM document_assignees WHERE document_id = ?");
    $stmtA->execute([$document_id]);
    $assigneeIds = array_map('intval', $stmtA->fetchAll(PDO::FETCH_COLUMN));

    echo json_encode([
        'success'          => true,
        'document'         => $document,
        'comments'         => $comments,
        'notes'            => $notes,
        'assignee_ids'     => $assigneeIds,
        'can_manage_access' => (isAdmin() || $isOwner),
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
