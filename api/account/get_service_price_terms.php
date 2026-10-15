<?php
// scope-audit: skip — reads one service's price terms for the Edit form; the service catalog is global
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/service_price_terms.php';
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

    $terms = array_map(fn($t) => [
        'term_id'        => (int)$t['term_id'],
        'term_label'     => $t['term_label'],
        'duration_value' => (int)$t['duration_value'],
        'duration_unit'  => $t['duration_unit'],
        'price'          => (float)$t['price'],
        'description'    => $t['description'],
        'is_default'     => (int)$t['is_default'] === 1,
    ], sptForProduct($pdo, $productId));

    echo json_encode([
        'success'         => true,
        'data'            => $terms,
        'is_subscription' => isPlatformVendorContext() && vssIsSubscription($pdo, $productId),
    ]);
} catch (Throwable $e) {
    error_log('get_service_price_terms: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
