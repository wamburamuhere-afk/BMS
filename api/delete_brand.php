<?php
/**
 * API: Delete Brand
 * Deletes a product brand.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $brand_id = $_POST['brand_id'] ?? null;

    if (!$brand_id) {
        throw new Exception("Brand ID is required");
    }

    $stmt = $pdo->prepare("DELETE FROM brands WHERE brand_id = ?");
    $stmt->execute([$brand_id]);

    echo json_encode([
        'success' => true,
        'message' => "Brand deleted successfully"
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
