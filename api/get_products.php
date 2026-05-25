<?php
// File: api/get_products.php (POS version)
// Turn off error reporting for output to prevent HTML errors in JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
ob_clean(); // Clean any previous output (whitespace/errors)
// Get request parameters for POS
$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$barcode = isset($_GET['barcode']) ? trim($_GET['barcode']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$in_stock = isset($_GET['in_stock']) ? filter_var($_GET['in_stock'], FILTER_VALIDATE_BOOLEAN) : true;

try {
    // Build query for POS (simplified)
    $query = "
        SELECT 
            p.product_id,
            p.product_name,
            p.sku,
            p.barcode,
            p.description,
            p.unit,
            p.selling_price,
            p.tax_rate,
            p.image_url,
            p.status,
            
            p.stock_quantity,
            
            0 as reserved_quantity
            
        FROM products p
        WHERE p.status = 'active'
    ";

    // Phase D — restrict to products in scope (global products with project_id=NULL always pass through)
    if (isset($_SESSION['user_id']) && function_exists('scopeFilterSqlNullable')) {
        $query .= scopeFilterSqlNullable('project', 'p');
    }
    
    $params = [];
    
    // Apply category filter
    if ($category_id > 0 && $category_id !== 'all') {
        $query .= " AND p.category_id = :category_id";
        $params[':category_id'] = $category_id;
    }
    
    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (
            p.product_name LIKE :search OR 
            p.sku LIKE :search OR 
            p.barcode LIKE :search
        )";
        $params[':search'] = "%$search%";
    }
    
    // Apply barcode filter
    if (!empty($barcode)) {
        $query .= " AND p.barcode = :barcode";
        $params[':barcode'] = $barcode;
    }

    // Apply ID filter
    if (isset($_GET['id'])) {
        $query .= " AND p.product_id = :id";
        $params[':id'] = intval($_GET['id']);
    }
    
    // Group by product
    $query .= " GROUP BY p.product_id";
    
    // Apply stock filter
    if ($in_stock) {
        $query .= " HAVING stock_quantity > 0";
    }
    
    // Order by name
    $query .= " ORDER BY p.product_name ASC";
    
    // Apply limit
    $query .= " LIMIT :limit";
    $params[':limit'] = $limit;
    
    // Execute query
    $stmt = $pdo->prepare($query);
    
    // Bind parameters
    foreach ($params as $key => $value) {
        if ($key === ':limit') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process results for POS
    $pos_products = [];
    foreach ($products as $product) {
        $stock_qty = floatval($product['stock_quantity']);
        $reserved_qty = floatval($product['reserved_quantity']);
        $available_qty = $stock_qty - $reserved_qty;
        
        $pos_products[] = [
            'product_id' => intval($product['product_id']),
            'product_name' => $product['product_name'],
            'sku' => $product['sku'],
            'barcode' => $product['barcode'],
            'description' => substr($product['description'] ?? '', 0, 100),
            'unit' => $product['unit'] ?: 'pcs',
            'price' => floatval($product['selling_price']),
            'tax_rate' => floatval($product['tax_rate']),
            'stock_quantity' => $stock_qty,
            'available_quantity' => $available_qty,
            'image_url' => $product['image_url'],
            'stock_status' => $available_qty <= 0 ? 'out_of_stock' : 
                             ($available_qty <= 10 ? 'low_stock' : 'in_stock')
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $pos_products,
        'count' => count($pos_products),
        'filters_applied' => [
            'category' => $category_id,
            'search' => $search,
            'in_stock' => $in_stock
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("POS Products Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load products',
        'error' => $e->getMessage()
    ]);
}