<?php
/**
 * API: Select2 AJAX product search for the POS "Restock Product" modal.
 * GET ?q=<term>
 * Unlike the sale-time product search, this is NOT limited to a warehouse's
 * current stock — restocking a product that has zero (or no) stock in this
 * shop yet is exactly the normal case. Services are excluded (nothing to
 * restock).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if (!isAuthenticated())        { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!hasPermission('adjust_stock') && !isAdmin()) { http_response_code(403); echo json_encode(['results' => []]); exit; }

global $pdo;

$q = trim($_GET['q'] ?? '');

try {
    $sql = "SELECT product_id, product_name, sku
              FROM products
             WHERE status != 'deleted' AND is_service != 1";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (product_name LIKE ? OR sku LIKE ? OR barcode LIKE ?)";
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
    }
    $sql .= " ORDER BY product_name ASC LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $text = $r['sku'] ? ($r['sku'] . ' — ' . $r['product_name']) : $r['product_name'];
        $results[] = ['id' => (int)$r['product_id'], 'text' => $text];
    }
    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('pos/search_products_for_restock error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
