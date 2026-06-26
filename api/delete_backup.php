<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

// Check permissions
/*if (!has_permission('manage_settings')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}*/

if (!isset($_POST['filename'])) {
    echo json_encode(['success' => false, 'message' => 'Filename not specified']);
    exit;
}

$filename = basename($_POST['filename']);
$filePath = ROOT_DIR . '/backups/' . $filename;

if (file_exists($filePath)) {
    if (unlink($filePath)) {
        // Record on the Activity Log so backup deletions are never silent.
        if (function_exists('logActivity') && isset($_SESSION['user_id'])) {
            logActivity($pdo, $_SESSION['user_id'], 'Delete backup',
                "deleted backup file \"{$filename}\"");
        }
        echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'File not found']);
}
