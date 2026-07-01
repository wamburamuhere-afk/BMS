<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized');
    }

    if (!canDelete('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete documents');
    }

    $document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;

    if ($document_id <= 0) {
        throw new Exception('Invalid document ID');
    }

    // Fetch document details (name included so the log is human-readable)
    $stmt = $pdo->prepare("SELECT file_path, document_name, original_filename, uploaded_by FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        throw new Exception('Document not found');
    }

    // Check permissions - Admin can delete any file, others can only delete their own
    $isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'Admin';
    $isOwner = $document['uploaded_by'] == $_SESSION['user_id'];

    if (!$isAdmin && !$isOwner) {
        throw new Exception('Permission denied. You can only delete your own documents.');
    }

    // Start transaction
    $pdo->beginTransaction();

    try {
        // Delete related records first
        $pdo->prepare("DELETE FROM document_downloads WHERE document_id = ?")->execute([$document_id]);
        
        // Delete the document record
        $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$document_id]);

        // Commit transaction
        $pdo->commit();

        // Log the deletion — audit trail + Activity Log feed.
        $doc_name = $document['document_name'] ?: ($document['original_filename'] ?: ('document #' . $document_id));
        logAudit($pdo, $_SESSION['user_id'], 'delete_document', [
            'activity_type' => 'document_management',
            'description' => "Deleted document ID: $document_id",
            'entity_type' => 'document',
            'entity_id' => $document_id
        ]);
        logActivity($pdo, $_SESSION['user_id'], 'Delete document',
            "deleted document \"{$doc_name}\" with id {$document_id}");

        // Delete physical file after successful database deletion
        if (!empty($document['file_path']) && file_exists($document['file_path'])) {
            @unlink($document['file_path']); // Use @ to suppress warnings if file doesn't exist
        }

        echo json_encode([
            'success' => true,
            'message' => 'Document deleted successfully'
        ]);

    } catch (Exception $e) {
        // Rollback on error
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
