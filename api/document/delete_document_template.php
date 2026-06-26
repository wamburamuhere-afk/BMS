<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('document_templates')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete document templates']);
    exit;
}

try {
    $id = $_POST['id'] ?? 0;

    if (!$id) {
        throw new Exception('Template ID is required');
    }

    // Optional: Delete physical file if needed
    /*
    $stmt = $pdo->prepare("SELECT file_path FROM document_templates WHERE id = ?");
    $stmt->execute([$id]);
    $file = $stmt->fetchColumn();
    if ($file && file_exists('../' . $file)) {
        unlink('../' . $file);
    }
    */

    // Capture the template name before deleting (clear, human-readable log).
    $tn = $pdo->prepare("SELECT template_name FROM document_templates WHERE id = ?");
    $tn->execute([$id]);
    $tpl_name = $tn->fetchColumn() ?: ('template #' . $id);

    $stmt = $pdo->prepare("DELETE FROM document_templates WHERE id = ?");
    $stmt->execute([$id]);

    // Log the action — audit trail + Activity Log feed (before output).
    logAudit($pdo, $_SESSION['user_id'], 'delete_document_template', [
        'activity_type' => 'delete',
        'description' => "Deleted document template ID: $id",
        'entity_type' => 'document_template',
        'entity_id' => $id
    ]);
    logActivity($pdo, $_SESSION['user_id'], 'Delete document template',
        "deleted document template \"{$tpl_name}\" with id {$id}");

    echo json_encode(['success' => true, 'message' => 'Template deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
