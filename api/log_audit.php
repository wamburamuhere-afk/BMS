<?php
/**
 * API: Log Audit Event
 * Used for logging client-side events like printing
 */
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $action = $_POST['action'] ?? '';
    $activity_type = $_POST['activity_type'] ?? 'log';
    $entity_type = $_POST['entity_type'] ?? null;
    $entity_id = isset($_POST['entity_id']) ? intval($_POST['entity_id']) : null;
    $description = $_POST['description'] ?? '';

    if (empty($action)) {
        throw new Exception('Action is required');
    }

    require_once HELPERS_FILE;

    // Call the global helper function (logging to activity_logs as requested)
    $full_description = "[" . strtoupper($activity_type) . "] " . $description;
    if ($entity_type && $entity_id) {
        $full_description .= " ($entity_type ID: $entity_id)";
    }
    
    $result = logActivity($pdo, $_SESSION['user_id'], $action, $full_description);
    
    // logActivity returns void in helpers.php, so we assume success if no exception
    echo json_encode(['success' => true]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
