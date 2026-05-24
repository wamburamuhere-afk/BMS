<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $id = $_POST['id'] ?? null;

    if (!$id) {
        throw new Exception("Template ID is required");
    }

    $stmt = $pdo->prepare("DELETE FROM email_templates WHERE id = ?");
    $stmt->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Email Template", "Template ID: $id");

    echo json_encode(['success' => true, 'message' => "Template deleted successfully"]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
