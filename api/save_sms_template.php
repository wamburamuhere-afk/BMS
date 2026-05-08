<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $id = $_POST['id'] ?? null;
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
    } else {
        // Insert
        $stmt = $pdo->prepare("INSERT INTO sms_templates (template_name, template_type, message_content, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$template_name, $template_type, $content, $is_active, $user_id]);
        $message = "SMS Template created successfully";
    }

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
