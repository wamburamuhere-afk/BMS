<?php
// File: api/account/get_purchase_order_details.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Order ID']);
    exit;
}

try {
    global $pdo;

    // Fetch PO Header
    $stmt = $pdo->prepare("
        SELECT 
            po.*,
            s.supplier_name, s.company_name, s.email as supplier_email, s.phone as supplier_phone, s.address as supplier_address,
            p.project_name,
            w.warehouse_name,
            u.username as created_by_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        LEFT JOIN projects p ON po.project_id = p.project_id
        LEFT JOIN warehouses w ON po.warehouse_id = w.warehouse_id
        LEFT JOIN users u ON po.created_by = u.user_id
        WHERE po.purchase_order_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    // Fetch PO Items
    $stmtItems = $pdo->prepare("
        SELECT 
            poi.*,
            p.product_name, p.sku, p.unit
        FROM purchase_order_items poi
        LEFT JOIN products p ON poi.product_id = p.product_id
        WHERE poi.purchase_order_id = ?
    ");
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Tax Info if needed (optional optimization)
    // For now, we assume tax_rate_id in items is enough or we join tax_rates table.
    // Let's enhance item data with tax name if possible
    
    foreach ($items as &$item) {
        if (!empty($item['tax_rate_id'])) {
            $taxStmt = $pdo->prepare("SELECT rate_name, rate_percentage FROM tax_rates WHERE rate_id = ?");
            $taxStmt->execute([$item['tax_rate_id']]);
            $tax = $taxStmt->fetch(PDO::FETCH_ASSOC);
            if ($tax) {
                $item['tax_name'] = $tax['rate_name'];
                $item['tax_percent'] = $tax['rate_percentage'];
            }
        }
    }

    // Fetch Attachments
    $stmtAtt = $pdo->prepare("SELECT * FROM purchase_order_attachments WHERE purchase_order_id = ?");
    $stmtAtt->execute([$order_id]);
    $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'data' => [
            'order' => $order,
            'items' => $items,
            'attachments' => $attachments
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
