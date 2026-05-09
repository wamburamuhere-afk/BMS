<?php
// File: api/test_dn_workflow.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: text/plain');

$token = $_GET['token'] ?? '';
if ($token !== 'bms_test_2024' && !isAuthenticated()) die("Unauthorized");

global $pdo;

function testLog($msg) {
    echo "[" . date('H:i:s') . "] $msg\n";
}

try {
    testLog("Starting Delivery Note (DN) Workflow Test...");

    // 1. Setup Test Data
    $supplier_row = $pdo->query("SELECT supplier_id, project_id FROM suppliers WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $supplier = $supplier_row['supplier_id'] ?? null;
    $project = $supplier_row['project_id'] ?? 0;
    
    $warehouse = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' " . ($project ? "AND (project_id = $project OR project_id = 0)" : "") . " LIMIT 1")->fetchColumn();
    $product = $pdo->query("SELECT product_id FROM products WHERE is_service = 0 LIMIT 1")->fetchColumn();
    $user = $pdo->query("SELECT user_id FROM users WHERE is_active = 1 LIMIT 1")->fetchColumn();

    if (!$warehouse || !$supplier || !$product || !$user) {
        throw new Exception("Missing required test data (warehouse, supplier, non-service product, or active user).");
    }

    testLog("Using Warehouse ID: $warehouse, Supplier ID: $supplier, Product ID: $product, User ID: $user");

    // Mock session for authentication
    $_SESSION['user_id'] = $user;
    $_SESSION['role'] = 'admin'; // Assume admin for permissions check if needed

    // 2. Create a Dummy PO for reference
    $po_number = "TEST-PO-" . time();
    $pdo->prepare("INSERT INTO purchase_orders (order_number, supplier_id, warehouse_id, project_id, status) VALUES (?, ?, ?, ?, 'approved')")
        ->execute([$po_number, $supplier, $warehouse, $project]);
    $po_id = $pdo->lastInsertId();
    testLog("Created Test PO ID: $po_id (#$po_number)");

    // 3. Test DN Creation
    testLog("Testing DN Creation (POST to api/create_dn.php)...");
    
    $_POST = [
        'project_id' => $project,
        'warehouse_id' => $warehouse,
        'supplier_id' => $supplier,
        'delivery_date' => date('Y-m-d'),
        'purchase_order_id' => $po_id,
        'do_id' => 999, // Dummy DO ID
        'status' => 'draft',
        'items' => json_encode([
            ['product_id' => $product, 'quantity' => 10, 'unit' => 'pcs']
        ])
    ];

    // Mock session for user_id
    $user_id = $_SESSION['user_id'];

    // Capture output of create_dn.php
    ob_start();
    include __DIR__ . '/create_dn.php';
    $result_json = ob_get_clean();
    $result = json_decode($result_json, true);

    if (!$result || !$result['success']) {
        throw new Exception("DN Creation Failed: " . ($result['message'] ?? 'Unknown error') . "\nFull Result: " . $result_json);
    }

    $delivery_id = $result['delivery_id'];
    testLog("DN Created Successfully! ID: $delivery_id");

    // 4. Verify Database Record
    $dn_stmt = $pdo->prepare("SELECT * FROM deliveries WHERE delivery_id = ?");
    $dn_stmt->execute([$delivery_id]);
    $row = $dn_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception("DN Record not found in database.");
    
    if ($row['purchase_order_id'] != $po_id) throw new Exception("Data Mismatch: purchase_order_id expected $po_id, got " . $row['purchase_order_id']);
    if ($row['do_id'] != 999) throw new Exception("Data Mismatch: do_id expected 999, got " . $row['do_id']);
    
    testLog("Database verification PASSED (PO ID and DO ID correctly stored).");

    // 5. Test DN Update
    testLog("Testing DN Update (POST to api/update_dn.php)...");
    
    $_POST = [
        'delivery_id' => $delivery_id,
        'project_id' => $project,
        'warehouse_id' => $warehouse,
        'supplier_id' => $supplier,
        'delivery_date' => date('Y-m-d'),
        'purchase_order_id' => $po_id,
        'do_id' => 888, // Updated Dummy DO ID
        'items' => json_encode([
            ['product_id' => $product, 'quantity' => 15, 'unit' => 'pcs']
        ])
    ];

    ob_start();
    include __DIR__ . '/update_dn.php';
    $update_json = ob_get_clean();
    $update_res = json_decode($update_json, true);

    if (!$update_res || !$update_res['success']) {
        throw new Exception("DN Update Failed: " . ($update_res['message'] ?? 'Unknown error'));
    }

    testLog("DN Updated Successfully!");

    // 6. Final Database verification
    $dn_stmt->execute([$delivery_id]);
    $row = $dn_stmt->fetch(PDO::FETCH_ASSOC);
    if ($row['do_id'] != 888) throw new Exception("Update Data Mismatch: do_id expected 888, got " . $row['do_id']);
    
    testLog("Final verification PASSED.");

    // 7. Cleanup
    $pdo->prepare("DELETE FROM delivery_items WHERE delivery_id = ?")->execute([$delivery_id]);
    $pdo->prepare("DELETE FROM deliveries WHERE delivery_id = ?")->execute([$delivery_id]);
    $pdo->prepare("DELETE FROM purchase_orders WHERE purchase_order_id = ?")->execute([$po_id]);
    testLog("Cleanup completed.");

    echo "\n🏆 ALL TESTS PASSED SUCCESSFULLY!\n";

} catch (Exception $e) {
    echo "\n❌ TEST FAILED: " . $e->getMessage() . "\n";
    if (isset($delivery_id)) {
        // Partial cleanup
    }
}
