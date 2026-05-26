<?php
// scope-audit: skip — bulk import utility; product catalog is global
/**
 * API: Import Products
 * Processes CSV file and imports products.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canCreate('products')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to import products']);
    exit();
}

try {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload failed");
    }

    $file = $_FILES['file']['tmp_name'];
    $handle = fopen($file, 'r');
    $headers = fgetcsv($handle);
    
    // Normalize headers
    $headers = array_map('strtolower', $headers);
    
    $imported = 0;
    $updated = 0;
    $errors = [];

    $pdo->beginTransaction();

    while (($row = fgetcsv($handle)) !== false) {
        $data = array_combine($headers, $row);
        
        $sku = $data['sku'] ?? '';
        $name = $data['product_name'] ?? '';
        $cost = floatval($data['cost_price'] ?? 0);
        $price = floatval($data['selling_price'] ?? 0);
        $stock = floatval($data['stock_quantity'] ?? 0);
        
        if (empty($sku) || empty($name)) continue;

        // Check if product exists
        $stmt = $pdo->prepare("SELECT product_id FROM products WHERE sku = ?");
        $stmt->execute([$sku]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Update
            $stmt = $pdo->prepare("UPDATE products SET product_name = ?, cost_price = ?, selling_price = ?, stock_quantity = ? WHERE product_id = ?");
            $stmt->execute([$name, $cost, $price, $stock, $existing['product_id']]);
            $updated++;
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO products (sku, product_name, cost_price, selling_price, stock_quantity, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$sku, $name, $cost, $price, $stock]);
            $imported++;
        }
    }

    $pdo->commit();
    fclose($handle);

    // Log Activity
    logActivity($pdo, $_SESSION['user_id'], "Imported Products: $imported new, $updated updated");

    echo json_encode([
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'message' => "Import completed: $imported new, $updated updated"
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
