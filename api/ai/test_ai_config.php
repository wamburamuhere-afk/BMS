<?php
/**
 * api/ai/test_ai_config.php — "Test connection" for the AI Assistant (admin only).
 * Sends a tiny ping to the configured provider and reports success/failure.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/ai_service.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!isAdmin())         { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin only']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

if (!aiConfigured()) {
    echo json_encode(['success' => false, 'message' => 'Enable the assistant and set a model + API key first, then save before testing.']);
    exit;
}

$res = aiComplete([
    ['role' => 'system', 'content' => 'You are a connectivity test. Reply with exactly the word: OK'],
    ['role' => 'user',   'content' => 'ping'],
], ['feature' => 'test', 'max_tokens' => 5, 'temperature' => 0]);

if ($res['ok']) {
    $reply = trim($res['text']);
    echo json_encode(['success' => true, 'message' => 'Provider responded successfully' . ($reply !== '' ? ' ("' . substr($reply, 0, 20) . '")' : '') . '.']);
} else {
    echo json_encode(['success' => false, 'message' => $res['error'] ?: 'The provider did not respond.']);
}
