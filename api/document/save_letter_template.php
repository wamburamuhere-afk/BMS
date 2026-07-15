<?php
/**
 * save_letter_template.php — saves the Create Document editor's current
 * body as a reusable template (document_templates.content), so future
 * letters can start from it via the "Use Template" picker instead of a
 * blank page every time.
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
    if (!canCreate('document_templates')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to create document templates');
    }
    csrf_check();

    $template_name = trim((string)($_POST['template_name'] ?? ''));
    $category_id   = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $content       = (string)($_POST['content'] ?? '');
    // Structural fields — captured with any {{tokens}} intact (NOT resolved),
    // so the template reproduces the whole letter, not just its body, and its
    // tokens auto-fill afresh each time it's reused.
    $subject           = trim((string)($_POST['subject'] ?? ''));
    $recipient         = trim((string)($_POST['recipient'] ?? ''));
    $recipient_address = trim((string)($_POST['recipient_address'] ?? ''));
    $use_letterhead    = isset($_POST['use_letterhead']) ? (($_POST['use_letterhead'] === '1') ? 1 : 0) : null;
    $signature_align   = in_array(($_POST['signature_align'] ?? ''), ['left', 'center', 'right'], true)
        ? $_POST['signature_align'] : null;

    if ($template_name === '') {
        throw new Exception('Template name is required');
    }
    if (trim(strip_tags($content)) === '') {
        throw new Exception('The letter body cannot be empty');
    }

    $stmt = $pdo->prepare("
        INSERT INTO document_templates
            (template_name, category_id, content, subject, recipient, recipient_address,
             use_letterhead, signature_align, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");
    $stmt->execute([
        $template_name, $category_id, $content,
        $subject !== '' ? $subject : null,
        $recipient !== '' ? $recipient : null,
        $recipient_address !== '' ? $recipient_address : null,
        $use_letterhead, $signature_align,
        $_SESSION['user_id'],
    ]);
    $template_id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Saved letter template: '$template_name'");

    echo json_encode(['success' => true, 'message' => 'Template saved.', 'template_id' => $template_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
