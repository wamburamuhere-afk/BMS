<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$loan_id = filter_input(INPUT_POST, 'loan_id', FILTER_VALIDATE_INT);
$document_type = filter_input(INPUT_POST, 'document_type', FILTER_SANITIZE_SPECIAL_CHARS);
$notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_SPECIAL_CHARS);
$uploaded_by = $_SESSION['user_id'] ?? 0;

if (!$loan_id || !$document_type || !isset($_FILES['signed_doc'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$file = $_FILES['signed_doc'];
$allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
$max_size = 5 * 1024 * 1024; // 5MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload error: ' . $file['error']]);
    exit;
}

if (!in_array($file['type'], $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only PDF and images are allowed.']);
    exit;
}

if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit.']);
    exit;
}

// Create directory structure
$upload_dir = __DIR__ . "/../uploads/loans/{$loan_id}/documents/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = "{$document_type}_" . time() . "_" . rand(100, 999) . "." . $extension;
$file_path = "uploads/loans/{$loan_id}/documents/{$filename}";
$full_path = __DIR__ . "/../" . $file_path;

if (move_uploaded_file($file['tmp_name'], $full_path)) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO loan_documents (
                loan_id, document_type, document_name, description, 
                file_path, original_filename, file_size, file_type, 
                document_date, uploaded_by, uploaded_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, NOW())
        ");
        
        $document_name = ucwords(str_replace('_', ' ', $document_type));
        
        $stmt->execute([
            $loan_id,
            $document_type,
            $document_name,
            $notes,
            $file_path,
            $file['name'],
            $file['size'],
            $extension,
            $uploaded_by
        ]);
        
        logActivity($pdo, $uploaded_by, "Uploaded {$document_name} for loan ID: {$loan_id}");
        
        logAudit($pdo, $uploaded_by, 'upload_loan_document', [
            'activity_type' => 'upload',
            'description' => "Uploaded signed document: '{$document_name}' for Loan ID: {$loan_id}",
            'entity_type' => 'loan_document',
            'entity_id' => $pdo->lastInsertId()
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
    } catch (PDOException $e) {
        // If DB fails, try to remove the uploaded file
        if (file_exists($full_path)) unlink($full_path);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
}
