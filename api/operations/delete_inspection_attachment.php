<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$att_id = intval($_POST['id'] ?? 0);
if (!$att_id) {
    echo json_encode(['success' => false, 'message' => 'Attachment ID is required']);
    exit();
}

try {
    $row = $pdo->prepare("SELECT id, inspection_id, file_name FROM inspection_attachments WHERE id = ?");
    $row->execute([$att_id]);
    $att = $row->fetch(PDO::FETCH_ASSOC);

    if (!$att) {
        echo json_encode(['success' => false, 'message' => 'Attachment not found']);
        exit();
    }

    // Delete physical file
    $file_path = ROOT_DIR . '/uploads/inspections/' . $att['inspection_id'] . '/' . $att['file_name'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }

    $pdo->prepare("DELETE FROM inspection_attachments WHERE id = ?")->execute([$att_id]);

    echo json_encode(['success' => true, 'message' => 'Attachment deleted successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
