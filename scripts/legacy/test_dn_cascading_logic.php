<?php
/**
 * Test Suite: Delivery Note (GRN) Workflow Validation
 * This script verifies the logic for Cascading Filters and Partial Delivery Tracking.
 */
require_once __DIR__ . '/roots.php';

echo "=== DN WORKFLOW VALIDATION START ===\n\n";

global $pdo;

// 1. Validate PO Status Filtering
echo "1. Checking for eligible Purchase Orders (Approved/Partial)...\n";
$eligible_statuses = ['approved', 'ordered', 'partially_received', 'received', 'completed'];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE status IN (".implode(',', array_fill(0, count($eligible_statuses), '?')).")");
$stmt->execute($eligible_statuses);
$po_count = $stmt->fetchColumn();
echo "   Found $po_count eligible POs for GRN creation.\n";

if ($po_count == 0) {
    echo "   [WARNING] No eligible POs found in the database. Test cannot proceed fully.\n";
} else {
    // 2. Validate Warehouse/Supplier Linkage (The Cascade)
    echo "2. Validating Warehouse -> Supplier -> PO Relationship...\n";
    $stmt = $pdo->query("
        SELECT po.purchase_order_id, po.order_number, po.warehouse_id, po.supplier_id, s.supplier_name, w.warehouse_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.supplier_id
        JOIN warehouses w ON po.warehouse_id = w.warehouse_id
        WHERE po.status IN ('approved', 'ordered', 'partially_received')
        LIMIT 5
    ");
    $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pos as $po) {
        echo "   - PO {$po['order_number']}: Correctly linked to Warehouse [{$po['warehouse_name']}] and Supplier [{$po['supplier_name']}].\n";
    }

    // 3. Validate Remaining Quantity Logic
    echo "3. Testing 'Remaining Quantity' Math for a random PO...\n";
    $test_po = $pos[0]['purchase_order_id'];
    $stmt = $pdo->prepare("
        SELECT 
            poi.product_id, 
            p.product_name, 
            poi.quantity as ordered_qty,
            COALESCE((
                SELECT SUM(di.quantity_delivered) 
                FROM delivery_items di 
                JOIN deliveries d ON di.delivery_id = d.delivery_id 
                WHERE d.purchase_order_id = poi.purchase_order_id 
                AND di.product_id = poi.product_id
                AND d.status != 'cancelled'
            ), 0) as delivered_qty
        FROM purchase_order_items poi
        JOIN products p ON poi.product_id = p.product_id
        WHERE poi.purchase_order_id = ?
        LIMIT 3
    ");
    $stmt->execute([$test_po]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        $remaining = $item['ordered_qty'] - $item['delivered_qty'];
        echo "   - [{$item['product_name']}]: Ordered={$item['ordered_qty']}, Delivered={$item['delivered_qty']}, Remaining=$remaining\n";
        if ($remaining < 0) {
            echo "     [ERROR] Negative balance detected! Check delivery records.\n";
        } else {
            echo "     [PASS] Math is consistent.\n";
        }
    }
}

// 4. Validate Attachment Logic (Schema Check)
echo "4. Validating Attachment Table Schema...\n";
$stmt = $pdo->query("DESC delivery_attachments");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
$required_cols = ['attachment_id', 'delivery_id', 'file_name', 'file_path'];
$missing = array_diff($required_cols, $cols);

if (empty($missing)) {
    echo "   [PASS] All required attachment columns present.\n";
} else {
    echo "   [FAIL] Missing columns: " . implode(', ', $missing) . "\n";
}

echo "\n=== DN WORKFLOW VALIDATION COMPLETE ===\n";
