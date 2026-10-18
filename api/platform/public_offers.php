<?php
/**
 * api/platform/public_offers.php — the plans a new company can sign up on.
 *
 * GET, public, read-only (service_plans_plan.md Phase 3). Used by the pricing
 * popup on login.php and by the mobile app before api/mobile/register.php.
 *
 * Root install only: a tenant's own address answers 404, like registration.
 * Lists only active, public plans with a price, of products shown on
 * registration. No internal ids — a plan is named by plan_key and a price by
 * term_key ("1m", "1y"), which is all register accepts, re-checked there.
 *
 * Response: { success, currency:"TZS", products:[{product_key, name, description,
 *            plans:[{plan_key, name, tagline, description, is_popular, tier_rank,
 *            max_users, max_shops, max_storage_mb, modules[], items[],
 *            prices:[{term_key, term_label, months, price_tzs, per_month, is_default}],
 *            addons[]}]}] }   — products is [] when nothing is on offer.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/tenant_registration.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

try {
    $products = selfRegistrationOpen() ? listPublicOffers(null, true) : [];
} catch (Throwable $e) {
    error_log('public_offers: ' . $e->getMessage());
    $products = [];
}

// Prices change rarely; a short cache spares the control DB on busy pages.
header('Cache-Control: public, max-age=60');
echo json_encode(['success' => true, 'currency' => 'TZS', 'products' => $products]);
