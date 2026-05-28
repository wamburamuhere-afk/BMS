<?php
// File: api/sales/create_return.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canCreate('sales_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to create sales returns']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

global $pdo;

$order_id = intval($_POST['sales_order_id'] ?? 0);
$customer_id = intval($_POST['customer_id'] ?? 0);
$return_date = $_POST['return_date'] ?? date('Y-m-d');
$reason = $_POST['reason'] ?? '';
$items = $_POST['items'] ?? [];

if ($order_id <= 0 || empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid order or no items selected']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Calculate Totals
    $subtotal = 0;
    $return_items_data = [];

    foreach ($items as $product_id => $qty) {
        $qty = floatval($qty);
        if ($qty <= 0) continue;

        // Verify with original order item to get price
        $stmtItem = $pdo->prepare("SELECT unit_price FROM sales_order_items WHERE order_id = ? AND product_id = ?");
        $stmtItem->execute([$order_id, $product_id]);
        $originalItem = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if (!$originalItem) {
             // Try fallback column sales_order_id
            $stmtItem = $pdo->prepare("SELECT unit_price FROM sales_order_items WHERE sales_order_id = ? AND product_id = ?");
            try {
                $stmtItem->execute([$order_id, $product_id]);
                $originalItem = $stmtItem->fetch(PDO::FETCH_ASSOC);
            } catch(Exception $e) {}
        }


        if (!$originalItem) continue;

        $price = floatval($originalItem['unit_price']);
        $line_total = $qty * $price;
        $subtotal += $line_total;

        $return_items_data[] = [
            'product_id' => $product_id,
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $line_total
        ];
    }

    if (empty($return_items_data)) {
        throw new Exception("No valid items to return");
    }

    // 2. Insert Return Header
    // Schema: sales_return_id (PK), return_number, sales_order_id, customer_id, return_date, total_amount, grand_total, reason, status, created_by
    $return_number = 'RET-' . date('Ymd') . '-' . rand(1000, 9999);
    $grand_total = $subtotal; 

    $stmt = $pdo->prepare("
        INSERT INTO sales_returns (
            return_number, sales_order_id, customer_id, return_date, 
            total_amount, grand_total, reason, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    
    $stmt->execute([
        $return_number, $order_id, $customer_id, $return_date, 
        $subtotal, $grand_total, $reason, $_SESSION['user_id']
    ]);
    
    $return_id = $pdo->lastInsertId();

    // ── e-signature capture (Created By) ─ returns three-approval slice
    if (!function_exists('workflowCaptureSignature')) {
        require_once __DIR__ . '/../../core/workflow.php';
    }
    $wfActor = workflowActorSnapshot();
    workflowCaptureSignature(
        $pdo, 'sales_return', (int)$return_id, 'created',
        (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
    );

    // 3. Insert Return Items
    // Schema: return_item_id, sales_return_id, product_id, quantity, unit_price, total_amount
    $stmtItemInsert = $pdo->prepare("
        INSERT INTO sales_return_items (
            sales_return_id, product_id, quantity, unit_price, total_amount, reason
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($return_items_data as $item) {
        $stmtItemInsert->execute([
            $return_id,
            $item['product_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['line_total'],
            $reason
        ]);
    }

    require_once __DIR__ . '/../../helpers.php';
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Create Sales Return', "$user_name created Sales Return #$return_number (Total: " . number_format($grand_total, 2) . ")");

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Return created successfully', 'return_id' => $return_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
