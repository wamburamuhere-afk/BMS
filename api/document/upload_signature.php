<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['signature_file'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    $userId = $_SESSION['user_id'];
    $file = $_FILES['signature_file'];

    // Basic validation
    $allowedTypes = ['image/png', 'image/jpeg', 'image/jpg'];
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only PNG and JPEG are allowed.');
    }

    $userDir = ROOT_DIR . '/uploads/signatures/' . $userId;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0777, true);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'signature_upload_' . uniqid() . '.' . $extension;
    $filepath = $userDir . '/' . $filename;
    $dbPath = '/uploads/signatures/' . $userId . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Save to database
        $stmt = $pdo->prepare("INSERT INTO user_signatures (user_id, signature_type, file_path, status, created_at) VALUES (?, 'uploaded', ?, 'active', NOW())");
        $stmt->execute([$userId, $dbPath]);

        echo json_encode(['success' => true, 'message' => 'Signature uploaded successfully']);
    } else {
        throw new Exception('Failed to move uploaded file');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
