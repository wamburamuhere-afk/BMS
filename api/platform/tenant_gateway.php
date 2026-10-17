<?php
// Signed server-to-server entry for a company's own system asking about its BMS
// subscription (customer_subscription_plan.md Phase 5). Platform root only; the
// logic and every check live in core/platform_gateway.php::pgHandle().
// scope-audit: skip — answers only for the customer linked to the signed tenant id
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/platform_gateway.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
global $pdo;
[$code, $out] = pgHandle($pdo, (string)file_get_contents('php://input'), [
    'X-Platform-Tenant' => $_SERVER['HTTP_X_PLATFORM_TENANT'] ?? '',
    'X-Platform-Ts'     => $_SERVER['HTTP_X_PLATFORM_TS'] ?? '',
    'X-Platform-Sig'    => $_SERVER['HTTP_X_PLATFORM_SIG'] ?? '',
]);
http_response_code($code);
echo json_encode($out);
