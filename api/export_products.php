<?php
// scope-audit: skip — product catalog export; product catalog is global
/**
 * API: Export Products
 * Generates a CSV file of products for Excel/Download.
 */
require_once __DIR__ . '/../roots.php';

// Check permissions (basic check for now)
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

try {
    // Prepare headers for download
    $filename = "products_export_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, ['SKU', 'Product Name', 'Category', 'Brand', 'Cost Price', 'Selling Price', 'Stock Quantity', 'Unit', 'Status']);

    // Fetch products joining with categories and brands
    $query = "
        SELECT 
            p.sku, 
            p.product_name, 
            COALESCE(c.category_name, 'N/A') as category_name, 
            COALESCE(b.brand_name, 'N/A') as brand_name, 
            p.cost_price, 
            p.selling_price, 
            COALESCE((SELECT SUM(stock_quantity) FROM product_stocks WHERE product_id = p.product_id), 0) as stock_quantity, 
            p.unit, 
            p.status
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        LEFT JOIN brands b ON p.brand_id = b.brand_id
        GROUP BY p.product_id
        ORDER BY p.product_name ASC
    ";

    $stmt = $pdo->query($query);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();

} catch (PDOException $e) {
    die("Export Failed: " . $e->getMessage());
}
