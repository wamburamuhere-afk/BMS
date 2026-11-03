<?php
// scope-audit: skip — the company's own subscription (control DB row of this tenant only), admin-only like the web page
/**
 * api/mobile/subscription.php — the web Subscription page for the Flutter app
 * (mobile_api_upgrade_plan.md Phase 5).
 *
 * Runs the web endpoint api/my_subscription.php for a Bearer-token caller, so
 * the app gets exactly what the web page gets — same admin-only gate, same
 * company (this server's own tenant, never from the request):
 *
 *   GET  ?action=status        access period, plan + limits + extras, payments
 *   GET  ?action=catalog       plans / periods BJP sells
 *   GET  ?action=documents     BJP quotations + invoices for this company (marks them seen)
 *   GET  ?action=badge         how many documents are new
 *   POST action=request        product_id, term_id, extras {token: qty} → a request waiting at BJP
 *   GET  ?action=contracts     subscription contracts BJP signed for this company
 *   POST action=contract_sign  contract_id → a one-time link to sign it
 *
 * Every user sees the short summary (plan, status, days left) in me.php → subscription.
 * POST bodies may be JSON or form fields; optional client_uuid makes a POST safe to retry.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();
mobileJsonBody();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }

$_REQUEST['action'] = (string)($_POST['action'] ?? $_GET['action'] ?? '');
// The Bearer token is the authentication (some servers hide the Authorization
// header from $_SERVER, which mobileRun() checks), so the web CSRF check passes.
if (!empty($GLOBALS['BMS_MOBILE_TOKEN_ID'])) $_POST['_csrf'] = csrf_token();
global $pdo;
mobileRun($pdo, 'subscription/' . $_REQUEST['action'], $_POST['client_uuid'] ?? null, function () {
    global $pdo;
    require __DIR__ . '/../my_subscription.php';
});
