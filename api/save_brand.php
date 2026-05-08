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
    } else {
        // Create
        $stmt = $pdo->prepare("INSERT INTO brands (brand_name, website, description, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$brand_name, $website, $description, $status]);
        $message = "Brand created successfully";
        $brand_id = $pdo->lastInsertId();
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
