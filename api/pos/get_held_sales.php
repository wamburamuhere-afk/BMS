<?php
// scope-audit: skip — POS held sales helper; POS scope deferred to Phase G-2
/**
 * API: Get Held Sales for POS
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    global $pdo;
    
    $user_id = $_SESSION['user_id'];
    
    // Get held sales for current user
    $stmt = $pdo->prepare("
        SELECT 
            h.*,
            c.customer_name
        FROM pos_held_sales h
        LEFT JOIN customers c ON h.customer_id = c.customer_id
        WHERE h.user_id = ?
        AND h.status = 'held'
        ORDER BY h.held_at DESC
        LIMIT 20
    ");
    
    $stmt->execute([$user_id]);
    $held_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $held_sales
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
