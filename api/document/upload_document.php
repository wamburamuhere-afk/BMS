<?php
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

    if (!canCreate('documents')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to upload documents');
    }

    if (!isset($_FILES['document_file'])) {
        throw new Exception('No file selected');
    }

    $upload_dir = '../../uploads/documents/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception("Failed to create upload directory");
        }
    }

    $file = $_FILES['document_file'];
    $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'zip', 'rar'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $max_size = 50 * 1024 * 1024; // 50MB

    if (!in_array($file_ext, $allowed_types)) {
        throw new Exception("File type not allowed. Allowed formats: " . implode(', ', $allowed_types));
    }

    if ($file['size'] > $max_size) {
        throw new Exception("File size exceeds 50MB limit");
    }

    $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file['name']);
    $target_dir = __DIR__ . '/../../uploads/documents/';
    $target_path = $target_dir . $filename;
    
    // Path for DB relative to app root
    $db_path = 'uploads/documents/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        throw new Exception("Failed to upload file to storage. Current dir: " . __DIR__);
    }

    // Optional issue / expire dates
    $issue_date  = !empty($_POST['issue_date'])  ? $_POST['issue_date']  : null;
    $expire_date = !empty($_POST['expire_date']) ? $_POST['expire_date'] : null;
    if ($issue_date && $expire_date && $expire_date <= $issue_date) {
        throw new Exception("Expire Date must be later than the Issue Date");
    }

    $stmt = $pdo->prepare("
        INSERT INTO documents (
            document_name, description, file_path, original_filename,
            file_size, file_type, category_id, version, issue_date, expire_date, tags, access_level, uploaded_by, project_id, source
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $_POST['document_name'],
        $_POST['description'] ?? '',
        $db_path,
        $file['name'],
        $file['size'],
        $file_ext,
        !empty($_POST['category_id']) ? $_POST['category_id'] : null,
        $_POST['version'] ?? '1.0',
        $issue_date,
        $expire_date,
        $_POST['tags'] ?? '',
        $_POST['access_level'] ?? 'private',
        $_SESSION['user_id'],
        !empty($_POST['project_id']) ? $_POST['project_id'] : null,
        $_POST['source'] ?? null
    ]);
    
    $document_id = $pdo->lastInsertId();

    // Audit Log for upload
    logAudit($pdo, $_SESSION['user_id'], 'upload_document', [
        'activity_type' => 'upload',
        'description' => "Uploaded document: '{$_POST['document_name']}' ({$file['name']})",
        'entity_type' => 'document',
        'entity_id' => $document_id
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully!',
        'document_id' => $document_id
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
