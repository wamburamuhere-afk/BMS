<?php
/**
 * use_template.php — records that a document template was picked to start a
 * new letter (from the category/template wizard, new_document.php). Bumps
 * `usage_count` so the "Used N time(s)" badge shown in both the wizard and
 * the "Use Template" picker on create_document.php stays meaningful.
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    if (!canView('document_templates')) {
        http_response_code(403);
        throw new Exception('Access denied');
    }
    csrf_check();

    $template_id = intval($_POST['template_id'] ?? 0);
    if (!$template_id) {
        throw new Exception('Invalid template');
    }

    $stmt = $pdo->prepare("UPDATE document_templates SET usage_count = usage_count + 1 WHERE id = ? AND is_active = 1");
    $stmt->execute([$template_id]);

    logActivity($pdo, $_SESSION['user_id'], "Used document template #$template_id to start a new letter");

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
