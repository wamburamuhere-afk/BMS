<?php
/**
 * API: Hold POS Sale
 * Save current cart for later retrieval
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();
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

// The client sends this as a raw JSON body (contentType: 'application/json'),
// which PHP never populates $_POST for — every field below was silently null/0
// regardless of what was actually sent, not just 'items'.
$body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// restaurant_order_flow_plan.md — a waiter (Restaurant Orders create, no POS sell right) may save a
// TABLE's order; every other hold still needs canCreate('pos'), exactly as before.
require_once __DIR__ . '/../../core/restaurant_order_flow.php';
$__holdAllowed = canCreate('pos') || (!empty($body['table_id']) && rofCanTake($pdo));
if (!$__holdAllowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access Denied: you do not have permission to hold POS sales', 'Access Denied: you do not have permission to hold sales')]);
    exit();
}

try {
    global $pdo;

    if (isset($body['items']) && is_string($body['items'])) {
        $body['items'] = json_decode($body['items'], true) ?: [];
    }

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
            throw new Exception(wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.', true));
        }
        $tblChk = $pdo->prepare("SELECT 1 FROM restaurant_tables WHERE table_id = ? AND warehouse_id = ?");
        $tblChk->execute([$table_id, $warehouse_id]);
        if (!$tblChk->fetchColumn()) {
            throw new Exception(wLabel('The selected table does not belong to this warehouse.', 'The selected table does not belong to this shop.', true));
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
        // Restaurant simplicity pass — saving a TABLE's order moves no money, so a waiter
        // needs no cash shift for it (paying still does). Only a table order on an active
        // restaurant shop, and only once shift_id is nullable; every other hold — all
        // retail holds included — keeps the rule below exactly as before.
        $tableOrderNoShift = false;
        if ($table_id) {
            require_once __DIR__ . '/../../core/restaurant_scope.php';
            $shiftCol = $pdo->query("SHOW COLUMNS FROM pos_held_sales LIKE 'shift_id'")->fetch(PDO::FETCH_ASSOC);
            $tableOrderNoShift = $shiftCol && strtoupper((string)$shiftCol['Null']) === 'YES'
                && restaurantShopActive($pdo, $warehouse_id);
        }
        if (!$tableOrderNoShift) {
            throw new Exception("Please start a cash register shift before holding a sale.");
        }
    }

    // Generate hold reference
    $hold_reference = $reference ?: 'HOLD-' . date('Ymd-His');

    // restaurant_plan.md Phase 2 — ONE open order per table. A table's new
    // held order replaces its previous one, so the Tables screen and the till
    // always load the current order (before, every save/Send to Kitchen added
    // another 'held' row and stale ones piled up). Table orders only — a plain
    // retail hold (no table_id) is never touched.
    if ($table_id) {
        $pdo->beginTransaction(); // replace + insert + occupy succeed or fail together

        // Online orders (restaurant_online_menu_plan.md): staff may have accepted a
        // customer's order onto this table AFTER this till loaded it. Saving that
        // older copy must not silently drop the customer's lines — put back any
        // accepted online line the incoming cart does not have. A till that loaded
        // the current order (same hold_id) is trusted: its edits are deliberate.
        require_once __DIR__ . '/../../core/restaurant_online.php';
        if (function_exists('roSchemaReady') && roSchemaReady($pdo)) {
            $loaded = !empty($body['loaded_hold_id']) ? (int)$body['loaded_hold_id'] : null;
            $cur = $pdo->prepare("SELECT hold_id, items_data FROM pos_held_sales WHERE table_id = ? AND status = 'held' ORDER BY held_at DESC, hold_id DESC LIMIT 1 FOR UPDATE");
            $cur->execute([$table_id]);
            $curRow = $cur->fetch(PDO::FETCH_ASSOC);
            if ($curRow && (int)$curRow['hold_id'] !== $loaded) {
                $have = [];
                foreach ($items as $it) if (!empty($it['online_order_id'])) $have[(int)$it['online_order_id']] = true;
                $open = $pdo->prepare("SELECT order_id FROM restaurant_online_orders WHERE table_id = ? AND status IN ('accepted','ready')");
                $open->execute([$table_id]);
                $openIds = array_flip(array_map('intval', $open->fetchAll(PDO::FETCH_COLUMN)));
                foreach ((array)json_decode((string)$curRow['items_data'], true) as $it) {
                    $oid = (int)($it['online_order_id'] ?? 0);
                    if ($oid && isset($openIds[$oid]) && !isset($have[$oid])) {
                        $items[] = $it;
                        $subtotal += (float)($it['price'] ?? 0) * (float)($it['quantity'] ?? 0);
                    }
                }
            }
        }

        // restaurant_order_flow_plan.md — a dish the kitchen already has that is no longer on the saved
        // order is taken off the kitchen. Cooked food needs the cashier + a reason in the team flow
        // (RofException → error_code cancel_needs_reason / cancel_needs_approval / cancel_not_allowed).
        rofReconcile($pdo, ['warehouse_id' => $warehouse_id, 'table_id' => $table_id, 'items' => $items,
                            'user_id' => (int)$user_id, 'reason' => trim((string)($body['cancel_reason'] ?? '')), 'source' => 'removed']);

        $pdo->prepare("UPDATE pos_held_sales SET status = 'cancelled' WHERE table_id = ? AND status = 'held'")
            ->execute([$table_id]);
    }

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
    // Capture now: logActivity() below inserts into activity_log and would change lastInsertId().
    $hold_id = (int)$pdo->lastInsertId();

    // Phase 30 — opening/loading a table's order occupies it immediately, not
    // only once the bill is finally closed, so a floor-plan view reflects
    // reality the moment a server starts taking an order.
    if ($table_id) {
        $pdo->prepare("UPDATE restaurant_tables SET status = 'occupied', updated_at = NOW() WHERE table_id = ?")
            ->execute([$table_id]);
    }
    if ($pdo->inTransaction()) $pdo->commit();

    require_once __DIR__ . '/../../helpers.php';
    $username = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $user_id, 'Hold POS Sale', "$username held a POS sale (Ref: $hold_reference, Total: " . number_format($total, 2) . ")");

    echo json_encode([
        'success' => true,
        'message' => t('Sale held successfully'),
        'hold_id' => $hold_id,
        'reference' => $hold_reference
    ]);
    
} catch (RofException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode($e->toArray());
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
