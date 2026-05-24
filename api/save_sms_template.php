<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    if (!isAuthenticated()) throw new Exception('Unauthorized');

    $id = $_POST['id'] ?? null;

    if (!empty($id) ? !canEdit('sms_alerts') : !canCreate('sms_alerts')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to ' . (!empty($id) ? 'edit' : 'create') . ' SMS templates');
    }

    $template_name = trim($_POST['template_name'] ?? '');
    $template_type = trim($_POST['template_type'] ?? 'general');
    $content = trim($_POST['content'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $user_id = $_SESSION['user_id'] ?? 0;

    if (empty($template_name) || empty($content)) {
        throw new Exception("Template name and content are required");
    }

    if ($id) {
        // Update
        $stmt = $pdo->prepare("UPDATE sms_templates SET template_name = ?, template_type = ?, message_content = ?, is_active = ? WHERE template_id = ?");
        $stmt->execute([$template_name, $template_type, $content, $is_active, $id]);
        $message = "SMS Template updated successfully";
        logActivity($pdo, $user_id, "Updated SMS Template", "Template: $template_name (ID: $id)");
    } else {
        // Insert
        $stmt = $pdo->prepare("INSERT INTO sms_templates (template_name, template_type, message_content, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$template_name, $template_type, $content, $is_active, $user_id]);
        $newId = $pdo->lastInsertId();
        $message = "SMS Template created successfully";
        logActivity($pdo, $user_id, "Created SMS Template", "Template: $template_name (ID: $newId)");
    }

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
