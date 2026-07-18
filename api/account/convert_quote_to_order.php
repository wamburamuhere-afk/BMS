<?php
// File: api/account/convert_quote_to_order.php
// Converts an APPROVED quotation (from the `quotations` table) into a real
// Sales Order (a new row in `sales_orders`). The quotation is kept as a
// historical record and tagged with the resulting sales order id so it can
// never be converted twice.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/code_generator.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canCreate('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create sales orders from quotations']);
    exit;
}

try {
    global $pdo;

    $id = intval($_POST['id'] ?? $_POST['quotation_id'] ?? 0);
    if (!$id) {
        throw new Exception("Missing quotation ID");
    }

    // Phase C — block conversions against quotations on projects not in user scope
    assertScopeForRecord('quotations', 'sales_order_id', $id);

    // Fetch the quotation header.
    $stmt = $pdo->prepare("SELECT * FROM quotations WHERE sales_order_id = ?");
    $stmt->execute([$id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quote) {
        throw new Exception("Quotation not found");
    }
    if (($quote['status'] ?? '') !== 'approved') {
        throw new Exception("Only an approved quotation can be converted to a sales order.");
    }
    if (!empty($quote['converted_to_so_id'])) {
        throw new Exception("This quotation has already been converted to a sales order.");
    }

    // Fetch the quotation items.
    $itemStmt = $pdo->prepare("SELECT * FROM quotation_items WHERE order_id = ?");
    $itemStmt->execute([$id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();

    // Build the new sales order number (company-prefixed sequential, BFS-SO-0001).
    $so_number = nextCode($pdo, 'SO');

    // Copy the header into sales_orders. Only columns that exist in BOTH tables
    // are carried over — quotation-only columns (quote_valid_until, reviewed_by,
    // reviewed_at, approved_at, converted_to_so_id, ...) are dropped automatically.
    $soCols = array_flip($pdo->query("SHOW COLUMNS FROM sales_orders")->fetchAll(PDO::FETCH_COLUMN));
    $header = [];
    foreach ($quote as $col => $val) {
        if (isset($soCols[$col])) {
            $header[$col] = $val;
        }
    }
    unset($header['sales_order_id'], $header['created_at'], $header['updated_at']);
    $header['order_number'] = $so_number;
    $header['is_quote']     = 0;
    $header['status']       = 'pending';
    $header['created_by']   = $_SESSION['user_id'];
    $header['updated_by']   = $_SESSION['user_id'];
    if (array_key_exists('approved_by', $header)) $header['approved_by'] = null;
    if (array_key_exists('reviewed_by', $header)) $header['reviewed_by'] = null;

    $cols   = array_keys($header);
    $colSql = '`' . implode('`,`', $cols) . '`';
    $ph     = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO sales_orders ($colSql) VALUES ($ph)")
        ->execute(array_values($header));
    $new_so_id = $pdo->lastInsertId();

    // e-signature capture (Created By) — this is a distinct SO-creation path
    // from save_sales_order.php (which already captures it), so it needs its
    // own call; without it, the print page's "Created By" column shows a name
    // (from created_by) but no signature stamp, unlike Reviewed/Approved.
    $wfActor = workflowActorSnapshot();
    workflowCaptureSignature(
        $pdo, 'sales_order', (int)$new_so_id, 'created',
        (int)$_SESSION['user_id'], $wfActor['name'], $wfActor['role']
    );

    // Copy the items into sales_order_items.
    foreach ($items as $item) {
        unset($item['order_item_id'], $item['created_at']);
        $item['order_id'] = $new_so_id;

        $icols   = array_keys($item);
        $icolSql = '`' . implode('`,`', $icols) . '`';
        $iph     = implode(',', array_fill(0, count($icols), '?'));
        $pdo->prepare("INSERT INTO sales_order_items ($icolSql) VALUES ($iph)")
            ->execute(array_values($item));
    }

    // Tag the quotation with the resulting sales order — prevents a second
    // conversion. The quotation stays 'approved' as a historical record.
    $pdo->prepare("UPDATE quotations SET converted_to_so_id = ?, updated_at = NOW(), updated_by = ? WHERE sales_order_id = ?")
        ->execute([$new_so_id, $_SESSION['user_id'], $id]);

    $pdo->commit();

    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Convert Quotation',
        "$user_name converted Quotation #{$quote['order_number']} to Sales Order #$so_number");

    echo json_encode(['success' => true, 'message' => 'Converted successfully', 'sales_order_id' => $new_so_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
