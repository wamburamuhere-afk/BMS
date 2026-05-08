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
        echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'File not found']);
}
