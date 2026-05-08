<?php
/**
 * Generic Activity Logging API
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$description = $_POST['description'] ?? '';

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Action is required']);
    exit;
}

try {
    require_once HELPERS_FILE;
    logActivity($pdo, $_SESSION['user_id'], $action, $description);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
