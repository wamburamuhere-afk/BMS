<?php
// File: api/account/approve_purchase_return.php
// Workflow transition: reviewed -> approved. Stamps approved_by / approved_at.
//
// Side-effect on approval: stock is deducted from the warehouse for every
// purchase_return_item, the same logic that previously lived in
// api/update_purchase_return_status.php. We inline the helper here (under a
// different name) instead of require-ing that file — that file has top-level
// POST handling we'd accidentally re-execute. function_exists() guards
// against redefinition if both ever end up loaded in the same request.
//
// After deduction we set stock_updated=1 so the existing reversal logic in
// the legacy status endpoint keeps working if the return is later
// rejected/cancelled.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

// ─── Helper: deduct or add stock for every purchase_return_item ───────────
// Defined BEFORE the try block so PHP can resolve the call inside the try.
// Conditional function declarations are not hoisted in PHP, so it must be
// declared before it is called.
if (!function_exists('approve_pr_adjust_stock')) {
    function approve_pr_adjust_stock(PDO $pdo, int $returnId, int $warehouseId, string $action = 'deduct'): void
    {
        if (!$warehouseId) {
            throw new Exception("Warehouse not specified for this return. Cannot adjust stock.");
        }

        $itemStmt = $pdo->prepare("SELECT product_id, quantity FROM purchase_return_items WHERE purchase_return_id = ?");
        $itemStmt->execute([$returnId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $operator = ($action === 'deduct') ? '-' : '+';
        $calc_qty = ($action === 'deduct') ? -1 : 1;

        foreach ($items as $item) {
            $product_id = intval($item['product_id']);
            $qty = floatval($item['quantity']);
            if ($product_id <= 0 || $qty <= 0) continue;

            $stmtCheck = $pdo->prepare("SELECT stock_id, stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
            $stmtCheck->execute([$product_id, $warehouseId]);
            $stockRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($action === 'deduct') {
                $currentVal = $stockRow ? floatval($stockRow['stock_quantity']) : 0;
                if ($currentVal < $qty) {
                    $stmtPName = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
                    $stmtPName->execute([$product_id]);
                    $pName = $stmtPName->fetchColumn();
                    throw new Exception("Insufficient stock for '$pName' in this warehouse. Required: $qty, Available: $currentVal.");
                }
            }

            $stmtStock = $pdo->prepare("
                UPDATE products
                SET current_stock = current_stock $operator ?,
                    stock_quantity = stock_quantity $operator ?
                WHERE product_id = ?
            ");
            $stmtStock->execute([$qty, $qty, $product_id]);

            if ($stockRow) {
                $stmtUpdatePS = $pdo->prepare("
                    UPDATE product_stocks
                    SET stock_quantity = stock_quantity $operator ?,
                        last_updated = NOW()
                    WHERE stock_id = ?
                ");
                $stmtUpdatePS->execute([$qty, $stockRow['stock_id']]);
            } else {
                $final_qty = $qty * $calc_qty;
                $stmtInsertPS = $pdo->prepare("
                    INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, last_updated)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmtInsertPS->execute([$product_id, $warehouseId, $final_qty]);
            }
        }
    }
}

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!canApprove('purchase_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve purchase returns']);
    exit;
}

try {
    global $pdo;

    $id = intval($_POST['return_id'] ?? $_POST['id'] ?? 0);
    if (!$id) {
        throw new Exception("Missing purchase return ID");
    }

    assertScopeForRecord('purchase_returns', 'purchase_return_id', $id);

    $stmt = $pdo->prepare("
        SELECT return_number, status, warehouse_id, stock_updated
        FROM purchase_returns WHERE purchase_return_id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception("Purchase return not found");
    }
    if ($row['status'] !== 'reviewed') {
        throw new Exception("Only a reviewed purchase return can be approved (current status: " . ucfirst($row['status']) . ").");
    }

    $actor = workflowActorSnapshot();

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE purchase_returns
        SET status = 'approved', approved_by = ?, approved_at = NOW(),
            updated_by = ?, updated_at = NOW()
        WHERE purchase_return_id = ?
    ")->execute([$_SESSION['user_id'], $_SESSION['user_id'], $id]);

    // Stock side-effect: deduct, then mark stock_updated=1
    if ((int)$row['stock_updated'] === 0) {
        approve_pr_adjust_stock($pdo, $id, (int)$row['warehouse_id'], 'deduct');
        $pdo->prepare("UPDATE purchase_returns SET stock_updated = 1 WHERE purchase_return_id = ?")
            ->execute([$id]);
    }

    // money.md OUT-8 — post the GL contra of the GRN: Dr AP / Cr Inventory.
    // Best-effort (never throws), idempotent; joins this transaction.
    require_once __DIR__ . '/../../core/purchase_posting.php';
    postPurchaseReturn($pdo, $id, (int)$_SESSION['user_id']);

    $sigResult = workflowCaptureSignature($pdo, 'purchase_return', $id, 'approved',
        $_SESSION['user_id'], $actor['name'], $actor['role']);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], 'Approve Purchase Return',
        "{$actor['name']} approved Purchase Return #{$row['return_number']}");

    $response = ['success' => true, 'message' => 'Purchase Return approved.'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
