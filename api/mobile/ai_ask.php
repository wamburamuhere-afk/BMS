<?php
/**
 * api/mobile/ai_ask.php — "Ask Smart POS / Ask BMS" for the Flutter app
 * (mobile_api_upgrade_plan.md Phase 9).
 *
 * POST question → { success, answer, used[] }
 *
 * Runs the web endpoint api/ai/ask.php for a Bearer-token caller, so the app
 * gets the same answers with the same limits: the AI Assistant module and the
 * user's permission, the company's (or BJP's) key and monthly cap, the rate
 * limit, answers only from what this user may open, in their language.
 * Show the chat only when me.php → ai.ready is true; a person can always be
 * reached through api/mobile/support.php (action=create).
 * Optional client_uuid makes a retry return the first answer instead of asking again.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();
mobileJsonBody();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
// The Bearer token is the authentication, so the web CSRF check passes.
if (!empty($GLOBALS['BMS_MOBILE_TOKEN_ID'])) $_POST['_csrf'] = csrf_token();

global $pdo;
mobileRun($pdo, 'ai/ask', $_POST['client_uuid'] ?? null, function () {
    global $pdo;
    require __DIR__ . '/../ai/ask.php';
});
