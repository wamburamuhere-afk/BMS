<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $id = $_POST['template_id'] ?? null;
    $name = $_POST['template_name'] ?? '';
    $categoryId = $_POST['category_id'] ?? null;
    $type = $_POST['template_type'] ?? 'uploaded';
    $description = $_POST['description'] ?? '';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $userId = $_SESSION['user_id'] ?? null;

    if (empty($name)) {
        throw new Exception("Template name is required");
    }

    $filePath = null;
    $fileType = null;

    // Handle File Upload
    if (isset($_FILES['template_file']) && $_FILES['template_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = ROOT_DIR . '/docs/templates/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = time() . '_' . basename($_FILES['template_file']['name']);
        $targetPath = $uploadDir . $filename;
        
        if (move_uploaded_file($_FILES['template_file']['tmp_name'], $targetPath)) {
            $filePath = 'docs/templates/' . $filename;
            $fileType = pathinfo($filename, PATHINFO_EXTENSION);
        }
    }

    if (!empty($id)) {
        // Update
        if ($filePath) {
            $stmt = $pdo->prepare("UPDATE document_templates SET template_name = ?, category_id = ?, file_path = ?, file_type = ?, description = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $categoryId, $filePath, $fileType, $description, $isActive, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE document_templates SET template_name = ?, category_id = ?, description = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $categoryId, $description, $isActive, $id]);
        }
        $msg = "Template updated successfully";
    } else {
        // Create
        $stmt = $pdo->prepare("INSERT INTO document_templates (template_name, category_id, file_path, file_type, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $categoryId, $filePath, $fileType, $description, $isActive, $userId]);
        $msg = "Template saved successfully";
    }

    echo json_encode(['success' => true, 'message' => $msg]);

    // Log the action
    logAudit($pdo, $_SESSION['user_id'], !empty($id) ? 'update_document_template' : 'create_document_template', [
        'activity_type' => !empty($id) ? 'update' : 'create',
        'description' => "$msg: $name",
        'entity_type' => 'document_template',
        'entity_id' => $id ?: $pdo->lastInsertId()
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
