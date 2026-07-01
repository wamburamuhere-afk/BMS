<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    if (!isAuthenticated()) throw new Exception('Unauthorized');

    if (!canDelete('sms_alerts')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete SMS templates');
    }

    $id = $_POST['id'] ?? null;

    if (!$id) {
        throw new Exception("Template ID is required");
    }

    $stmt = $pdo->prepare("DELETE FROM sms_templates WHERE template_id = ?");
    $stmt->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Delete sms template", "deleted SMS template with id $id");

    echo json_encode(['success' => true, 'message' => "SMS Template deleted successfully"]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
