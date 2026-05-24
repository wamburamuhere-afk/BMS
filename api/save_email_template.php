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
    $subject = trim($_POST['subject'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($template_name) || empty($subject) || empty($content)) {
        throw new Exception("Template name, subject, and content are required");
    }

    if ($id) {
        // Update
        $stmt = $pdo->prepare("UPDATE email_templates SET template_name = ?, template_type = ?, subject = ?, content = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$template_name, $template_type, $subject, $content, $is_active, $id]);
        $message = "Template updated successfully";
        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Email Template", "Template: $template_name (ID: $id)");
    } else {
        // Insert
        $stmt = $pdo->prepare("INSERT INTO email_templates (template_name, template_type, subject, content, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$template_name, $template_type, $subject, $content, $is_active]);
        $newId = $pdo->lastInsertId();
        $message = "Template created successfully";
        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Created Email Template", "Template: $template_name (ID: $newId)");
    }

    echo json_encode(['success' => true, 'message' => $message]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
