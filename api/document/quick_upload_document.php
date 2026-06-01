<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Unauthorized');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!canCreate('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to upload documents');
    }

    if (!isset($_FILES['document_file'])) {
        throw new Exception('No file selected');
    }

    // Absolute, __DIR__-based path so the physical save ALWAYS lands in the
    // web-root uploads/documents/ — the same path stored in the DB and used by
    // the preview URL. A relative path ('../uploads/...') resolves against the
    // runtime working directory, which on the production front-controller is
    // NOT the web root, so the file was being saved to the wrong folder while
    // the DB/URL pointed to uploads/documents/ -> "Missing PDF" on preview.
    $upload_dir = __DIR__ . '/../../uploads/documents/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception("Failed to create upload directory");
        }
    }

    $file = $_FILES['document_file'];
    $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'bmp'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $max_size = 50 * 1024 * 1024; // 50MB

    if (!in_array($file_ext, $allowed_types)) {
        throw new Exception("Invalid file type. Allowed formats: PDF, Word documents (DOC, DOCX), and images (JPG, PNG, GIF, BMP).");
    }

    if ($file['size'] > $max_size) {
        throw new Exception("File size exceeds 50MB limit");
    }

    $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file['name']);
    $target_path = $upload_dir . $filename;
    
    // Convert to relative path for database
    $db_path = 'uploads/documents/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        throw new Exception("Failed to upload file to storage");
    }

    $stmt = $pdo->prepare("
        INSERT INTO documents (
            document_name, description, file_path, original_filename, 
            file_size, file_type, category_id, version, uploaded_by, access_level
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $_POST['document_name'],
        $_POST['description'] ?? '',
        $db_path,
        $file['name'],
        $file['size'],
        $file_ext,
        !empty($_POST['category_id']) ? $_POST['category_id'] : null,
        '1.0',
        $_SESSION['user_id'],
        'private'
    ]);

    echo json_encode([
        'success' => true,
        'document_id' => $pdo->lastInsertId(),
        'document_name' => $_POST['document_name'],
        'file_path' => $db_path
    ]);

    // Log the action
    logAudit($pdo, $_SESSION['user_id'], 'upload_document', [
        'activity_type' => 'upload',
        'description' => "Uploaded document (Quick): '{$_POST['document_name']}'",
        'entity_type' => 'document',
        'entity_id' => $pdo->lastInsertId()
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
