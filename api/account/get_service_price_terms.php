<?php
// scope-audit: skip — reads one service's price terms for the Edit form; the service catalog is global
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/service_price_terms.php';
require_once __DIR__ . '/../../core/service_plans.php';
require_once __DIR__ . '/../../core/vendor_context.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('products')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$productId = (int)($_GET['product_id'] ?? 0);
if ($productId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid service']);
    exit;
}

try {
    global $pdo;
    $st = $pdo->prepare("SELECT product_id FROM products WHERE product_id = ? AND is_service = 1 AND status <> 'deleted'");
    $st->execute([$productId]);
    if (!$st->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Service not found']);
        exit;
    }

    $termShape = fn($t) => [
        'term_id'        => (int)$t['term_id'],
        'term_label'     => $t['term_label'],
        'plan_id'        => $t['plan_id'] ?? null,
        'display_label'  => $t['display_label'] ?? $t['term_label'],
        'duration_value' => (int)$t['duration_value'],
        'duration_unit'  => $t['duration_unit'],
        'price'          => (float)$t['price'],
        'description'    => $t['description'],
        'is_default'     => (int)$t['is_default'] === 1,
    ];
    $terms = array_map($termShape, sptForProduct($pdo, $productId));

    // Plans (core/service_plans.php): `data` above stays the flat list every line picker reads.
    $plans = array_map(fn($p) => [
        'plan_id'    => $p['plan_id'],
        'plan_name'  => $p['plan_name'],
        'tagline'    => $p['tagline'],
        'is_popular' => $p['is_popular'],
        'items'      => $p['items'],
        'terms'      => array_map($termShape, $p['terms']),
    ], spPlansForProduct($pdo, $productId));

    $vendor = isPlatformVendorContext();
    $platformKey = $vendor ? vssPlatformProduct($pdo, $productId) : null;

    echo json_encode([
        'success'         => true,
        'data'            => $terms,
        'plans'           => $plans,
        'is_subscription' => $vendor && vssIsSubscription($pdo, $productId),
        'contract_template_id' => $vendor ? vssContractTemplate($pdo, $productId) : null,
        // A mapped service: its plans are Superadmin's, read live and shown read-only.
        'platform_product_key' => $platformKey,
        'platform_plans'  => $platformKey !== null ? spPlatformPlans($platformKey) : [],
    ]);
} catch (Throwable $e) {
    error_log('get_service_price_terms: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
