<?php
// File: api/rfq_quick_add_product.php
require_once __DIR__ . '/../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');

    if (!canCreate('products') && !canEdit('rfq')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to quick-add products for RFQ');
    }

    $product_name = trim($_POST['product_name'] ?? '');
    $unit         = trim($_POST['unit'] ?? 'pcs');
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);

    if (!$product_name) throw new Exception('Product name is required');
    if (!$warehouse_id) throw new Exception('Warehouse is required');
    if (!$unit) $unit = 'pcs';

    // Check if product already exists (case-insensitive)
    $stmt = $pdo->prepare("SELECT product_id FROM products WHERE LOWER(product_name) = LOWER(?) LIMIT 1");
    $stmt->execute([$product_name]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $product_id = (int)$existing;
    } else {
        // Create minimal product record
        $stmt = $pdo->prepare("
            INSERT INTO products
                (product_name, unit, cost_price, selling_price, purchase_price,
                 status, is_service, track_inventory, created_by)
            VALUES (?, ?, 0, 0, 0, 'active', 0, 1, ?)
        ");
        $stmt->execute([$product_name, $unit, $_SESSION['user_id']]);
        $product_id = (int)$pdo->lastInsertId();

        logActivity($pdo, $_SESSION['user_id'], "Auto-created product via RFQ: {$product_name}");
    }

    // Ensure product_stocks entry exists for this warehouse (qty stays 0 — will be received via GRN)
    $pdo->prepare("
        INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity)
        VALUES (?, ?, 0, 0)
        ON DUPLICATE KEY UPDATE product_id = product_id
    ")->execute([$product_id, $warehouse_id]);

    echo json_encode(['success' => true, 'product_id' => $product_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
