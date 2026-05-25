<?php
/**
 * API: save_goods_return.php
 * Saves a Goods Return Note linked to a project.
 */
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canCreate('projects')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to create goods returns']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$user_id    = intval($_SESSION['user_id']);
$project_id = intval($_POST['project_id'] ?? 0);

// Phase B (scope) — block writes against projects not in user scope
if ($project_id > 0 && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
    exit;
}

$return_number = trim($_POST['return_number'] ?? '');
$return_date   = trim($_POST['return_date'] ?? date('Y-m-d'));
$supplier_id   = intval($_POST['supplier_id'] ?? 0);
$warehouse_id  = intval($_POST['warehouse_id'] ?? 0);
$receipt_id    = intval($_POST['receipt_id'] ?? 0);
$return_reason = trim($_POST['return_reason'] ?? '');
$reason_details = trim($_POST['reason_details'] ?? '');
$notes         = trim($_POST['notes'] ?? '');
$items_json    = $_POST['items_json'] ?? '[]';

if (empty($return_number) || $supplier_id <= 0 || $warehouse_id <= 0 || empty($return_reason)) {
    echo json_encode(['success' => false, 'message' => 'Please fill all required fields, including warehouse.']);
    exit;
}

$items = json_decode($items_json, true) ?: [];
if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Please add at least one item.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Fetch purchase_order_id from receipt if available
    $purchase_order_id = null;
    if ($receipt_id > 0) {
        $stmt_po = $pdo->prepare("SELECT purchase_order_id FROM purchase_receipts WHERE receipt_id = ?");
        $stmt_po->execute([$receipt_id]);
        $purchase_order_id = $stmt_po->fetchColumn();
    }

    // Calculate total amount
    $total_amount = 0;
    foreach ($items as $item) {
        $total_amount += floatval($item['total'] ?? 0);
    }

    $stmt = $pdo->prepare("
        INSERT INTO purchase_returns 
            (return_number, return_date, supplier_id, warehouse_id, purchase_order_id, receipt_id, project_id, reason, reason_details, notes, total_amount, created_by, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $return_number, $return_date, $supplier_id, $warehouse_id, $purchase_order_id, $receipt_id, $project_id, 
        $return_reason, $reason_details, $notes, $total_amount, $user_id
    ]);
    $return_id = $pdo->lastInsertId();

    // Insert items
    $itemStmt = $pdo->prepare("
        INSERT INTO purchase_return_items (
            purchase_return_id, product_id, product_name, sku, 
            quantity, unit, unit_price, line_total
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($items as $item) {
        $itemStmt->execute([
            $return_id,
            intval($item['product_id'] ?? 0),
            trim($item['item_name']),
            trim($item['sku'] ?? ''),
            floatval($item['quantity']),
            trim($item['unit'] ?? 'pcs'),
            floatval($item['unit_price'] ?? 0),
            floatval($item['total'] ?? 0)
        ]);
    }

    $pdo->commit();

    // Phase 3c — goods returns affect stock + supplier balances.
    logActivity($pdo, $_SESSION['user_id'], "Created Project Goods Return", "Return ID: $return_id, number: $return_number");

    echo json_encode([
        'success'   => true,
        'message'   => 'Goods return note ' . $return_number . ' saved successfully.',
        'return_id' => $return_id
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
