<?php
// File: api/create_product.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in and has permission
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Check permission
if (!isAdmin() && !canEdit('products')) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
    exit();
}

$product_id = intval($_POST['product_id']);

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Handle file upload
    $image_url = null;
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/products/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $file_extension = pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
        $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $unique_filename;
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = mime_content_type($_FILES['product_image']['tmp_name']);
        
        if (!in_array($file_type, $allowed_types)) {
            throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.');
        }
        
        // Validate file size (max 2MB)
        if ($_FILES['product_image']['size'] > 2 * 1024 * 1024) {
            throw new Exception('File size too large. Maximum size is 2MB.');
        }
        
        // Move uploaded file
        if (move_uploaded_file($_FILES['product_image']['tmp_name'], $upload_path)) {
            $image_url = 'uploads/products/' . $unique_filename;
            
            // Delete old image if exists
            $old_image_stmt = $pdo->prepare("SELECT image_url FROM products WHERE product_id = ?");
            $old_image_stmt->execute([$product_id]);
            $old_image = $old_image_stmt->fetchColumn();
            if ($old_image && file_exists('../' . $old_image)) {
                unlink('../' . $old_image);
            }
        }
    }
    
    // Get selling price and discount rate first
    $selling_price = floatval($_POST['selling_price']);
    $discount_rate = !empty($_POST['discount_rate']) ? floatval($_POST['discount_rate']) : 0.00;
    
    // Calculate min_selling_price automatically based on discount_rate
    // Formula: Min Selling Price = Selling Price - (Selling Price × Discount Rate / 100)
    $calculated_min_price = $selling_price - ($selling_price * $discount_rate / 100);
    
    // Use calculated value, or manual override if provided
    $min_selling_price = !empty($_POST['min_selling_price']) ? floatval($_POST['min_selling_price']) : $calculated_min_price;
    
    // Combine dimensions
    $dimensions = null;
    if (!empty($_POST['dim_length']) || !empty($_POST['dim_width']) || !empty($_POST['dim_height'])) {
        $l = !empty($_POST['dim_length']) ? $_POST['dim_length'] : '0';
        $w = !empty($_POST['dim_width']) ? $_POST['dim_width'] : '0';
        $h = !empty($_POST['dim_height']) ? $_POST['dim_height'] : '0';
        $dimensions = "{$l}×{$w}×{$h} cm";
    }
    
    // Prepare product data
    $product_data = [
        'product_name' => trim($_POST['product_name']),
        'sku' => !empty($_POST['sku']) ? trim($_POST['sku']) : null,
        'product_code' => !empty($_POST['sku']) ? trim($_POST['sku']) : null,
        'barcode' => !empty($_POST['barcode']) ? trim($_POST['barcode']) : null,
        'description' => !empty($_POST['description']) ? trim($_POST['description']) : null,
        'category_id' => !empty($_POST['category_id']) ? intval($_POST['category_id']) : null,
        'brand_id' => !empty($_POST['brand_id']) ? intval($_POST['brand_id']) : null,
        'supplier_id' => !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null,
        'unit' => $_POST['unit'],
        'weight' => !empty($_POST['weight']) ? floatval($_POST['weight']) : 0.000,
        'dimensions' => $dimensions,
        'cost_price' => floatval($_POST['cost_price']),
        'selling_price' => $selling_price,
        'min_selling_price' => $min_selling_price,
        'wholesale_price' => !empty($_POST['wholesale_price']) ? floatval($_POST['wholesale_price']) : 0.00,
        'tax_id' => !empty($_POST['tax_id']) ? intval($_POST['tax_id']) : null,
        'tax_rate' => 0, 
        'discount_rate' => $discount_rate,
        'reorder_level' => !empty($_POST['reorder_level']) ? floatval($_POST['reorder_level']) : 0.000,
        'min_stock_level' => !empty($_POST['min_stock_level']) ? floatval($_POST['min_stock_level']) : 0.000,
        'max_stock_level' => !empty($_POST['max_stock_level']) ? floatval($_POST['max_stock_level']) : 0.000,
        'status' => $_POST['status'] ?? 'active',
        'is_service' => isset($_POST['is_service']) ? 1 : 0,
        'is_taxable' => isset($_POST['is_taxable']) ? 1 : 0,
        'track_inventory' => isset($_POST['track_inventory']) ? 1 : 0,
        'manufacturer' => !empty($_POST['manufacturer']) ? trim($_POST['manufacturer']) : null,
        'model' => !empty($_POST['model']) ? trim($_POST['model']) : null,
        'serial_number' => !empty($_POST['serial_number']) ? trim($_POST['serial_number']) : null,
        'warranty_period' => !empty($_POST['warranty_period']) ? intval($_POST['warranty_period']) : 0,
        'expiry_days' => !empty($_POST['expiry_days']) ? intval($_POST['expiry_days']) : 0,
        'updated_by' => $user_id,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if ($image_url) {
        $product_data['image_url'] = $image_url;
    }
    
    // Get tax rate if tax_id is provided
    if ($product_data['tax_id']) {
        $stmt = $pdo->prepare("SELECT rate_percentage FROM tax_rates WHERE rate_id = ?");
        $stmt->execute([$product_data['tax_id']]);
        $tax_rate = $stmt->fetchColumn();
        $product_data['tax_rate'] = $tax_rate ? floatval($tax_rate) : 0;
    }
    
    // Check for duplicate SKU
    if ($product_data['sku']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND product_id != ?");
        $stmt->execute([$product_data['sku'], $product_id]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('SKU already exists on another product.');
        }
    }
    
    // Check for duplicate barcode
    if ($product_data['barcode']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE barcode = ? AND product_id != ?");
        $stmt->execute([$product_data['barcode'], $product_id]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Barcode already exists on another product.');
        }
    }
    
    // Update product
    $update_parts = [];
    foreach ($product_data as $key => $value) {
        $update_parts[] = "$key = :$key";
    }
    $update_query = "UPDATE products SET " . implode(', ', $update_parts) . " WHERE product_id = :original_id";
    
    $product_data['original_id'] = $product_id;
    $stmt = $pdo->prepare($update_query);
    $stmt->execute($product_data);
    
    // Commit transaction
    $pdo->commit();

    // Log activity
    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $user_id, "Updated product: " . $product_data['product_name'] . ($product_data['sku'] ? " ({$product_data['sku']})" : ""));
    
    echo json_encode([
        'success' => true,
        'message' => 'Product updated successfully!',
        'product_id' => $product_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Delete uploaded file if transaction failed
    if (!empty($image_url) && file_exists('../' . $image_url)) {
        unlink('../' . $image_url);
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}