<?php
// File: api/sales/update_return.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('sales_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit sales returns']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

global $pdo;

$return_id = intval($_POST['return_id'] ?? 0);
$sales_order_id = intval($_POST['sales_order_id'] ?? 0);
$return_date = $_POST['return_date'] ?? date('Y-m-d');
$reason = $_POST['reason'] ?? '';
$items = $_POST['items'] ?? []; // Key is product_id, value is qty

if ($return_id <= 0 || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid return ID or no items']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Verify status is pending
    $stmt = $pdo->prepare("SELECT status FROM sales_returns WHERE sales_return_id = ?");
    $stmt->execute([$return_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current || $current['status'] !== 'pending') {
        throw new Exception("Only pending returns can be edited");
    }

    // 1. Clear existing items
    $pdo->prepare("DELETE FROM sales_return_items WHERE sales_return_id = ?")->execute([$return_id]);

    // 2. Re-insert items and calculate total
    $subtotal = 0;
    $stmtItemInsert = $pdo->prepare("
        INSERT INTO sales_return_items (
            sales_return_id, product_id, quantity, unit_price, total_amount, reason
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($items as $product_id => $qty) {
        $qty = floatval($qty);
        if ($qty <= 0) continue;

        // Fetch price from original order
        $stmtItem = $pdo->prepare("SELECT unit_price FROM sales_order_items WHERE order_id = ? AND product_id = ?");
        $stmtItem->execute([$sales_order_id, $product_id]);
        $row = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
             // Try fallback column
             $stmtItem = $pdo->prepare("SELECT unit_price FROM sales_order_items WHERE sales_order_id = ? AND product_id = ?");
             $stmtItem->execute([$sales_order_id, $product_id]);
             $row = $stmtItem->fetch(PDO::FETCH_ASSOC);
        }

        if ($row) {
            $price = floatval($row['unit_price']);
            $line_total = $qty * $price;
            $subtotal += $line_total;

            $stmtItemInsert->execute([
                $return_id,
                $product_id,
                $qty,
                $price,
                $line_total,
                $reason
            ]);
        }
    }

    if ($subtotal <= 0) {
        throw new Exception("At least one item must have a quantity greater than 0");
    }

    $grand_total = $subtotal;

    // 3. Update Main Record
    $updateReturn = $pdo->prepare("
        UPDATE sales_returns 
        SET return_date = ?, reason = ?, total_amount = ?, grand_total = ? 
        WHERE sales_return_id = ?
    ");
    $updateReturn->execute([$return_date, $reason, $grand_total, $grand_total, $return_id]);

    // 4. Log Activity
    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    
    $stmt_num = $pdo->prepare("SELECT return_number FROM sales_returns WHERE sales_return_id = ?");
    $stmt_num->execute([$return_id]);
    $return_num = $stmt_num->fetchColumn();
    
    logActivity($pdo, $_SESSION['user_id'], 'Edit Sales Return', "$user_name updated Sales Return #$return_num (Total: " . number_format($grand_total, 2) . ")");

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Return updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
