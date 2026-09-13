<?php
/**
 * API: prefill values for the POS "Restock Product" modal.
 * GET: product_id, warehouse_id
 * Returns the most recent batch's buying/wholesale/retail price for this
 * product in this warehouse (what we paid/charged last time); falls back to
 * the product's own purchase_price / Wholesale price-group override / plain
 * selling_price when the product has never had a batch here before.
 * Permission: adjust_stock (same gate quick_restock.php itself uses).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/pos_price_groups.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())        { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!hasPermission('adjust_stock') && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Permission denied')]);
    exit;
}

global $pdo;

$product_id   = (int)($_GET['product_id'] ?? 0);
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);

if ($product_id <= 0 || $warehouse_id <= 0) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}
if (!userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

try {
    $prod = $pdo->prepare("SELECT product_name, purchase_price, selling_price, wholesale_price FROM products WHERE product_id = ? AND status != 'deleted'");
    $prod->execute([$product_id]);
    $product = $prod->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        echo json_encode(['success' => false, 'message' => t('Product not found')]);
        exit;
    }

    $batch = $pdo->prepare("
        SELECT unit_cost, wholesale_price, selling_price
        FROM product_batches
        WHERE product_id = ? AND warehouse_id = ?
        ORDER BY batch_id DESC
        LIMIT 1
    ");
    $batch->execute([$product_id, $warehouse_id]);
    $lastBatch = $batch->fetch(PDO::FETCH_ASSOC);

    $wholesaleGroupId = wholesalePriceGroupId($pdo);
    $wholesaleOverride = null;
    if ($wholesaleGroupId) {
        $wg = $pdo->prepare("SELECT price FROM product_price_group_prices WHERE product_id = ? AND price_group_id = ?");
        $wg->execute([$product_id, $wholesaleGroupId]);
        $val = $wg->fetchColumn();
        if ($val !== false) $wholesaleOverride = (float)$val;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'product_name'    => $product['product_name'],
            'buying_price'    => $lastBatch ? (float)$lastBatch['unit_cost'] : (float)$product['purchase_price'],
            'wholesale_price' => $lastBatch && $lastBatch['wholesale_price'] !== null
                ? (float)$lastBatch['wholesale_price']
                : ($wholesaleOverride ?? (float)$product['wholesale_price']),
            'selling_price'   => $lastBatch && $lastBatch['selling_price'] !== null
                ? (float)$lastBatch['selling_price']
                : (float)$product['selling_price'],
            'has_prior_batch' => (bool)$lastBatch,
        ],
    ]);
} catch (PDOException $e) {
    error_log('get_restock_defaults error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
