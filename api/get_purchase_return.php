<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!canView('purchase_returns')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    // Fetch Header with Joined Data
    $stmt = $pdo->prepare("
        SELECT 
            pr.*,
            s.supplier_name,
            s.company_name,
            s.contact_person,
            s.phone as supplier_phone,
            s.email as supplier_email,
            s.address as supplier_address,
            grn.receipt_number,
            u.username as created_by_name,
            w.warehouse_name,
            pr.warehouse_id,
            pr.receipt_id
        FROM purchase_returns pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_receipts grn ON pr.receipt_id = grn.receipt_id
        LEFT JOIN warehouses w ON pr.warehouse_id = w.warehouse_id
        LEFT JOIN users u ON pr.created_by = u.user_id
        WHERE pr.purchase_return_id = ?
    ");
    $stmt->execute([$id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$return) {
        throw new Exception("Return not found");
    }

    // Fetch Items with product names if available
    $itemStmt = $pdo->prepare("
        SELECT pri.*, p.unit, p.sku 
        FROM purchase_return_items pri 
        LEFT JOIN products p ON pri.product_id = p.product_id 
        WHERE pri.purchase_return_id = ?
    ");
    $itemStmt->execute([$id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $return['items'] = $items;

    echo json_encode(['success' => true, 'data' => $return]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
