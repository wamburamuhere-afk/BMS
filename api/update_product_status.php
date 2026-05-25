<?php
// File: api/update_product_status.php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isAdmin() && !canEdit('products')) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

$product_id = intval($_POST['product_id'] ?? 0);
$status = $_POST['status'] ?? '';

if (!$product_id || !in_array($status, ['active', 'inactive'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('products', 'product_id', $product_id);
}

try {
    $stmt = $pdo->prepare("UPDATE products SET status = ?, updated_at = NOW(), updated_by = ? WHERE product_id = ?");
    $stmt->execute([$status, $_SESSION['user_id'], $product_id]);
    
    // Log Activity
    require_once __DIR__ . '/../helpers.php';
    // Get product name for better logging
    $p_stmt = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
    $p_stmt->execute([$product_id]);
    $p_name = $p_stmt->fetchColumn();
    logActivity($pdo, $_SESSION['user_id'], ucfirst($status === 'active' ? 'Activated' : 'Deactivated') . " product: $p_name");
    
    echo json_encode(['success' => true, 'message' => "Product " . ($status === 'active' ? 'activated' : 'deactivated') . " successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
