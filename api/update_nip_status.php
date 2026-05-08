<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

try {
    if (!isset($_SESSION['user_id'])) throw new Exception('Unauthorized access');
    $user_id    = intval($_SESSION['user_id']);
    $product_id = intval($_POST['product_id'] ?? 0);
    $status     = trim($_POST['status'] ?? '');

    if (!$product_id) throw new Exception('Product ID is required.');
    $allowed = ['active', 'inactive', 'draft', 'pending', 'approved'];
    if (!in_array($status, $allowed)) throw new Exception('Invalid status value.');

    $stmt = $pdo->prepare("SELECT product_id, product_name FROM products WHERE product_id = ? AND is_service = 1");
    $stmt->execute([$product_id]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prod) throw new Exception('NIP product not found.');

    $pdo->prepare("UPDATE products SET status = ? WHERE product_id = ?")->execute([$status, $product_id]);

    logActivity($pdo, $user_id, "Changed NIP product \"{$prod['product_name']}\" status to {$status}");

    echo json_encode(['success' => true, 'message' => "Status updated to \"" . ucfirst($status) . "\".", 'status' => $status]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
