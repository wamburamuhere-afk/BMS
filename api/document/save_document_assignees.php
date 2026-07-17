<?php
// File: api/document/save_document_assignees.php
// Replaces the full assignee set for a document. Only the uploader or an
// admin may manage who a private/restricted document is shared with.
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

    $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
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
    if (!$isOwner && !isAdmin()) {
        http_response_code(403);
        throw new Exception('Permission denied. Only the uploader or an admin can manage access.');
    }

    $userIds = $_POST['user_ids'] ?? [];
    if (!is_array($userIds)) {
        $userIds = array_filter(explode(',', (string)$userIds));
    }
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $userIds = array_filter($userIds, fn($id) => $id > 0);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM document_assignees WHERE document_id = ?")->execute([$document_id]);

        if (!empty($userIds)) {
            $ins = $pdo->prepare("INSERT INTO document_assignees (document_id, user_id, assigned_by, assigned_at) VALUES (?, ?, ?, NOW())");
            foreach ($userIds as $uid) {
                $ins->execute([$document_id, $uid, $currentUserId]);
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    logAudit($pdo, $currentUserId, 'document_assignees_updated', [
        'activity_type' => 'document_management',
        'description'   => "Updated access list for document \"{$document['document_name']}\" (ID: $document_id)",
        'entity_type'   => 'document',
        'entity_id'     => $document_id,
        'new_values'    => ['assignee_ids' => $userIds],
    ]);
    logActivity($pdo, $currentUserId, 'Update Document Access',
        "Updated who can see document \"{$document['document_name']}\" (ID: $document_id)");

    echo json_encode(['success' => true, 'message' => 'Access list updated', 'assignee_ids' => array_values($userIds)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
