<?php
/**
 * Simple products API with category and search filters
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Include global configuration and database connection
require_once __DIR__ . '/../../roots.php';

// Security: Check if user is authenticated
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;
    
    if (!$pdo) {
        throw new Exception("Database connection not available.");
    }
    
    // Get parameters
    $category = isset($_GET['category']) ? intval($_GET['category']) : 0;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
    $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
    
    // Build query using product_stocks for accurate warehouse balance
    $ps_warehouse_filter = $warehouse_id > 0 ? "AND ps.warehouse_id = :warehouse_ps" : "";
    $sm_warehouse_filter = $warehouse_id > 0 ? "AND sm.warehouse_id = :warehouse_sm" : "";
    
    $project_stock_subquery = "0";
    if ($project_id > 0) {
        $project_stock_subquery = "(SELECT COALESCE(SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE -quantity END), 0) 
                                    FROM stock_movements sm 
                                    WHERE sm.product_id = p.product_id 
                                    AND sm.project_id = :project_id 
                                    $sm_warehouse_filter)";
    }

    $sql = "SELECT 
                p.product_id,
                p.product_name,
                p.sku,
                p.barcode,
                p.selling_price,
                p.min_selling_price,
                p.tax_rate,
                p.is_taxable,
                COALESCE(SUM(ps.stock_quantity), 0) as total_physical,
                -- General Available: Stock in warehouse NOT reserved for ANY project
                COALESCE(SUM(ps.stock_quantity - IFNULL(ps.reserved_quantity, 0)), 0) as general_available,
                $project_stock_subquery as project_stock,
                p.is_service,
                p.category_id,
                p.image_url
            FROM products p
            LEFT JOIN product_stocks ps ON p.product_id = ps.product_id $ps_warehouse_filter
            WHERE p.status = 'active'";
    
    $params = [];
    if ($warehouse_id > 0) {
        $params[':warehouse_ps'] = $warehouse_id;
        if ($project_id > 0) $params[':warehouse_sm'] = $warehouse_id;
    }
    if ($project_id > 0) $params[':project_id'] = $project_id;
    
    if ($category > 0) {
        $sql .= " AND p.category_id = :category";
        $params[':category'] = $category;
    }
    
    if (!empty($search)) {
        $sql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $sql .= " GROUP BY p.product_id ";
    
    // Sort by project stock first if a project is selected
    if ($project_id > 0) {
        $sql .= " ORDER BY (project_stock > 0) DESC, p.product_name ASC ";
    } else {
        $sql .= " ORDER BY p.product_name LIMIT 100";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process products
    $products = array_map(function($p) use ($project_id) {
        $p['product_id'] = intval($p['product_id']);
        $p['selling_price'] = floatval($p['selling_price']);
        
        $general = floatval($p['general_available']);
        $p_stock = floatval($p['project_stock'] ?? 0);
        
        // Final Available = General Stock + This Project's Reserved Stock
        $p['stock_quantity'] = $general + $p_stock;
        $p['project_stock'] = $p_stock;
        
        $p['is_service'] = (bool)$p['is_service'];
        $p['is_taxable'] = (bool)$p['is_taxable'];
        $p['category_id'] = intval($p['category_id']);
        $p['tax_rate'] = (bool)$p['is_taxable'] ? floatval($p['tax_rate'] ?? 0) : 0;
        
        return $p;
    }, $raw_products);
    
    echo json_encode([
        'success' => true,
        'data' => $products,
        'count' => count($products),
        'filters' => [
            'category' => $category,
            'search' => $search
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
