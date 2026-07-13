<?php
/**
 * get_letter_templates.php — lists content-based templates for the "Use
 * Template" picker on create_document.php. Only rows with a populated
 * `content` column qualify — the existing file-based templates (uploaded
 * PDFs like loan agreements) have content = NULL and never appear here.
 */
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) {
        throw new Exception('Unauthorized');
    }
    if (!canView('document_templates')) {
        http_response_code(403);
        throw new Exception('Access denied');
    }

    $stmt = $pdo->query("
        SELECT dt.id, dt.template_name, dt.content, dt.usage_count, tc.category_name
        FROM document_templates dt
        LEFT JOIN template_categories tc ON tc.id = dt.category_id
        WHERE dt.content IS NOT NULL AND dt.is_active = 1
        ORDER BY dt.template_name ASC
    ");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'templates' => $templates]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
