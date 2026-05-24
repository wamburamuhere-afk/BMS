<?php
/**
 * API: Save Brand
 * Creates or updates a product brand.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $brand_id = $_POST['brand_id'] ?? null;

    if (!empty($brand_id) ? !canEdit('products') : !canCreate('products')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to ' . (!empty($brand_id) ? 'edit' : 'create') . ' brands');
    }
    $brand_name = trim($_POST['brand_name'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'active';

    if (empty($brand_name)) {
        throw new Exception("Brand name is required");
    }

    if ($brand_id) {
        // Update
        $stmt = $pdo->prepare("UPDATE brands SET brand_name = ?, website = ?, description = ?, status = ? WHERE brand_id = ?");
        $stmt->execute([$brand_name, $website, $description, $status, $brand_id]);
        $message = "Brand updated successfully";
        logActivity($pdo, $_SESSION['user_id'], "Updated Brand", "Brand: $brand_name (ID: $brand_id)");
    } else {
        // Create
        $stmt = $pdo->prepare("INSERT INTO brands (brand_name, website, description, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$brand_name, $website, $description, $status]);
        $brand_id = $pdo->lastInsertId();
        $message = "Brand created successfully";
        logActivity($pdo, $_SESSION['user_id'], "Created Brand", "Brand: $brand_name (ID: $brand_id)");
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'brand_id' => $brand_id
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
