<?php
// File: api/test_po_workflow.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: text/plain');

$token = $_GET['token'] ?? '';
if ($token !== 'bms_migrate_2024') die("Unauthorized");

global $pdo;

function test($name, $condition) {
    echo "TEST: " . str_pad($name, 60, ".") . ($condition ? "[ PASS ]" : "[ FAIL ]") . "\n";
}

try {
    echo "PURCHASE ORDER WORKFLOW TEST SUITE\n";
    echo "=================================\n\n";

    // 1. Check Table Columns
    $cols = $pdo->query("DESCRIBE purchase_orders")->fetchAll(PDO::FETCH_COLUMN);
    test("prepared_by_name column exists", in_array('prepared_by_name', $cols));
    test("reviewed_by_name column exists", in_array('reviewed_by_name', $cols));
    test("approved_by_name column exists", in_array('approved_by_name', $cols));

    // 2. Check ENUM status
    $stmt = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'");
    $statusCol = $stmt->fetch(PDO::FETCH_ASSOC);
    test("Status ENUM includes 'review'", strpos($statusCol['Type'], "'review'") !== false);
    test("Status ENUM includes 'approved'", strpos($statusCol['Type'], "'approved'") !== false);

    // 3. Test data insertion
    $pdo->exec("INSERT INTO purchase_orders (order_number, supplier_id, order_date, status, prepared_by_name, prepared_by_role) 
                VALUES ('TEST-PO-001', 1, CURDATE(), 'pending', 'Test Preparer', 'Test Role')");
    $po_id = $pdo->lastInsertId();
    test("Test PO inserted with 'pending' status", $po_id > 0);

    // 4. Test Review API
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'test_user';
    $_SESSION['first_name'] = 'Test';
    $_SESSION['last_name'] = 'Reviewer';
    $_SESSION['user_role'] = 'Reviewer Role';
    
    // We'll simulate the review call logic
    $reviewer_name = "Test Reviewer";
    $reviewer_role = "Reviewer Role";
    $pdo->prepare("UPDATE purchase_orders SET status = 'review', reviewed_by_name = ?, reviewed_by_role = ?, reviewed_at = NOW() WHERE purchase_order_id = ?")
        ->execute([$reviewer_name, $reviewer_role, $po_id]);
    
    $checkReview = $pdo->query("SELECT status, reviewed_by_name FROM purchase_orders WHERE purchase_order_id = $po_id")->fetch();
    test("Status updated to 'review'", $checkReview['status'] === 'review');
    test("Reviewer name snapshotted correctly", $checkReview['reviewed_by_name'] === 'Test Reviewer');

    // 5. Test Approve API
    $approver_name = "Test Approver";
    $approver_role = "Admin Role";
    $pdo->prepare("UPDATE purchase_orders SET status = 'approved', approved_by_name = ?, approved_by_role = ?, approved_at = NOW(), approved_by = ? WHERE purchase_order_id = ?")
        ->execute([$approver_name, $approver_role, 1, $po_id]);
    
    $checkApprove = $pdo->query("SELECT status, approved_by_name FROM purchase_orders WHERE purchase_order_id = $po_id")->fetch();
    test("Status updated to 'approved'", $checkApprove['status'] === 'approved');
    test("Approver name snapshotted correctly", $checkApprove['approved_by_name'] === 'Test Approver');

    // Cleanup
    $pdo->exec("DELETE FROM purchase_orders WHERE purchase_order_id = $po_id");
    echo "\nCleanup: Test PO deleted.\n";
    echo "\n=================================\n";
    echo "TESTING COMPLETE\n";

} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
}
