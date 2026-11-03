<?php
/**
 * actions/superadmin_tenant_restaurant_shops.php — READ-ONLY list of ONE
 * tenant's active shops already set to Restaurant/Hybrid POS Mode.
 *
 * Called by the superadmin "Point of Sale — More" dialog only when the
 * operator switches Restaurant POS from off to on, so they see which shops
 * will start behaving as restaurant shops (restaurant_plan.md Phase 0.1).
 * Thin wrapper around core/tenant_admin.php::tenantRestaurantModeShops();
 * never writes.
 *
 * Same guard order as every other superadmin action: host, session, POST, CSRF.
 *
 * POST {tenant_id} -> {success, shops: [{warehouse_name, pos_mode}]}
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();

$tenantId = (int)($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

$shops = tenantRestaurantModeShops($tenantId);
if ($shops === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Could not read this tenant\'s shops right now.']);
    exit;
}

echo json_encode(['success' => true, 'shops' => $shops]);
