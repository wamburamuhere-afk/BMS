<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $id = $_POST['id'] ?? null;
    if (!$id) {
        throw new Exception("Template ID is required");
    }

    // Optionally delete the physical file too
    $stmt = $pdo->prepare("SELECT file_path FROM document_templates WHERE id = ?");
    $stmt->execute([$id]);
    $filePath = $stmt->fetchColumn();
    
    if ($filePath && file_exists(ROOT_DIR . '/' . $filePath)) {
        unlink(ROOT_DIR . '/' . $filePath);
    }

    $stmt = $pdo->prepare("DELETE FROM document_templates WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['success' => true, 'message' => "Template deleted successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
