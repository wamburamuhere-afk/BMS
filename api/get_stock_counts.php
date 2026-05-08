<?php
/**
 * API: Get Stock Counts
 * Returns counts for out-of-stock and low-stock items.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    // 1. Get out of stock count (stock <= 0)
    $stmt_out = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= 0 AND status = 'active'");
    $out_of_stock = $stmt_out->fetchColumn();

    // 2. Get low stock count (stock > 0 AND stock <= reorder_level)
    $stmt_low = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity > 0 AND stock_quantity <= reorder_level AND status = 'active'");
    $low_stock = $stmt_low->fetchColumn();

    echo json_encode([
        'success' => true,
        'data' => [
            'out_of_stock' => (int)$out_of_stock,
            'low_stock' => (int)$low_stock
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch stock counts',
        'error' => $e->getMessage()
    ]);
}
