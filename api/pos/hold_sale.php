<?php
/**
 * API: Hold POS Sale
 * Save current cart for later retrieval
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
// Respect the caller's saved language preference (set by header.php on their
// last page load) so t()-wrapped messages below come back in the right
// language, not always English.
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}


if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit();
}

if (!canCreate('pos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access Denied: you do not have permission to hold POS sales')]);
    exit();
}

try {
    global $pdo;

    // The client sends this as a raw JSON body (contentType: 'application/json'),
    // which PHP never populates $_POST for — every field below was silently null/0
    // regardless of what was actually sent, not just 'items'.
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $user_id = $_SESSION['user_id'];
    $customer_id = $body['customer_id'] ?? null;
    $reference = $body['reference'] ?? null;
    $items = $body['items'] ?? [];
    $subtotal = floatval($body['subtotal'] ?? 0);
    $tax = floatval($body['tax'] ?? 0);
    // Phase 30 (pos_upgrade_plan.md §9) — table-addressable holds. Both
    // optional/additive: a plain retail hold that never sends them behaves
    // exactly as before.
    $warehouse_id = !empty($body['warehouse_id']) ? (int)$body['warehouse_id'] : null;
    $table_id = !empty($body['table_id']) ? (int)$body['table_id'] : null;
    if ($table_id) {
        require_once __DIR__ . '/../../core/project_scope.php';
        if (!$warehouse_id || !userCan('warehouse', $warehouse_id)) {
            throw new Exception(t('Access denied: this warehouse is not in your assigned scope.'));
        }
        $tblChk = $pdo->prepare("SELECT 1 FROM restaurant_tables WHERE table_id = ? AND warehouse_id = ?");
        $tblChk->execute([$table_id, $warehouse_id]);
        if (!$tblChk->fetchColumn()) {
            throw new Exception(t('The selected table does not belong to this warehouse.'));
        }
    }

    if (empty($items)) {
        throw new Exception("No items to hold");
    }
    
    // Get active shift — pos_held_sales.shift_id is NOT NULL, so this must be
    // validated before the insert, not silently passed through as null.
    $stmt = $pdo->prepare("SELECT shift_id FROM cash_register_shifts WHERE user_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$user_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    $shift_id = $shift['shift_id'] ?? null;
    if (!$shift_id) {
        throw new Exception("Please start a cash register shift before holding a sale.");
    }

    // Generate hold reference
    $hold_reference = $reference ?: 'HOLD-' . date('Ymd-His');
    
    // Insert held sale
    $stmt = $pdo->prepare("
        INSERT INTO pos_held_sales (
            user_id, shift_id, customer_id, warehouse_id, table_id, hold_reference,
            items_data, subtotal, tax_amount, total_amount, held_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $total = $subtotal + $tax;

    $stmt->execute([
        $user_id,
        $shift_id,
        $customer_id,
        $warehouse_id,
        $table_id,
        $hold_reference,
        json_encode($items),
        $subtotal,
        $tax,
        $total
    ]);

    // Phase 30 — opening/loading a table's order occupies it immediately, not
    // only once the bill is finally closed, so a floor-plan view reflects
    // reality the moment a server starts taking an order.
    if ($table_id) {
        $pdo->prepare("UPDATE restaurant_tables SET status = 'occupied', updated_at = NOW() WHERE table_id = ?")
            ->execute([$table_id]);
    }

    require_once __DIR__ . '/../../helpers.php';
    $username = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $user_id, 'Hold POS Sale', "$username held a POS sale (Ref: $hold_reference, Total: " . number_format($total, 2) . ")");

    echo json_encode([
        'success' => true,
        'message' => t('Sale held successfully'),
        'hold_id' => $pdo->lastInsertId(),
        'reference' => $hold_reference
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
