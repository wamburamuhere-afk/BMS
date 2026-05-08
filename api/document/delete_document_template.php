<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
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

    $stmt = $pdo->prepare("DELETE FROM document_templates WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Template deleted successfully']);

    // Log the action
    logAudit($pdo, $_SESSION['user_id'], 'delete_document_template', [
        'activity_type' => 'delete',
        'description' => "Deleted document template ID: $id",
        'entity_type' => 'document_template',
        'entity_id' => $id
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
